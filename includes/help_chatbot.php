<?php
declare(strict_types=1);
/**
 * @file includes/help_chatbot.php
 * @brief Help assistant engine — Rule-based FAQ replies with student context placeholders
 * @access student
 */

require_once __DIR__ . '/services/llm_chat_service.php';

/**
 * Main entry: Claude LLM when API key is set, else rule-based FAQ.
 *
 * @param list<array{role:string,content:string}>|null $history
 */
function help_chatbot_reply(string $rawMessage, array $studentContext, ?array &$history = null): string
{
    if (llm_chat_enabled() && $history !== null) {
        $messages = $history;
        $messages[] = ['role' => 'user', 'content' => $rawMessage];
        $result = llm_chat_complete($messages, $studentContext);
        if ($result['ok'] && !empty($result['reply'])) {
            $history[] = ['role' => 'user', 'content' => $rawMessage];
            $history[] = ['role' => 'assistant', 'content' => (string)$result['reply']];
            $history = llm_chat_trim_history($history);
            return (string)$result['reply'];
        }
    }

    return help_chatbot_rules_reply($rawMessage, $studentContext);
}

/**
 * Rule-based fallback (no external API).
 * Placeholders: {{student_name}}, {{reg_no}}, {{department}}, {{allocation}}.
 */
function help_chatbot_rules_reply(string $rawMessage, array $studentContext): string
{
    $msg = mb_strtolower(trim($rawMessage), 'UTF-8');
    if ($msg === '') {
        return 'Ask me about hostel allocation, complaints, payments, room change, or portal shortcuts.';
    }
    $repl = static function (string $tpl) use ($studentContext): string {
        foreach ($studentContext as $key => $val) {
            if (is_scalar($val)) {
                $tpl = str_replace('{{' . $key . '}}', (string)$val, $tpl);
            }
        }
        return $tpl;
    };

    if (preg_match('/^(hi|hey|hello|salam)$/u', $msg)) {
        return $repl(
            'Hello {{student_name}}! Ask about hostel application, complaints, payments, room change, or hostel rules anytime.'
        );
    }

    /** @var list<array{patterns:list<string>,response:string}> $rules Order matters — first match wins */
    $rules = [
        [
            'patterns' => ['hello', 'hi ', 'hey', 'assalam', 'good morning', 'good evening', ' salaam'],
            'response' => 'Hello {{student_name}}! I am your hostel portal assistant—you can ask about applications, complaints, payments, room changes, ID card, or notices.',
        ],
        [
            'patterns' => ['how do i apply', 'apply for hostel', 'hostel apply', 'application'],
            'response' => 'Go to “Apply for Hostel” in the sidebar. Pick a hostel + capacity, then submit—admin reviews seat availability and approves or rejects.',
        ],
        [
            'patterns' => ['application status', 'pending hostel', 'have i been allotted', 'allocation status', 'allotment status'],
            'response' => 'Open “Allocated Room” to see your current placement. Pending applications show on the same page until admin approves.',
        ],
        [
            'patterns' => ['hostel card', 'id card', 'identity card', 'student card'],
            'response' => 'Visit Allocated Room → Print Hostel ID Card. It auto-fills your photo, room, QR code and is print-ready.',
        ],
        [
            'patterns' => ['allotment slip', 'admit slip', 'allocation slip'],
            'response' => 'Open Allocated Room → Download Allotment Slip. Use “Print / Save as PDF” to keep a copy.',
        ],
        [
            'patterns' => ['fee challan', 'voucher', 'receipt', 'fee slip', 'payment slip', 'pay slip'],
            'response' => 'In Payments, click the green “Download Fee Challan” button for the current semester. Past payments show a Voucher button per row.',
        ],
        [
            'patterns' => ['complaint', 'maintenance', 'issue ', 'repair', 'leak', 'electric', 'fan', 'water'],
            'response' => 'Sidebar → Complaints. Title + description is enough. Wardens see it for your hostel; you can also escalate to admin.',
        ],
        [
            'patterns' => ['escalate', 'escalation'],
            'response' => 'Open Complaints, find your active complaint, and use “Submit to Admin”. Wardens still see it but admin gets a flag.',
        ],
        [
            'patterns' => ['room change', 'change room', 'swap', 'transfer', 'shift room'],
            'response' => 'Sidebar → Request Room Change. Provide a reason. Wardens process students from their own hostel; admin can override.',
        ],
        [
            'patterns' => ['payment', 'fee', 'dues', 'bill', 'pay '],
            'response' => 'Open Payments under the sidebar. You can also print a fee challan for the current semester from the same page.',
        ],
        [
            'patterns' => ['forgot password', 'reset password', 'password reset', 'lost password'],
            'response' => 'Use “Forgot password?” on the login page. A reset link will be generated (valid 30 minutes).',
        ],
        [
            'patterns' => ['verify email', 'email verification', 'verify my email'],
            'response' => 'After registering you got a verification link. Open it once to mark your email as verified. Ask admin to resend if needed.',
        ],
        [
            'patterns' => ['notice', 'announcement', 'news'],
            'response' => 'Open the public Notices page (linked from the landing page footer) for the latest announcements and rules.',
        ],
        [
            'patterns' => ['selection', 'allot', 'priority'],
            'response' => 'Allocation follows policy + available seats + admin review. Smart Allocation also matches department / semester / room-type for compatibility.',
        ],
        [
            'patterns' => ['warden', 'custodian'],
            'response' => 'Wardens manage day-to-day affairs of their hostel. The portal forwards complaints and room-change requests to them automatically.',
        ],
        [
            'patterns' => ['rule ', 'rules', 'curfew', 'visitor', 'visitors', 'guest', 'guests'],
            'response' => 'Hostel rules cover visitors, curfew, electricity usage, mess timings and conduct. Check Notices → Rules for the latest update.',
        ],
        [
            'patterns' => ['profile', 'edit profile', 'update profile', 'change phone', 'change avatar', 'photo'],
            'response' => 'Open Dashboard → Profile to update your display name, department, phone number, gender, and avatar photo.',
        ],
        [
            'patterns' => ['contact', 'office', 'phone'],
            'response' => 'Hostel office: +92-41-9201045 · hostel@uaf.edu.pk · Mon–Sat 9am-5pm. Use Complaints for actionable issues.',
        ],
        [
            'patterns' => ['mess', 'food', 'cafeteria'],
            'response' => 'The mess is operated separately by the hostel office — timings are on the Notices board. Hygiene complaints can be filed in Complaints.',
        ],
        [
            'patterns' => ['wifi', 'internet', 'network'],
            'response' => 'Wi-Fi is provided in all blocks. If your room has no signal, file a complaint under Complaints with title “Wi-Fi issue”.',
        ],
        [
            'patterns' => ['ac', 'cooler', 'heater', 'appliance'],
            'response' => 'Personal high-load appliances need permission. File a request in Complaints titled “Appliance permission”; warden replies in the same thread.',
        ],
        [
            'patterns' => ['security', 'guard', 'safety', 'cctv'],
            'response' => 'Hostels run 24/7 security + CCTV. Emergencies → call the hostel office immediately. Non-urgent reports → Complaints.',
        ],
        [
            'patterns' => ['password', 'cannot login', 'can not login', 'login problem', 'sign in problem'],
            'response' => 'Use “Forgot password?” on the login page. If your account is disabled or your warden role is not assigned yet, contact admin.',
        ],
        [
            'patterns' => ['help', 'menu', 'what can you do', 'options'],
            'response' => 'I can help with: applying for a hostel, allotment status, ID card, fee challan, complaints, escalation, room change, profile, rules, notices, password reset.',
        ],
        [
            'patterns' => ['thank'],
            'response' => 'Happy to help {{student_name}}!',
        ],
        [
            'patterns' => ['bye', 'goodbye', 'see you'],
            'response' => 'Take care, {{student_name}}. The portal is open 24/7 whenever you need it again.',
        ],
    ];

    foreach ($rules as $rule) {
        $response = (string)$rule['response'];
        foreach ($rule['patterns'] as $needle) {
            $n = strtolower(trim($needle));
            if ($n !== '' && str_contains($msg, $n)) {
                return $repl($response);
            }
        }
    }

    return $repl(
        'I could not pinpoint that—but use Apply for Hostel, Payments, Submit Complaints, or Request Room Change from the sidebar. '
        . 'Your allotment note: {{allocation}} (Dept: {{department}}). '
        . 'Rephrase once with keywords like allocate, complain, dues, swap room.'
    );
}

