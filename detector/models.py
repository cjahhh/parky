"""
models.py — Plate detection via Roboflow Hosted API only (no EasyOCR/PyTorch).
"""
from __future__ import annotations
import os
import re
import base64
from typing import Any, Dict, List, Optional, Tuple

import cv2
import numpy as np
import requests

from postprocess import is_plate_valid


def _normalize_plate(text: str) -> str:
    return re.sub(r"[^A-Z0-9]", "", (text or "").upper()).strip()


def _plate_score(text: str) -> float:
    t = _normalize_plate(text)
    if not t:
        return 0.0
    if re.match(r"^[A-Z]{3}\d{3,5}$", t): return 5.0
    if re.match(r"^[A-Z]{2}\d{4,5}$",  t): return 4.5
    if re.match(r"^\d{3}[A-Z]{3}$",    t): return 4.5
    if re.match(r"^[A-Z]\d{5,6}$",     t): return 4.0
    if 5 <= len(t) <= 9 and re.search(r"\d", t) and re.search(r"[A-Z]", t):
        return 2.0
    return 0.5 if len(t) >= 4 else 0.0


def _detect_plate_roboflow(frame_bgr: np.ndarray) -> Tuple[Optional[Dict], float, str]:
    """
    Send frame to Roboflow. Returns (box, confidence, plate_text).
    Uses the OCR model which returns plate text directly.
    """
    api_key = os.getenv("ROBOFLOW_API_KEY", "").strip()
    if not api_key:
        print("[Roboflow] WARNING: ROBOFLOW_API_KEY not set")
        return None, 0.0, ""

    conf_gate = int(float(os.getenv("PARKY_ROBOFLOW_CONFIDENCE", "25")))
    overlap   = int(float(os.getenv("PARKY_ROBOFLOW_OVERLAP", "20")))
    jpeg_q    = int(float(os.getenv("PARKY_ROBOFLOW_JPEG_QUALITY", "88")))

    _, buffer = cv2.imencode(
        ".jpg", frame_bgr,
        [cv2.IMWRITE_JPEG_QUALITY, max(60, min(98, jpeg_q))]
    )
    img_b64 = base64.b64encode(buffer).decode("utf-8")

    url = (
        "https://detect.roboflow.com/license-plate-recognition-rxg4e/4"
        f"?api_key={api_key}&confidence={conf_gate}&overlap={overlap}"
    )

    try:
        resp = requests.post(
            url,
            data=img_b64,
            headers={"Content-Type": "application/x-www-form-urlencoded"},
            timeout=float(os.getenv("PARKY_ROBOFLOW_TIMEOUT_SEC", "12")),
        )
        resp.raise_for_status()
        data = resp.json()
        predictions = data.get("predictions", [])
    except Exception as exc:
        print(f"[Roboflow] Request failed: {exc}")
        return None, 0.0, ""

    if not predictions:
        print("[Roboflow] No plate detected.")
        return None, 0.0, ""

    best = max(predictions, key=lambda p: p.get("confidence", 0))
    conf = float(best.get("confidence", 0))

    # Roboflow sometimes returns the class name as the plate text
    plate_text = _normalize_plate(best.get("class", ""))
    print(f"[Roboflow] Detected — conf={conf:.2f} class='{plate_text}'")
    return best, conf, plate_text


def _maybe_upscale(frame_bgr: np.ndarray) -> np.ndarray:
    h, w = frame_bgr.shape[:2]
    target = int(float(os.getenv("PARKY_DETECT_MIN_SIDE", "640")))
    min_side = min(h, w)
    if min_side >= target:
        return frame_bgr
    scale = min(target / float(min_side), 2.0)
    return cv2.resize(frame_bgr, (int(w * scale), int(h * scale)),
                      interpolation=cv2.INTER_CUBIC)


def _crop_plate(frame_bgr: np.ndarray, box: Optional[Dict]) -> np.ndarray:
    if not box:
        return frame_bgr
    h, w = frame_bgr.shape[:2]
    cx, cy = int(box["x"]), int(box["y"])
    bw, bh = int(box["width"]), int(box["height"])
    pad = max(16, int(min(bw, bh) * 0.12))
    x1 = max(0, cx - bw // 2 - pad)
    y1 = max(0, cy - bh // 2 - pad)
    x2 = min(w, cx + bw // 2 + pad)
    y2 = min(h, cy + bh // 2 + pad)
    crop = frame_bgr[y1:y2, x1:x2]
    return crop if crop.size > 0 else frame_bgr


def _ocr_with_pytesseract(crop_bgr: np.ndarray) -> Tuple[str, float]:
    """Lightweight OCR fallback using pytesseract (no PyTorch needed)."""
    try:
        import pytesseract
        gray = cv2.cvtColor(crop_bgr, cv2.COLOR_BGR2GRAY)
        clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
        gray = clahe.apply(gray)
        gray = cv2.resize(gray, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
        config = r'--oem 3 --psm 8 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
        text = pytesseract.image_to_string(gray, config=config).strip()
        text = _normalize_plate(text)
        conf = 0.75 if is_plate_valid(text) else 0.3
        print(f"[Tesseract] Result: '{text}' conf={conf}")
        return text, conf
    except Exception as exc:
        print(f"[Tesseract] Failed: {exc}")
        return "", 0.0


class DetectorPipeline:
    def __init__(self) -> None:
        self.hardware_mode = "cpu"

    def model_status(self) -> Dict[str, Any]:
        return {
            "pipeline":         "roboflow_tesseract",
            "roboflow_key_set": bool(os.getenv("ROBOFLOW_API_KEY", "").strip()),
            "roboflow_model":   "license-plate-recognition-rxg4e/4",
            "hardware_mode":    self.hardware_mode,
        }

    def infer(self, frame_bgr: np.ndarray) -> Dict[str, Any]:
        work = _maybe_upscale(frame_bgr)
        best_box, det_conf, rf_plate = _detect_plate_roboflow(work)

        # Retry with slight upscale if missed
        if best_box is None:
            scale = float(os.getenv("PARKY_ROBOFLOW_RETRY_SCALE", "1.35"))
            h, w = frame_bgr.shape[:2]
            scaled = cv2.resize(frame_bgr,
                                (int(w * scale), int(h * scale)),
                                interpolation=cv2.INTER_CUBIC)
            best_box, det_conf, rf_plate = _detect_plate_roboflow(scaled)
            if best_box is not None:
                work = scaled

        # Use Roboflow class name if it looks like a plate
        plate_text, plate_conf = "", 0.0
        if rf_plate and _plate_score(rf_plate) >= 2.0:
            plate_text, plate_conf = rf_plate, det_conf
            print(f"[Pipeline] Using Roboflow class as plate: '{plate_text}'")
        else:
            # Fall back to Tesseract OCR on the cropped plate region
            crop = _crop_plate(work, best_box)
            plate_text, plate_conf = _ocr_with_pytesseract(crop)

        plate_text = _normalize_plate(plate_text)

        return {
            "rear_confidence":          max(0.5, det_conf),
            "vehicle_type":             "Unknown",
            "vehicle_type_confidence":  0.0,
            "vehicle_color":            "Unknown",
            "vehicle_color_confidence": 0.0,
            "plate":                    plate_text,
            "plate_confidence":         plate_conf,
            "plate_bbox":               [],
            "detections":               1 if best_box else 0,
        }
