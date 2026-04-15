"""
app.py — Parky Vehicle Detector API
Roboflow (plate detection) + EasyOCR (plate text reading)
v2.1.0 — EasyOCR warmup on startup to eliminate first-scan delay.
"""
import base64
import os
import threading
import time
from typing import Any, Dict, Optional


from dotenv import load_dotenv
load_dotenv()


import cv2
import numpy as np
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from models import DetectorPipeline
from postprocess import fuse_and_validate


class DetectRequest(BaseModel):
    image_b64: str = Field(..., description="Raw base64 payload or data URL")
    request_id: Optional[str] = Field(None, description="Optional request id")


class DetectResponse(BaseModel):
    success: bool
    plate: str = ""
    confidence: float = 0.0
    message: str = ""
    raw: Dict[str, Any] = Field(default_factory=dict)
    latency_ms: int = 0


APP_VERSION = "2.1.0"
STARTED_AT = time.time()
METRICS = {
    "requests_total": 0,
    "success_total": 0,
    "failure_total": 0,
    "avg_latency_ms": 0.0,
}


pipeline = DetectorPipeline()
_INFER_LOCK = threading.Lock()


app = FastAPI(
    title="Parky Plate Detector API (Roboflow + EasyOCR)",
    version=APP_VERSION,
)




def decode_image_payload(image_b64: str) -> np.ndarray:
    payload = image_b64.strip()
    if "," in payload and payload.lower().startswith("data:image"):
        payload = payload.split(",", 1)[1]
    try:
        img_bytes = base64.b64decode(payload, validate=True)
    except Exception as exc:
        raise ValueError(f"Invalid base64 payload: {exc}") from exc
    arr = np.frombuffer(img_bytes, dtype=np.uint8)
    frame = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if frame is None:
        raise ValueError("Decoded bytes are not a valid image")
    return frame




def update_metrics(latency_ms: int, success: bool) -> None:
    METRICS["requests_total"] += 1
    if success:
        METRICS["success_total"] += 1
    else:
        METRICS["failure_total"] += 1
    total = METRICS["requests_total"]
    prev_avg = METRICS["avg_latency_ms"]
    METRICS["avg_latency_ms"] = ((prev_avg * (total - 1)) + latency_ms) / total




def _warmup_easyocr() -> None:
    """
    Run a dummy inference on startup so EasyOCR's model is fully loaded
    into memory before the first real request arrives.
    Without this, the first scan takes 5-15 seconds while EasyOCR loads.
    With warmup, the first real scan is just as fast as subsequent ones.
    """
    print("[Parky] Warming up EasyOCR model (this takes 5-15 seconds)…")
    try:
        # Create a small white image with fake plate text
        dummy = np.ones((80, 240, 3), dtype=np.uint8) * 255
        cv2.putText(dummy, "ABC 123", (10, 55),
                    cv2.FONT_HERSHEY_SIMPLEX, 1.5, (0, 0, 0), 3)
        with _INFER_LOCK:
            pipeline.infer(dummy)
        print("[Parky] ✓ EasyOCR warmup complete — ready for fast scanning.")
    except Exception as e:
        print(f"[Parky] Warmup failed (non-fatal): {e}")




@app.on_event("startup")
async def startup_event():
    """Warm up EasyOCR in a background thread so startup is non-blocking."""
    t = threading.Thread(target=_warmup_easyocr, daemon=True)
    t.start()




@app.get("/health")
def health() -> Dict[str, Any]:
    return {
        "status": "ok",
        "version": APP_VERSION,
        "uptime_sec": int(time.time() - STARTED_AT),
        "hardware_mode": pipeline.hardware_mode,
        "models": pipeline.model_status(),
        "roboflow_key": "SET" if os.getenv("ROBOFLOW_API_KEY") else "MISSING ⚠️",
    }




@app.get("/metrics")
def metrics() -> Dict[str, Any]:
    return {
        **METRICS,
        "avg_latency_ms": round(METRICS["avg_latency_ms"], 2),
    }




@app.post("/detect", response_model=DetectResponse)
def detect(req: DetectRequest) -> DetectResponse:
    t0 = time.time()
    try:
        frame = decode_image_payload(req.image_b64)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc))


    try:
        with _INFER_LOCK:
            raw = pipeline.infer(frame)
            final = fuse_and_validate(raw)
    except Exception as exc:
        latency_ms = int((time.time() - t0) * 1000)
        update_metrics(latency_ms, False)
        return DetectResponse(
            success=False,
            message=f"Inference failed: {exc}",
            raw={},
            latency_ms=latency_ms,
        )


    latency_ms = int((time.time() - t0) * 1000)
    update_metrics(latency_ms, bool(final.get("success")))


    return DetectResponse(
        success=bool(final.get("success", False)),
        plate=str(final.get("plate", "")),
        confidence=float(final.get("confidence", 0.0)),
        message=str(final.get("message", "")),
        raw=raw,
        latency_ms=latency_ms,
    )




if __name__ == "__main__":
    import uvicorn
    host = os.getenv("PARKY_TF_SERVICE_HOST", "127.0.0.1")
    port = int(os.getenv("PARKY_TF_SERVICE_PORT", "8765"))
    print(f"[Parky] Starting detector service on http://{host}:{port}")
    print(f"[Parky] Roboflow API key: {'SET ✓' if os.getenv('ROBOFLOW_API_KEY') else 'MISSING ⚠️'}")
    uvicorn.run("app:app", host=host, port=port, reload=False)