/**
 * @return array<string,string>
 */
function help_chatbot_student_context(PDO $pdo, int $userId): array
{
    $ctx = [
        'student_name' => 'student',
        'reg_no' => '',
        'department' => '',
        'allocation' => 'not allocated yet',
        'ml_status' => '',
    ];
    try {
        $st = $pdo->prepare(
            'SELECT s.id, s.reg_no, s.department, u.name
             FROM students s JOIN users u ON u.id=s.user_id
             WHERE u.id = :uid LIMIT 1'
        );
        $st->execute([':uid' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $ctx['student_name'] = (string)$row['name'];
            $ctx['reg_no'] = (string)$row['reg_no'];
            $ctx['department'] = (string)$row['department'];
            $sid = (int)$row['id'];

            $q = $pdo->prepare(
                'SELECT h.name hostel_name, r.room_number FROM room_allocation ra
                 JOIN rooms r ON r.id=ra.room_id JOIN hostels h ON h.id=r.hostel_id
                 WHERE ra.student_id=:sid AND ra.status=\'approved\'
                 ORDER BY COALESCE(ra.responded_at, ra.applied_at) DESC LIMIT 1'
            );
            $q->execute([':sid' => $sid]);
            $allo = $q->fetch(PDO::FETCH_ASSOC);
            if ($allo) {
                $ctx['allocation'] = (string)$allo['hostel_name'] . ', room ' . (string)$allo['room_number'];
            }

            try {
                $ml = $pdo->prepare(
                    'SELECT ml_score, ml_status, final_status, hostel_name, room_number
                     FROM hostel_applications WHERE student_id = :sid
                     ORDER BY applied_at DESC LIMIT 1'
                );
                $ml->execute([':sid' => $sid]);
                $ha = $ml->fetch(PDO::FETCH_ASSOC);
                if ($ha) {
                    $score = $ha['ml_score'] !== null ? number_format((float)$ha['ml_score'], 1) . '%' : 'n/a';
                    $ctx['ml_status'] = (string)$ha['final_status']
                        . ' (ML score ' . $score . ')'
                        . (($ha['hostel_name'] ?? '') !== '' ? ', hostel ' . $ha['hostel_name'] . ' room ' . $ha['room_number'] : '');
                }
            } catch (Throwable $e) {
            }
        }
    } catch (Throwable $e) {
    }
    return $ctx;
}

/** Whether Claude is configured (for UI badge). */
function help_chatbot_uses_llm(): bool
{
    return llm_chat_enabled();
}
