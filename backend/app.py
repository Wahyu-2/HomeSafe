import os
import time
import base64
import logging
import cv2
import numpy as np
from fastapi import FastAPI, WebSocket, WebSocketDisconnect, HTTPException
from fastapi.middleware.cors import CORSMiddleware
import uvicorn
from recognizer import FaceRecognizerEngine, MATCH_THRESHOLD, REJECTION_THRESHOLD, REGISTRATION_FRAMES_REQUIRED

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(name)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

app = FastAPI(title="Smart Lock Face Recognition API")

# Allow CORS from Laravel frontend
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://127.0.0.1:8000", "http://localhost:8000"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

engine = FaceRecognizerEngine()


def decode_frame(img_data_b64: str):
    _, encoded = img_data_b64.split(",", 1) if "," in img_data_b64 else ("", img_data_b64)
    img_bytes = base64.b64decode(encoded)
    nparr = np.frombuffer(img_bytes, np.uint8)
    return cv2.imdecode(nparr, cv2.IMREAD_COLOR)


# ── Recognition WebSocket ──────────────────────────────────────────────────────
@app.websocket("/ws")
async def websocket_recognition(websocket: WebSocket):
    await websocket.accept()
    try:
        while True:
            data = await websocket.receive_json()
            if data.get("type") != "frame":
                continue

            frame = decode_frame(data.get("image", ""))
            if frame is None:
                continue

            start = time.perf_counter()
            face_info, bbox, face_count, quality = engine.extract_face_landmarks_and_box(frame)
            elapsed_ms = (time.perf_counter() - start) * 1000.0

            face_detected = face_info is not None
            matched = False
            match_name = "Unknown"
            similarity = 0.0
            match_percentage = 0.0

            if face_detected:
                embedding = engine.get_embedding(frame, face_info)
                if embedding is not None:
                    match_name, similarity, match_percentage = engine.match_face(embedding)
                    matched = similarity >= MATCH_THRESHOLD

            await websocket.send_json({
                "type":             "result",
                "face_detected":    face_detected,
                "face_count":       face_count,
                "quality_issue":    quality,        # None | 'too_small' | 'too_close' | 'multiple_faces'
                "bbox":             bbox,
                "matched":          matched,
                "name":             match_name,
                "similarity":       float(similarity),
                "percentage":       float(match_percentage),
                "process_time_ms":  round(elapsed_ms, 2),
                "unlocked":         matched
            })

    except WebSocketDisconnect:
        logger.info("Recognition WS disconnected")
    except Exception as e:
        logger.error(f"Recognition WS error: {e}", exc_info=True)


# ── Enrollment WebSocket ───────────────────────────────────────────────────────
@app.websocket("/ws/enroll")
async def websocket_enrollment(websocket: WebSocket):
    await websocket.accept()

    reg_name       = ""
    reg_embeddings = []
    is_scanning    = False

    try:
        while True:
            data = await websocket.receive_json()
            msg_type = data.get("type")

            if msg_type == "register_start":
                reg_name = data.get("name", "").strip()
                if not reg_name:
                    await websocket.send_json({"type": "register_error", "message": "Name cannot be empty."})
                    continue
                is_scanning    = True
                reg_embeddings = []
                await websocket.send_json({"type": "status", "message": f"Scanning '{reg_name}'... look straight at the camera."})

            elif msg_type == "register_cancel":
                is_scanning    = False
                reg_embeddings = []
                await websocket.send_json({"type": "status", "message": "Registration cancelled."})

            elif msg_type == "frame":
                frame = decode_frame(data.get("image", ""))
                if frame is None:
                    continue

                face_info, bbox, face_count, quality = engine.extract_face_landmarks_and_box(frame)

                # Always send preview so client can draw box
                preview = {"type": "preview", "bbox": bbox, "face_count": face_count, "quality_issue": quality}
                await websocket.send_json(preview)

                if not is_scanning:
                    continue

                # Guard: must have exactly one clean face
                if quality == "multiple_faces":
                    await websocket.send_json({"type": "warn", "message": "Multiple faces detected — only one person please."})
                    continue
                if quality == "too_small":
                    await websocket.send_json({"type": "warn", "message": "Move closer to the camera."})
                    continue
                if quality == "too_close":
                    await websocket.send_json({"type": "warn", "message": "Too close — move back a bit."})
                    continue
                if face_info is None:
                    await websocket.send_json({"type": "warn", "message": "No face detected — position your face in the guide box."})
                    continue

                embedding = engine.get_embedding(frame, face_info)
                if embedding is None:
                    continue

                reg_embeddings.append(embedding)
                progress = int(len(reg_embeddings) * 100 / REGISTRATION_FRAMES_REQUIRED)
                await websocket.send_json({
                    "type":     "register_progress",
                    "progress": progress,
                    "count":    len(reg_embeddings),
                    "total":    REGISTRATION_FRAMES_REQUIRED,
                    "message":  f"Capturing biometric data... {progress}%"
                })

                if len(reg_embeddings) >= REGISTRATION_FRAMES_REQUIRED:
                    mean_emb = np.mean(reg_embeddings, axis=0).reshape(1, -1)
                    success, reason = engine.register_user(reg_name, mean_emb)
                    is_scanning    = False
                    reg_embeddings = []

                    if success:
                        await websocket.send_json({"type": "register_success", "name": reg_name})
                    elif reason.startswith("duplicate:"):
                        dup_name = reason.split(":", 1)[1]
                        await websocket.send_json({
                            "type":    "register_error",
                            "message": f"Face is too similar to existing profile '{dup_name}'. Cannot register duplicate."
                        })
                    else:
                        await websocket.send_json({"type": "register_error", "message": f"Registration failed ({reason})."})

    except WebSocketDisconnect:
        logger.info("Enrollment WS disconnected")
    except Exception as e:
        logger.error(f"Enrollment WS error: {e}", exc_info=True)


# ── REST API ───────────────────────────────────────────────────────────────────
@app.get("/api/users")
async def get_users():
    return {"users": engine.get_users_list()}


@app.delete("/api/users/{name}")
async def delete_user(name: str):
    success = engine.delete_user(name)
    if success:
        return {"status": "success", "message": f"User '{name}' deleted."}
    raise HTTPException(status_code=404, detail=f"User '{name}' not found.")


if __name__ == "__main__":
    print("Starting Smart Lock API server at http://127.0.0.1:5000")
    uvicorn.run("app:app", host="127.0.0.1", port=5000, log_level="warning")
