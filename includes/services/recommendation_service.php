<?php
declare(strict_types=1);
/**
 * @file includes/services/recommendation_service.php
 * @brief AI recommendation bridge — Calls Python KNN or PHP fallback scorer
 * @access admin
 * @database rooms, allocations
 */

/**
 * Smart roommate / room heuristic engine (pseudo-AI rules based).
 *
 * Scoring recap (additive):
 *  - +10 → at least one occupant mirrors the student's department string.
 *  - +5  → at least one occupant mirrors the student's semester snapshot.
 *  - +3  → student.room_preference aligns with rooms.room_type (single/shared).
 *  - +2  → multiplied by AVAILABLE_BEDS (capacity - synced occupied count) so roomy
 *          cells bubble up before overcrowding quieter spaces.
 *
 * Guard rails:
 *  - Gender vs hostel.block type (mixed / aligned) happens before scoring.
 *  - Empty beds gate: slack must be strictly positive — already allocated students are excluded upstream.
 */

require_once __DIR__ . '/../models/smart_allocation_model.php';

/** Python AI microservice settings (from config/env.php). */
function ai_service_url(): string
{
    return app_env_string('AI_SERVICE_URL', 'http://127.0.0.1:5001/recommend');
}

function ai_service_token(): string
{
    return app_env_string('AI_SERVICE_TOKEN', 'hostel-ai-local-dev');
}

function ai_service_timeout(): int
{
    return max(1, app_env_int('AI_SERVICE_TIMEOUT', 2));
}

/**
 * Try the Python scikit-learn microservice first. On any failure (offline,
 * 5xx, timeout, unauthorised) return null so the caller falls back to the
 * local PHP heuristic.
 *
 * @return list<array<string,mixed>>|null
 */
function hostel_recommend_via_python(int $studentId): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init(ai_service_url());
    if ($ch === false) return null;
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_CONNECTTIMEOUT  => 1,
        CURLOPT_TIMEOUT         => ai_service_timeout(),
        CURLOPT_HTTPHEADER      => [
            'Content-Type: application/json',
            'X-AI-Token: ' . ai_service_token(),
        ],
        CURLOPT_POSTFIELDS      => json_encode(['student_id' => $studentId]),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $body === '' || $code < 200 || $code >= 300) {
        return null;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        return null;
    }
    $ranked = $decoded['ranked'] ?? null;
    if (!is_array($ranked)) {
        return null;
    }
    $shaped = [];
    foreach ($ranked as $row) {
        if (!is_array($row) || !isset($row['room_id'])) continue;
        $shaped[] = [
            'room_id'        => (int)$row['room_id'],
            'hostel_id'      => (int)($row['hostel_id'] ?? 0),
            'label'          => (string)($row['label'] ?? ''),
            'score'          => (int)round((float)($row['score'] ?? 0)),
            'reasons'        => is_array($row['reasons'] ?? null) ? array_values(array_map('strval', $row['reasons'])) : [],
            'available_beds' => (int)($row['available_beds'] ?? 0),
            'capacity'       => (int)($row['capacity'] ?? 0),
            'hostel_type'    => (string)($row['hostel_type'] ?? ''),
            'room_type'      => (string)($row['room_type'] ?? ''),
        ];
    }
    return $shaped;
}

function hostel_recommend_engine_in_use(): string
{
    // Probe /health quickly — used by the admin UI to show which engine ranked rooms.
    if (!function_exists('curl_init')) return 'php-rules';
    $ch = curl_init(rtrim(str_replace('/recommend', '/health', ai_service_url()), '/'));
    if ($ch === false) return 'php-rules';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_CONNECTTIMEOUT  => 1,
        CURLOPT_TIMEOUT         => 1,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && is_string($body)) ? 'python-knn' : 'php-rules';
}

/**
 * Produce candidate rooms plus human-readable rationales for the Bootstrap modal UI.
 *
 * Tries the Python KNN microservice first, then falls back to the PHP rule scorer.
 *
 * @return list<array{
 *     room_id:int,
 *     hostel_id:int,
 *     label:string,
 *     score:int,
 *     reasons:list<string>,
 *     available_beds:int,
 *     capacity:int,
 *     hostel_type:string,
 *     room_type:string
 * }>
 */
