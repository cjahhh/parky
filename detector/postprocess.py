"""
postprocess.py — Validate and finalize the plate result from DetectorPipeline.
Vehicle type and color checks removed.
"""
import re
import os
from typing import Any, Dict




def normalize_plate(value: str) -> str:
    """Strip everything except uppercase letters and digits."""
    clean = re.sub(r"[^A-Z0-9]", "", (value or "").upper())
    return clean.strip()




def is_plate_valid(plate: str) -> bool:
    """
    Check if the OCR result matches a known Philippine plate format.
    Patterns:
      ABC 123   →  3 letters + 3-5 digits  (most common, current LTO format)
      AB 1234   →  2 letters + 4-5 digits
      123 ABC   →  3 digits + 3 letters    (older format)
      A 12345   →  1 letter  + 5-6 digits  (motorcycle / special)
    """
    patterns = [
        r"^[A-Z]{3}\d{3,5}$",   # ABC123 / ABC1234 / ABC12345
        r"^[A-Z]{2}\d{4,5}$",   # AB1234 / AB12345
        r"^\d{3}[A-Z]{3}$",     # 123ABC
        r"^[A-Z]\d{5,6}$",      # A12345 / A123456
    ]
    return any(re.match(p, plate) for p in patterns)




def fuse_and_validate(raw: Dict[str, Any]) -> Dict[str, Any]:
    """
    Receive the raw dict from DetectorPipeline.infer() and decide
    whether we have a valid plate detection.


    Key change from original:
      - rear_confidence check REMOVED (we no longer use YOLO vehicle detection)
      - plate_confidence threshold kept but lowered slightly (Roboflow crop
        gives EasyOCR a much cleaner input so conf tends to be higher anyway)
    """
    # EasyOCR conf scores are often modest on crops; keep default permissive so
    # a well-formed plate is accepted when Roboflow found a good box.
    min_plate_conf = float(os.getenv("PARKY_MIN_PLATE_CONF", "0.06"))


    plate      = normalize_plate(str(raw.get("plate", "")))
    plate_conf = float(raw.get("plate_confidence", 0.0) or 0.0)


    plate_ok = is_plate_valid(plate)


    # Success = plate matches a known format AND OCR confidence is acceptable
    success = plate_ok and plate_conf >= min_plate_conf


    if success:
        message = "OK"
    elif not plate:
        message = "No plate text detected"
    elif not plate_ok:
        message = f"'{plate}' does not match a known plate format"
    else:
        message = f"Plate confidence too low ({plate_conf:.2f} < {min_plate_conf})"


    print(f"[Postprocess] plate='{plate}' valid={plate_ok} "
          f"conf={plate_conf:.2f} success={success} → {message}")


    return {
        "success":       success,
        "plate":         plate if success else "",
        "vehicle_type":  "Unknown",
        "vehicle_color": "Unknown",
        "confidence":    round(plate_conf, 4),
        "message":       message,
    }

