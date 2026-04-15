"""
models.py — Plate detection via Roboflow Hosted API + EasyOCR for text reading.
Vehicle type and color detection removed.
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


# ── EasyOCR singleton ─────────────────────────────────────────────────────────
_easyocr_reader = None




def get_easyocr_reader():
    global _easyocr_reader
    if _easyocr_reader is None:
        import easyocr
        use_gpu = os.getenv("PARKY_EASYOCR_GPU", "").strip() in ("1", "true", "yes")
        _easyocr_reader = easyocr.Reader(["en"], gpu=use_gpu, verbose=False)
    return _easyocr_reader




# ── Plate text helpers ────────────────────────────────────────────────────────
def _normalize_plate(text: str) -> str:
    """Strip everything except uppercase letters and digits."""
    return re.sub(r"[^A-Z0-9]", "", (text or "").upper()).strip()




def _plate_score(text: str) -> float:
    """Score how much a string looks like a real PH plate. Higher = better."""
    t = _normalize_plate(text)
    if not t:
        return 0.0
    if re.match(r"^[A-Z]{3}\d{3,5}$", t): return 5.0   # ABC 123  (most common)
    if re.match(r"^[A-Z]{2}\d{4,5}$",  t): return 4.5   # AB 1234
    if re.match(r"^\d{3}[A-Z]{3}$",    t): return 4.5   # 123 ABC  (older)
    if re.match(r"^[A-Z]\d{5,6}$",     t): return 4.0   # A 12345
    if 5 <= len(t) <= 9 and re.search(r"\d", t) and re.search(r"[A-Z]", t):
        return 2.0
    return 0.5 if len(t) >= 4 else 0.0




# ── Roboflow detection ────────────────────────────────────────────────────────
def _detect_plate_roboflow(frame_bgr: np.ndarray) -> Tuple[Optional[Dict], float]:
    """
    Send frame to Roboflow Hosted API (license-plate-recognition-rxg4e v4).
    Returns (best_prediction_dict, confidence) or (None, 0.0) if none found.


    PARKY_ROBOFLOW_CONFIDENCE / PARKY_ROBOFLOW_OVERLAP tune sensitivity vs noise.
    """
    api_key = os.getenv("ROBOFLOW_API_KEY", "").strip()
    if not api_key:
        print("[Roboflow] WARNING: ROBOFLOW_API_KEY is not set in .env")
        return None, 0.0


    conf_gate = int(float(os.getenv("PARKY_ROBOFLOW_CONFIDENCE", "25")))
    overlap = int(float(os.getenv("PARKY_ROBOFLOW_OVERLAP", "20")))
    jpeg_q = int(float(os.getenv("PARKY_ROBOFLOW_JPEG_QUALITY", "88")))


    _, buffer = cv2.imencode(".jpg", frame_bgr, [cv2.IMWRITE_JPEG_QUALITY, max(60, min(98, jpeg_q))])
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
        predictions = resp.json().get("predictions", [])
    except requests.exceptions.Timeout:
        print("[Roboflow] Request timed out.")
        return None, 0.0
    except requests.exceptions.RequestException as exc:
        print(f"[Roboflow] Request failed: {exc}")
        return None, 0.0
    except Exception as exc:
        print(f"[Roboflow] Unexpected error: {exc}")
        return None, 0.0


    if not predictions:
        print("[Roboflow] No plate detected in frame.")
        return None, 0.0


    best = max(predictions, key=lambda p: p.get("confidence", 0))
    conf = float(best.get("confidence", 0))
    print(f"[Roboflow] Plate detected — confidence: {conf:.2f}, box: "
          f"x={best.get('x')}, y={best.get('y')}, "
          f"w={best.get('width')}, h={best.get('height')}")
    return best, conf




def _maybe_upscale_for_detection(frame_bgr: np.ndarray) -> np.ndarray:
    """Upscale small camera frames so Roboflow sees plate-sized detail."""
    h, w = frame_bgr.shape[:2]
    min_side = min(h, w)
    target = int(float(os.getenv("PARKY_DETECT_MIN_SIDE", "640")))
    if min_side >= target:
        return frame_bgr
    scale = target / float(min_side)
    scale = min(scale, float(os.getenv("PARKY_DETECT_MAX_SCALE", "2.0")))
    nw, nh = int(w * scale), int(h * scale)
    return cv2.resize(frame_bgr, (nw, nh), interpolation=cv2.INTER_CUBIC)




def _extract_crop(frame_bgr: np.ndarray, box: Optional[Dict]) -> np.ndarray:
    h, w = frame_bgr.shape[:2]
    if not box:
        return frame_bgr
    cx, cy = int(box["x"]), int(box["y"])
    bw, bh = int(box["width"]), int(box["height"])
    pad = max(16, int(round(min(bw, bh) * 0.12)))
    x1 = max(0, cx - bw // 2 - pad)
    y1 = max(0, cy - bh // 2 - pad)
    x2 = min(w, cx + bw // 2 + pad)
    y2 = min(h, cy + bh // 2 + pad)
    crop = frame_bgr[y1:y2, x1:x2]
    print(f"[OCR] Cropping plate region: ({x1},{y1}) → ({x2},{y2})")
    if crop is None or crop.size == 0:
        return frame_bgr
    return crop




def _ocr_preprocess_variants(crop_bgr: np.ndarray) -> List[np.ndarray]:
    """
    Natural photos often read better with CLAHE than harsh adaptive threshold.
    We try several views and let scoring pick the winner (still one EasyOCR pass per view).
    """
    ch, cw = crop_bgr.shape[:2]
    min_w = int(float(os.getenv("PARKY_OCR_MIN_WIDTH", "260")))
    if cw < min_w:
        s = min_w / max(cw, 1)
        base = cv2.resize(crop_bgr, None, fx=s, fy=s, interpolation=cv2.INTER_CUBIC)
    else:
        base = crop_bgr


    gray = cv2.cvtColor(base, cv2.COLOR_BGR2GRAY)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    clahe_gray = clahe.apply(gray)
    clahe_bgr = cv2.cvtColor(clahe_gray, cv2.COLOR_GRAY2BGR)


    out: List[np.ndarray] = [base, clahe_bgr]
    if os.getenv("PARKY_OCR_THRESH_PATH", "").strip() in ("1", "true", "yes"):
        thresh = cv2.adaptiveThreshold(
            gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 31, 10
        )
        kernel = np.array([[0, -1, 0], [-1, 5, -1], [0, -1, 0]], dtype=np.float32)
        sharpened = cv2.filter2D(thresh, -1, kernel)
        out.append(cv2.cvtColor(sharpened, cv2.COLOR_GRAY2BGR))
    return out




def _readtext_candidates(reader, img_bgr: np.ndarray) -> Tuple[str, float]:
    allowlist = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"
    try:
        results = reader.readtext(
            img_bgr,
            allowlist=allowlist,
            paragraph=False,
            detail=1,
            decoder="greedy",
            width_ths=0.95,
            height_ths=0.95,
            text_threshold=float(os.getenv("PARKY_EASYOCR_TEXT_THRESHOLD", "0.45")),
            link_threshold=float(os.getenv("PARKY_EASYOCR_LINK_THRESHOLD", "0.35")),
        )
    except Exception as exc:
        print(f"[OCR] EasyOCR failed: {exc}")
        return "", 0.0


    best_text, best_rank, best_conf = "", -1.0, 0.0
    for item in results:
        if len(item) < 3:
            continue
        text = _normalize_plate(item[1])
        conf = float(item[2])
        rank = _plate_score(text) * 1.35 + conf
        print(f"[OCR] Candidate: '{text}' conf={conf:.2f} rank={rank:.2f}")
        if rank > best_rank:
            best_text, best_rank, best_conf = text, rank, conf


    print(f"[OCR] Best result: '{best_text}' (conf={best_conf:.2f})")
    return best_text, best_conf




def _ocr_best_from_crop(reader, crop_bgr: np.ndarray) -> Tuple[str, float]:
    best_text, best_rank, best_conf = "", -1.0, 0.0
    for proc in _ocr_preprocess_variants(crop_bgr):
        t, c = _readtext_candidates(reader, proc)
        rank = _plate_score(t) * 1.35 + float(c)
        if rank > best_rank:
            best_text, best_rank, best_conf = t, rank, float(c)
    return best_text, best_conf




def _ocr_crop(
    reader,
    frame_bgr: np.ndarray,
    box: Optional[Dict],
) -> Tuple[str, float]:
    """
    Crop plate (or full frame), run EasyOCR on several preprocessings, return best plate.
    """
    if box:
        crop = _extract_crop(frame_bgr, box)
    else:
        print("[OCR] No box — running OCR on full frame (fallback).")
        crop = frame_bgr
    return _ocr_best_from_crop(reader, crop)




# ── Pipeline ──────────────────────────────────────────────────────────────────
class DetectorPipeline:
    """
    Roboflow (plate location) + EasyOCR (plate text) pipeline.
    Vehicle type and color are no longer detected.
    """


    def __init__(self) -> None:
        self.hardware_mode = "cpu"
        try:
            import torch
            self.hardware_mode = "gpu" if torch.cuda.is_available() else "cpu"
        except Exception:
            pass


    def model_status(self) -> Dict[str, Any]:
        return {
            "pipeline":           "roboflow_easyocr",
            "roboflow_key_set":   bool(os.getenv("ROBOFLOW_API_KEY", "").strip()),
            "roboflow_model":     "license-plate-recognition-rxg4e/4",
            "roboflow_conf_gate": os.getenv("PARKY_ROBOFLOW_CONFIDENCE", "25"),
            "roboflow_overlap":   os.getenv("PARKY_ROBOFLOW_OVERLAP", "20"),
            "ocr_model":          True,
            "hardware_mode":      self.hardware_mode,
        }


    def infer(self, frame_bgr: np.ndarray) -> Dict[str, Any]:
        reader = get_easyocr_reader()


        work = _maybe_upscale_for_detection(frame_bgr)
        best_box, det_conf = _detect_plate_roboflow(work)


        # Second Roboflow pass: moderate upscale from original (helps when first pass missed)
        if best_box is None:
            retry_scale = float(os.getenv("PARKY_ROBOFLOW_RETRY_SCALE", "1.35"))
            h, w = frame_bgr.shape[:2]
            scaled = cv2.resize(frame_bgr, (int(w * retry_scale), int(h * retry_scale)), interpolation=cv2.INTER_CUBIC)
            if scaled.shape[0] != work.shape[0] or scaled.shape[1] != work.shape[1]:
                best2, det2 = _detect_plate_roboflow(scaled)
                if best2 is not None:
                    best_box, det_conf, work = best2, max(det_conf, det2), scaled


        plate_text, plate_conf = _ocr_crop(reader, work, best_box)
        plate_text = _normalize_plate(plate_text)


        # Wrong crop or threshold hurt OCR — compare full-frame read when we had a box
        if best_box is not None and plate_text and not is_plate_valid(plate_text):
            alt_t, alt_c = _ocr_crop(reader, work, None)
            alt_t = _normalize_plate(alt_t)
            if is_plate_valid(alt_t) and _plate_score(alt_t) > _plate_score(plate_text):
                plate_text, plate_conf = alt_t, alt_c
                print("[OCR] Using full-frame fallback (crop OCR failed format check).")
        elif best_box is not None and not plate_text:
            alt_t, alt_c = _ocr_crop(reader, work, None)
            alt_t = _normalize_plate(alt_t)
            if _plate_score(alt_t) > _plate_score(plate_text):
                plate_text, plate_conf = alt_t, alt_c
                print("[OCR] Using full-frame fallback (empty crop OCR).")


        return {
            "rear_confidence":            max(0.5, det_conf),
            "vehicle_type":               "Unknown",
            "vehicle_type_confidence":    0.0,
            "vehicle_color":              "Unknown",
            "vehicle_color_confidence":   0.0,
            "plate":                      plate_text,
            "plate_confidence":           plate_conf,
            "plate_bbox":                 [],
            "detections":                 1 if best_box else 0,
        }