function hostel_recommend_rooms_with_reasons(PDO $pdo, int $studentId): array
{
    $remote = hostel_recommend_via_python($studentId);
    if (is_array($remote) && $remote !== []) {
        return $remote;
    }
    return hostel_recommend_rooms_with_reasons_php($pdo, $studentId);
}

/**
 * PHP-only fallback (unchanged scoring rules). Kept as a separate function so
 * the Python service can be exercised in isolation if needed.
 *
 * @return list<array<string,mixed>>
 */
function hostel_recommend_rooms_with_reasons_php(PDO $pdo, int $studentId): array
{
    if (!smart_allocation_tables_ready($pdo)) {
        return [];
    }

    $profile = smart_allocation_get_student_profile($pdo, $studentId);
    if (!$profile || smart_allocation_student_is_assigned($pdo, $studentId)) {
        return [];
    }

    $stmt = $pdo->prepare(
        '
        SELECT r.id AS room_id,
               r.hostel_id AS hostel_id,
               r.room_number,
               r.capacity,
               r.occupied,
               r.room_type,
               h.name AS hostel_name,
               h.type AS hostel_type,
               GREATEST(r.capacity - r.occupied, 0) AS available_beds
        FROM rooms r
        INNER JOIN hostels h ON h.id = r.hostel_id
        WHERE GREATEST(r.capacity - r.occupied, 0) > 0
        ORDER BY h.name ASC, r.room_number ASC
    '
    );
    $stmt->execute();

    /** @var list<array<string,mixed>> $roomRows */
    $roomRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $matesStmt = $pdo->prepare(
        '
        SELECT s.department AS department,
               s.semester AS semester
        FROM allocations a
        INNER JOIN students s ON s.id = a.student_id
        WHERE a.room_id = :rid
    '
    );

    $stuDept = strtoupper(trim((string)$profile['department']));
    $stuSem = strtoupper(trim((string)($profile['semester'] ?? '')));
    $stuPref = strtolower(trim((string)($profile['room_preference'])));

    $scored = [];
    foreach ($roomRows as $row) {
        if (!smart_allocation_hostel_matches_student_gender((string)$row['hostel_type'], (string)$profile['gender'])) {
            continue;
        }

        $available = max(0, (int)$row['capacity'] - (int)$row['occupied']);

        $matesStmt->execute([':rid' => (int)$row['room_id']]);
        $mates = $matesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $score = 0;
        $reasons = [];

        $hasDeptBuddy = false;
        $hasSemBuddy = false;
        foreach ($mates as $m) {
            if ($stuDept !== '' && strtoupper(trim((string)$m['department'])) === $stuDept) {
                $hasDeptBuddy = true;
            }
            if ($stuSem !== '' && strtoupper(trim((string)($m['semester'] ?? ''))) === $stuSem) {
                $hasSemBuddy = true;
            }
        }

        if ($hasDeptBuddy) {
            $score += 10;
            $reasons[] = 'Same department students already live there (+10)';
        }
        if ($hasSemBuddy) {
            $score += 5;
            $reasons[] = 'Roommates match semester grouping (+5)';
        }

        $roomFlavor = strtolower(trim((string)$row['room_type']));
        if ($stuPref === $roomFlavor) {
            $score += 3;
            $reasons[] = 'Room type matches hostel preference footprint (+3)';
        }

        if ($available > 0) {
            $score += $available * 2;
            $reasons[] = 'More available beds help balance load (+2 × ' . $available . ')';
        }

        if ($reasons === []) {
            $reasons[] = 'Baseline fit — gender-safe vacancy with roommate-neutral signal';
        }

        $label = trim((string)$row['hostel_name']) . ' — ' . trim((string)$row['room_number']);

        $scored[] = [
            'room_id' => (int)$row['room_id'],
            'hostel_id' => (int)$row['hostel_id'],
            'label' => $label,
            'score' => $score,
            'reasons' => $reasons,
            'available_beds' => $available,
            'capacity' => (int)$row['capacity'],
            'hostel_type' => (string)$row['hostel_type'],
            'room_type' => (string)$row['room_type'],
        ];
    }

    usort(
        $scored,
        static function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }
            if ($left['available_beds'] !== $right['available_beds']) {
                return $right['available_beds'] <=> $left['available_beds'];
            }
            return strcmp((string)$left['label'], (string)$right['label']);
        }
    );

    return $scored;
}
