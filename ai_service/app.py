"""
UAF Hostel — Smart Allocation AI microservice (Flask + scikit-learn).

A thin REST microservice that scores hostel rooms for a given student using a
KNN-style similarity model on top of the same signals as the PHP heuristic:

  - Same-department roommates already living in the room
  - Same-semester roommates already living in the room
  - Room type vs student's stated preference
  - Free beds (load balancing)

PHP calls /recommend; if this microservice is offline, PHP falls back to its
built-in rule scorer so the portal is never broken.

Run:
    python -m pip install -r requirements.txt
    python app.py

The service listens on 127.0.0.1:5001 by default and only accepts local PHP
calls. Configure host / port / DB credentials via env vars (see README).
"""

from __future__ import annotations

import os
from typing import Any, List, Optional

import numpy as np
import pymysql
from flask import Flask, jsonify, request
from sklearn.neighbors import NearestNeighbors

DB_HOST = os.getenv("HOSTEL_DB_HOST", "127.0.0.1")
DB_PORT = int(os.getenv("HOSTEL_DB_PORT", "3306"))
DB_USER = os.getenv("HOSTEL_DB_USER", "root")
DB_PASS = os.getenv("HOSTEL_DB_PASS", "")
DB_NAME = os.getenv("HOSTEL_DB_NAME", "hostel_management")
SERVICE_HOST = os.getenv("AI_HOST", "127.0.0.1")
SERVICE_PORT = int(os.getenv("AI_PORT", "5001"))
SERVICE_TOKEN = os.getenv("AI_SHARED_TOKEN", "hostel-ai-local-dev")

app = Flask(__name__)


def get_conn() -> pymysql.connections.Connection:
    return pymysql.connect(
        host=DB_HOST, port=DB_PORT, user=DB_USER, password=DB_PASS,
        database=DB_NAME, cursorclass=pymysql.cursors.DictCursor, autocommit=True,
        connect_timeout=5,
    )


def fetch_student(conn, student_id: int) -> Optional[dict]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT s.id AS student_id, s.reg_no, s.department, s.semester,
                   COALESCE(NULLIF(TRIM(u.gender),''),
                            NULLIF(TRIM(s.gender),''), 'male') AS gender,
                   s.room_preference, u.name
            FROM students s
            INNER JOIN users u ON u.id = s.user_id
            WHERE s.id = %s LIMIT 1
            """,
            (student_id,),
        )
        return cur.fetchone()


def fetch_candidate_rooms(conn, gender: str) -> List[dict]:
    """Vacant rooms that match gender policy."""
    gender = str(gender or "male").strip().lower()
    # TODO: unify room tables in next sprint — see ARCHITECTURE.md
    # Current bridge: only include hostels that still have at least one free
    # bed in uaf_hostel_rooms to avoid recommending fully exhausted inventory.
    gender_clause = (
        "h.type IN ('mixed','boys')" if gender == "male"
        else "h.type IN ('mixed','girls')"
    )
    with conn.cursor() as cur:
        cur.execute(
            f"""
            SELECT r.id AS room_id, r.hostel_id, r.room_number, r.capacity,
                   r.occupied, r.room_type,
                   h.name AS hostel_name, h.type AS hostel_type,
                   GREATEST(r.capacity - r.occupied, 0) AS available_beds
            FROM rooms r
            INNER JOIN hostels h ON h.id = r.hostel_id
            WHERE GREATEST(r.capacity - r.occupied, 0) > 0
              AND EXISTS (
                  SELECT 1
                  FROM uaf_hostel_rooms ur
                  WHERE ur.hostel_name = h.name
                    AND ur.current_occupancy < ur.capacity
              )
              AND {gender_clause}
            ORDER BY h.name ASC, r.room_number ASC
            """
        )
        return list(cur.fetchall() or [])


def fetch_roommates(conn, room_id: int) -> List[dict]:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT s.department, s.semester
            FROM allocations a
            INNER JOIN students s ON s.id = a.student_id
            WHERE a.room_id = %s
            """,
            (room_id,),
        )
        return list(cur.fetchall() or [])


def feature_vec(dept_match: int, sem_match: int, pref_match: int,
                free_beds: int, capacity: int) -> np.ndarray:
    """Per-room feature vector for the KNN model.

    Order: [dept_match, sem_match, pref_match, free_ratio, capacity_norm]
    """
    free_ratio = free_beds / max(capacity, 1)
    return np.array(
        [float(dept_match), float(sem_match), float(pref_match),
         float(free_ratio), float(capacity) / 4.0],
        dtype=np.float64,
    )


def ideal_vec() -> np.ndarray:
    # Ideal room: has dept buddy + sem buddy + pref match + many free beds, capacity ~ avg.
    return np.array([1.0, 1.0, 1.0, 1.0, 0.5], dtype=np.float64)


