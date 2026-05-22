# UAF Hostel — Smart Allocation AI microservice

A small Flask + scikit-learn service that powers the **Smart Allocation** screen
of the PHP portal. When this service is running, PHP calls it for room
recommendations; when it isn't, PHP gracefully falls back to its built-in
rule-based scorer so the portal is never broken.

## How it ranks rooms

For every vacant + gender-matching room we build a 5-feature vector:

| Feature | Meaning |
|---|---|
| `dept_match`  | 1 if any current roommate shares the student's department |
| `sem_match`   | 1 if any current roommate shares the student's semester |
| `pref_match`  | 1 if `rooms.room_type` matches `students.room_preference` |
| `free_ratio`  | `available_beds / capacity` (load balancing) |
| `capacity_n`  | `capacity / 4` (normalised) |

We then use **scikit-learn's `NearestNeighbors`** (Euclidean) to find the rooms
closest to the *ideal* vector `(1, 1, 1, 1, 0.5)`. The smaller the distance, the
higher the score (`0..100`).

This is a real ML routine (no hand-written if/else weighting), so it can be
swapped for a richer model (cosine + TF-IDF over roommate hobbies, KNN with
trained embeddings, etc.) without touching PHP.

## Run

```bash
cd ai_service
python -m pip install -r requirements.txt
python app.py
```

The service binds to `127.0.0.1:5001` by default.

### Environment variables

| Variable | Default | Notes |
|---|---|---|
| `HOSTEL_DB_HOST` | `127.0.0.1` | MySQL host |
| `HOSTEL_DB_PORT` | `3306` | MySQL port |
| `HOSTEL_DB_USER` | `root` | MySQL user |
| `HOSTEL_DB_PASS` | `` | MySQL password |
| `HOSTEL_DB_NAME` | `hostel_management` | DB schema |
| `AI_HOST` | `127.0.0.1` | Bind host |
| `AI_PORT` | `5001` | Bind port |
| `AI_SHARED_TOKEN` | `hostel-ai-local-dev` | PHP must send this in `X-AI-Token` |

### PHP integration

`includes/services/recommendation_service.php` first tries
`POST http://127.0.0.1:5001/recommend` with the shared token. If the call
fails (timeout, 5xx, unauthorised) the PHP **falls back to the local
rule-based heuristic** — so the portal keeps working even if Python is offline.

To force the fallback (e.g. for grading offline), simply stop this service.

## Test it manually

```bash
curl -X POST http://127.0.0.1:5001/recommend \
  -H 'Content-Type: application/json' \
  -H 'X-AI-Token: hostel-ai-local-dev' \
  -d '{"student_id": 1}'
```
