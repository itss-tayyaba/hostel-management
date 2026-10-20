<?php
declare(strict_types=1);
/**
 * @file includes/services/llm_chat_service.php
 * @brief Anthropic Claude chat for the student help assistant
 * @access student
 */

require_once __DIR__ . '/../../config/db.php';

function llm_chat_api_key(): string
{
    return trim(app_env_string('ANTHROPIC_API_KEY', ''));
}

function llm_chat_model(): string
{
    return app_env_string('ANTHROPIC_MODEL', 'claude-3-5-haiku-20241022');
}

function llm_chat_enabled(): bool
{
    return llm_chat_api_key() !== '';
}

/**
 * Build system prompt with portal facts + logged-in student context.
 *
 * @param array<string,string> $ctx
 */
function llm_chat_system_prompt(array $ctx): string
{
  $name = $ctx['student_name'] ?? 'Student';
  $reg = $ctx['reg_no'] ?? '';
  $dept = $ctx['department'] ?? '';
  $alloc = $ctx['allocation'] ?? 'not allocated yet';
  $ml = $ctx['ml_status'] ?? '';

  $mlLine = $ml !== '' ? "\n- ML hostel application status: {$ml}" : '';

  return <<<PROMPT
You are the official AI assistant for the University of Agriculture Faisalabad (UAF) Hostel Management System web portal.

STUDENT CONTEXT (use when relevant):
- Name: {$name}
- Registration: {$reg}
- Department (program): {$dept}
- Room allotment: {$alloc}{$mlLine}

PORTAL MENU (student sidebar — give exact steps):
1. Dashboard — profile, photo, notices
2. Apply for Hostel — ML profile + room preference; shows allocation score (allocated/waitlisted)
3. Allocated Room — current room status
4. Hostel ID Card / Allotment Slip — printable from sidebar (open in new tab)
5. Complaints — create complaint; can escalate to admin
6. Room Change — request move between rooms
7. Payments — view dues/history; Fee Voucher for challan
8. Help assistant — this chat

RULES FOR YOUR ANSWERS:
- Answer only about this hostel portal, UAF hostel processes, and general student hostel guidance.
- Be concise (2–5 short paragraphs max), friendly, and actionable with numbered steps when helpful.
- If the student asks something outside hostel/portal scope (coding, unrelated topics), politely redirect to hostel topics.
- Never invent specific dates, fees, or policies; say "check Notices page or hostel office" for deadlines.
- Do not reveal API keys, admin passwords, or internal system details.
- Use the student's name occasionally when natural.

CONTACT (general): Hostel office +92-41-9201045, hostel@uaf.edu.pk, Mon–Sat 9am–5pm.
PROMPT;
}

/**
 * Call Anthropic Messages API.
 *
 * @param list<array{role:string,content:string}> $messages
 * @return array{ok:bool,reply?:string,error?:string}
 */
function llm_chat_complete(array $messages, array $studentContext): array
{
    $apiKey = llm_chat_api_key();
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'LLM API key not configured.'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL extension required.'];
    }

    $body = [
        'model' => llm_chat_model(),
        'max_tokens' => min(1024, max(256, app_env_int('ANTHROPIC_MAX_TOKENS', 600))),
        'system' => llm_chat_system_prompt($studentContext),
        'messages' => $messages,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL init failed.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(10, app_env_int('ANTHROPIC_TIMEOUT', 45)),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        return [
            'ok' => false,
            'error' => $curlErr !== '' ? 'Network error: ' . $curlErr : 'Empty response from AI service.',
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON from AI service.'];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $errMsg = (string)($decoded['error']['message'] ?? $decoded['error'] ?? 'HTTP ' . $httpCode);
        return ['ok' => false, 'error' => $errMsg];
    }

    $text = '';
    foreach ($decoded['content'] ?? [] as $block) {
        if (is_array($block) && ($block['type'] ?? '') === 'text') {
            $text .= (string)($block['text'] ?? '');
        }
    }
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'error' => 'AI returned an empty reply.'];
    }

    return ['ok' => true, 'reply' => $text];
}

/** Trim session history to last N turns. */
function llm_chat_trim_history(array $history, int $maxMessages = 12): array
{
    if (count($history) <= $maxMessages) {
        return $history;
    }
    return array_slice($history, -$maxMessages);
}