def score_rooms(student: dict, rooms: List[dict], conn) -> List[dict]:
    if not rooms:
        return []

    stu_dept = (student.get("department") or "").strip().upper()
    stu_sem = (student.get("semester") or "").strip().upper()
    stu_pref = (student.get("room_preference") or "shared").strip().lower()

    features = []
    enriched = []
    for r in rooms:
        mates = fetch_roommates(conn, r["room_id"])
        has_dept = any((m.get("department") or "").strip().upper() == stu_dept and stu_dept for m in mates)
        has_sem = any((m.get("semester") or "").strip().upper() == stu_sem and stu_sem for m in mates)
        pref_match = stu_pref == (r.get("room_type") or "shared").strip().lower()

        v = feature_vec(int(has_dept), int(has_sem), int(pref_match),
                        int(r["available_beds"]), int(r["capacity"]))
        features.append(v)
        enriched.append({
            "room_id": int(r["room_id"]),
            "hostel_id": int(r["hostel_id"]),
            "label": f"{r['hostel_name']} — {r['room_number']}",
            "available_beds": int(r["available_beds"]),
            "capacity": int(r["capacity"]),
            "hostel_type": str(r["hostel_type"]),
            "room_type": str(r["room_type"]),
            "_signals": {
                "dept": int(has_dept),
                "sem": int(has_sem),
                "pref": int(pref_match),
                "free_ratio": float(r["available_beds"]) / max(int(r["capacity"]), 1),
            },
        })

    X = np.vstack(features)
    n = X.shape[0]
    k = min(max(n, 1), len(features))
    knn = NearestNeighbors(n_neighbors=k, metric="euclidean")
    knn.fit(X)
    distances, indices = knn.kneighbors(ideal_vec().reshape(1, -1))
    order = list(indices[0])
    dist_arr = list(distances[0])

    # Convert distances into a 0..100 score (smaller distance = higher score).
    max_dist = max(max(dist_arr), 1e-6)

    ranked: List[dict] = []
    for idx, dist in zip(order, dist_arr):
        item = enriched[idx]
        score = float(round((1.0 - (dist / (max_dist + 1e-6))) * 100.0, 2))
        reasons: List[str] = []
        sig = item["_signals"]
        if sig["dept"]:
            reasons.append("Same department roommate (+strong)")
        if sig["sem"]:
            reasons.append("Same semester cohort (+match)")
        if sig["pref"]:
            reasons.append("Room type matches preference (+match)")
        if sig["free_ratio"] >= 0.5:
            reasons.append("Spacious — load balanced")
        if not reasons:
            reasons.append("Baseline gender-safe vacancy")

        ranked.append({
            "room_id": item["room_id"],
            "hostel_id": item["hostel_id"],
            "label": item["label"],
            "score": score,
            "available_beds": item["available_beds"],
            "capacity": item["capacity"],
            "hostel_type": item["hostel_type"],
            "room_type": item["room_type"],
            "reasons": reasons,
            "model": "knn-euclidean",
        })

    return ranked


# --- HTTP API (called by PHP recommendation_service.php) ---------------------

@app.get("/health")
def health() -> Any:
    """Liveness probe — admin UI uses this to show Python vs PHP fallback badge."""
    return jsonify({"ok": True, "service": "uaf-hostel-ai", "model": "knn"})


@app.post("/recommend")
def recommend() -> Any:
    """Rank vacant rooms for student_id; requires X-AI-Token header."""
    incoming_token = request.headers.get("X-AI-Token", "")
    if SERVICE_TOKEN and incoming_token != SERVICE_TOKEN:
        return jsonify({"ok": False, "error": "forbidden"}), 403

    payload = request.get_json(silent=True) or {}
    try:
        student_id = int(payload.get("student_id", 0))
    except (TypeError, ValueError):
        student_id = 0
    if student_id <= 0:
        return jsonify({"ok": False, "error": "student_id_required"}), 400

    try:
        conn = get_conn()
    except Exception as e:
        return jsonify({"ok": False, "error": "db_unreachable", "detail": str(e)}), 503

    try:
        student = fetch_student(conn, student_id)
        if not student:
            return jsonify({"ok": False, "error": "student_not_found"}), 404
        gender = str(student.get("gender") or "male").strip().lower()
        student["gender"] = gender
        rooms = fetch_candidate_rooms(conn, gender)
        ranked = score_rooms(student, rooms, conn)
    finally:
        conn.close()

    top = ranked[0] if ranked else None
    return jsonify({
        "ok": True,
        "model": "knn-euclidean",
        "student": {
            "id": int(student["student_id"]),
            "name": str(student.get("name") or ""),
            "department": str(student.get("department") or ""),
            "semester": str(student.get("semester") or ""),
            "preference": str(student.get("room_preference") or "shared"),
            "gender": str(student.get("gender") or ""),
        },
        "top": top,
        "ranked": ranked,
    })


if __name__ == "__main__":
    app.run(host=SERVICE_HOST, port=SERVICE_PORT, debug=False)
