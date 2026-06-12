import os
os.environ['TF_ENABLE_ONEDNN_OPTS'] = '0'
import json
import re
import logging
import cv2
import numpy as np
import mediapipe as mp
from mediapipe.tasks import python
from mediapipe.tasks.python import vision
from threading import Lock

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(name)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# Thresholds
MATCH_THRESHOLD          = 0.40   # min cosine untuk MATCH
REJECTION_THRESHOLD      = 0.30   # di bawah ini pasti UNKNOWN
DUPLICATE_REG_THRESHOLD  = 0.55   # tolak registrasi jika terlalu mirip user lain
REGISTRATION_FRAMES_REQUIRED = 10
EMBEDDING_DIM            = 128
MIN_FACE_SIZE            = 80     # px minimum lebar bbox
MAX_FACE_SIZE_RATIO      = 0.85   # terlalu dekat ke kamera
MIN_DETECTION_CONFIDENCE = 0.70

MIN_NAME_LENGTH = 2
MAX_NAME_LENGTH = 50
VALID_NAME_PATTERN = re.compile(r'^[a-zA-Z0-9_\-\s]+$')


class FaceRecognizerEngine:
    def __init__(self, model_path="models/face_recognition_sface_2021dec.onnx", db_path="database.json"):
        self.db_path    = db_path
        self.model_path = model_path
        self.db_lock    = Lock()

        tflite_path = "models/blaze_face_short_range.tflite"
        if not os.path.exists(tflite_path):
            raise FileNotFoundError(f"MediaPipe model not found: {tflite_path}. Run download_model.py.")

        base_options = python.BaseOptions(model_asset_path=tflite_path)
        options = vision.FaceDetectorOptions(
            base_options=base_options,
            min_detection_confidence=MIN_DETECTION_CONFIDENCE
        )
        self.face_detector = vision.FaceDetector.create_from_options(options)

        if not os.path.exists(model_path):
            raise FileNotFoundError(f"SFace model not found: {model_path}. Run download_model.py.")

        self.face_recognizer = cv2.FaceRecognizerSF.create(
            model=self.model_path, config="",
            backend_id=cv2.dnn.DNN_BACKEND_OPENCV,
            target_id=cv2.dnn.DNN_TARGET_CPU
        )
        self.database = self.load_database()

    # ── Database ───────────────────────────────────────────────────────────────

    def load_database(self):
        if not os.path.exists(self.db_path):
            return {}
        try:
            with open(self.db_path, 'r') as f:
                db = json.load(f)
            for name in db:
                db[name] = np.array(db[name], dtype=np.float32).reshape(1, EMBEDDING_DIM)
            logger.info(f"Loaded {len(db)} users.")
            return db
        except Exception as e:
            logger.error(f"DB load error: {e}")
            return {}

    def save_database(self):
        try:
            out = {n: e.flatten().tolist() for n, e in self.database.items()}
            with open(self.db_path, 'w') as f:
                json.dump(out, f, indent=4)
            return True
        except Exception as e:
            logger.error(f"DB save error: {e}")
            return False

    # ── Validation ─────────────────────────────────────────────────────────────

    def _validate_name(self, name):
        if not name or not isinstance(name, str):
            return False
        if len(name.strip()) < MIN_NAME_LENGTH or len(name) > MAX_NAME_LENGTH:
            return False
        return bool(VALID_NAME_PATTERN.match(name))

    def _check_duplicate(self, embedding):
        """Return (is_duplicate, closest_name, score)."""
        best_name  = None
        best_score = -1.0
        for name, db_emb in self.database.items():
            score = self.face_recognizer.match(embedding, db_emb, cv2.FaceRecognizerSF_FR_COSINE)
            if score > best_score:
                best_score = score
                best_name  = name
        is_dup = best_score >= DUPLICATE_REG_THRESHOLD
        return is_dup, best_name, float(best_score)

    # ── CRUD ───────────────────────────────────────────────────────────────────

    def register_user(self, name, embedding):
        """
        Register user. Returns (success: bool, reason: str).
        Reasons: 'ok' | 'invalid_name' | 'invalid_embedding' | 'duplicate:<name>'
        """
        if not self._validate_name(name):
            return False, "invalid_name"
        if embedding is None or embedding.shape != (1, EMBEDDING_DIM):
            return False, "invalid_embedding"

        with self.db_lock:
            # Check for duplicate face (skip if overwriting same name)
            if self.database:
                is_dup, dup_name, dup_score = self._check_duplicate(embedding)
                if is_dup and dup_name != name:
                    logger.warning(f"Duplicate face: '{name}' too similar to '{dup_name}' ({dup_score:.3f})")
                    return False, f"duplicate:{dup_name}"

            self.database[name] = embedding
            ok = self.save_database()
            return ok, "ok" if ok else "save_error"

    def delete_user(self, name):
        with self.db_lock:
            if name in self.database:
                del self.database[name]
                return self.save_database()
            return False

    def get_users_list(self):
        return list(self.database.keys())

    # ── Detection ──────────────────────────────────────────────────────────────

    def detect_all_faces(self, frame):
        """Return list of all detected face bboxes (for multi-face guard)."""
        h, w, _ = frame.shape
        rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        mp_img = mp.Image(image_format=mp.ImageFormat.SRGB, data=rgb)
        results = self.face_detector.detect(mp_img)
        return results.detections if results.detections else []

    def extract_face_landmarks_and_box(self, frame):
        """
        Returns (face_info, bbox_dict, face_count, quality_issue).
        quality_issue: None | 'too_small' | 'too_close' | 'multiple_faces' | 'low_confidence'
        """
        h, w, _ = frame.shape
        detections = self.detect_all_faces(frame)

        if not detections:
            return None, None, 0, None

        face_count = len(detections)

        # Multi-face guard — return count so caller can decide what to do
        if face_count > 1:
            return None, None, face_count, "multiple_faces"

        best = detections[0]
        bb   = best.bounding_box
        conf = best.categories[0].score if best.categories else 1.0

        xmin   = max(0, int(bb.origin_x))
        ymin   = max(0, int(bb.origin_y))
        bw     = min(w - xmin, int(bb.width))
        bh     = min(h - ymin, int(bb.height))

        # Quality checks
        if bw < MIN_FACE_SIZE:
            return None, None, 1, "too_small"
        if bw / w > MAX_FACE_SIZE_RATIO:
            return None, None, 1, "too_close"

        kp = best.keypoints
        re_x, re_y = int(kp[0].x * w), int(kp[0].y * h)
        le_x, le_y = int(kp[1].x * w), int(kp[1].y * h)
        nt_x, nt_y = int(kp[2].x * w), int(kp[2].y * h)
        mc_x, mc_y = int(kp[3].x * w), int(kp[3].y * h)

        dx, dy     = le_x - re_x, le_y - re_y
        dist_eyes  = np.sqrt(dx*dx + dy*dy) or 1.0
        ux, uy     = dx / dist_eyes, dy / dist_eyes
        mhw        = 0.25 * dist_eyes

        rmc_x, rmc_y = int(mc_x - mhw * ux), int(mc_y - mhw * uy)
        lmc_x, lmc_y = int(mc_x + mhw * ux), int(mc_y + mhw * uy)

        face_info = np.array([
            xmin, ymin, bw, bh,
            re_x, re_y, le_x, le_y,
            nt_x, nt_y,
            rmc_x, rmc_y, lmc_x, lmc_y,
            conf
        ], dtype=np.float32).reshape(1, 15)

        bbox_coords = {
            "xmin": xmin, "ymin": ymin, "width": bw, "height": bh,
            "confidence": conf,
            "landmarks": {
                "right_eye":    (re_x,  re_y),
                "left_eye":     (le_x,  le_y),
                "nose_tip":     (nt_x,  nt_y),
                "mouth_center": (mc_x,  mc_y),
                "right_mouth":  (rmc_x, rmc_y),
                "left_mouth":   (lmc_x, lmc_y),
            }
        }
        return face_info, bbox_coords, 1, None

    # ── Embedding & Match ──────────────────────────────────────────────────────

    def get_embedding(self, frame, face_info):
        try:
            aligned = self.face_recognizer.alignCrop(frame, face_info)
            return self.face_recognizer.feature(aligned)
        except Exception as e:
            logger.error(f"Embedding error: {e}")
            return None

    def compute_match_percentage(self, cosine_score):
        if cosine_score <= 0:
            return 0.0
        if cosine_score >= 1.0:
            return 100.0
        if cosine_score < MATCH_THRESHOLD:
            return round((cosine_score / MATCH_THRESHOLD) * 79.0, 2)
        return round(80.0 + ((cosine_score - MATCH_THRESHOLD) / (1.0 - MATCH_THRESHOLD)) * 20.0, 2)

    def match_face(self, embedding):
        """
        Returns (name, cosine_score, percentage).
        Applies dual-threshold: score < REJECTION_THRESHOLD → always Unknown.
        """
        if embedding is None or not self.database:
            return "Unknown", 0.0, 0.0

        best_name  = "Unknown"
        best_score = -1.0

        for name, db_emb in self.database.items():
            score = self.face_recognizer.match(embedding, db_emb, cv2.FaceRecognizerSF_FR_COSINE)
            if score > best_score:
                best_score = score
                best_name  = name

        # Hard rejection zone
        if best_score < REJECTION_THRESHOLD:
            return "Unknown", float(best_score), self.compute_match_percentage(best_score)

        return best_name, float(best_score), self.compute_match_percentage(best_score)
