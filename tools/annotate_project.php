<?php
declare(strict_types=1);

/**
 * One-time utility: prepend explanatory file headers to PHP sources.
 * Run: php tools/annotate_project.php
 * Safe to re-run — skips files that already contain "@file".
 */

$root = dirname(__DIR__);
$marker = '@file';

$descriptions = [
    'index.php' => ['Public entry', 'Redirects visitors to landing.php', 'public', ''],
    'landing.php' => ['Marketing homepage', 'Public landing with features, notices teaser, login CTA', 'public', 'notices'],
    'login.php' => ['Authentication', 'Email/password login, throttle, role redirect, email verify gate', 'public', 'users, login_attempts'],
    'logout.php' => ['Session teardown', 'Clears session and returns to login', 'authenticated', ''],
    'register.php' => ['Student registration', 'Creates user + student row, verification token', 'public', 'users, students'],
    'forgot_password.php' => ['Password recovery', 'Issues reset token (dev link on screen)', 'public', 'password_resets'],
    'reset_password.php' => ['Password reset form', 'Consumes single-use token from email/link', 'public', 'password_resets, users'],
    'verify_email.php' => ['Email verification', 'Activates account via token in URL', 'public', 'users'],
    'notices.php' => ['Public notices', 'Read-only list of published announcements', 'public', 'notices'],
    'warden.php' => ['Warden dashboard', 'Hostel-scoped complaints and room-change queues', 'warden', 'complaints, room_change_requests'],
    'config/db.php' => ['Database bootstrap', 'PDO singleton + env loader helpers', 'system', ''],
    'config/env.example.php' => ['Environment template', 'Copy to config/env.php — DB and AI settings', 'system', ''],
    'includes/app.php' => ['Application core', 'Sessions, CSRF, auth guards, password verify, warden scope', 'system', ''],
    'includes/auth_throttle.php' => ['Login rate limiter', 'Blocks brute force via login_attempts table', 'system', 'login_attempts'],
    'includes/help_chatbot.php' => ['Help assistant engine', 'Rule-based FAQ replies with student context placeholders', 'student', ''],
    'includes/warden_scope.php' => ['Warden authorization', 'Checks complaint/room-change belongs to warden hostel', 'warden', ''],
    'includes/student_avatar.php' => ['Avatar uploads', 'Validate, save, delete student profile photos', 'student', 'students'],
    'includes/layout/header.php' => ['Dashboard HTML head', 'Bootstrap, CSS, opens dash-shell wrapper', 'layout', ''],
    'includes/layout/sidebar.php' => ['Role navigation', 'Admin / student / warden menu links', 'layout', ''],
    'includes/layout/footer.php' => ['Dashboard footer', 'Closes layout, loads main.js and ui.js', 'layout', ''],
    'includes/partials/flash_alerts.php' => ['Flash → toast', 'Outputs session flash for HostelUI.toast()', 'layout', ''],
    'includes/partials/empty_state.php' => ['Empty UI block', 'Reusable no-data message for tables/lists', 'layout', ''],
    'includes/partials/stat_card.php' => ['KPI card', 'Reusable statistics card partial', 'layout', ''],
    'includes/partials/notice_board.php' => ['Notice list UI', 'Compact or card layout for public notices', 'layout', 'notices'],
    'includes/services/recommendation_service.php' => ['AI recommendation bridge', 'Calls Python KNN or PHP fallback scorer', 'admin', 'rooms, allocations'],
    'includes/pages/voucher_view.php' => ['Printable voucher', 'Shared HTML for fee/payment vouchers', 'admin,student', 'payments'],
    'includes/pages/coming_soon.php' => ['Placeholder page', 'Stub for unfinished features', 'any', ''],
    'includes/models/student_model.php' => ['Student data layer', 'Profile, hostels, rooms, applications', 'all', 'students, room_allocation'],
    'includes/models/smart_allocation_model.php' => ['Smart allocation layer', 'Beds, allocations, occupancy sync', 'admin', 'beds, allocations'],
    'includes/models/complaint_model.php' => ['Complaints layer', 'CRUD, escalation to admin', 'student,admin,warden', 'complaints'],
    'includes/models/room_change_model.php' => ['Room change layer', 'Student requests and status updates', 'student,admin,warden', 'room_change_requests'],
    'includes/models/payment_model.php' => ['Payments layer', 'Fee structure, record payment, balances', 'admin,student', 'payments, fee_structure'],
    'includes/models/notice_model.php' => ['Notices layer', 'Admin CMS + public listing', 'admin,public', 'notices'],
    'includes/models/audit_model.php' => ['Audit log layer', 'Append-only security/event history', 'admin', 'audit_log'],
    'includes/models/password_reset_model.php' => ['Password reset layer', 'Token issue and consume (hashed)', 'public', 'password_resets'],
    'admin/dashboard.php' => ['Admin home', 'KPIs and Chart.js analytics', 'admin', 'room_allocation, complaints, rooms'],
    'admin/students.php' => ['Manage students', 'List, edit, activate/deactivate, delete', 'admin', 'students, users'],
    'admin/applications.php' => ['Room applications', 'Approve/reject room_allocation requests', 'admin', 'room_allocation'],
    'admin/rooms.php' => ['Hostels & rooms', 'CRUD hostels/rooms, bed capacity', 'admin', 'hostels, rooms, beds'],
    'admin/smart_allocation.php' => ['Smart allocation UI', 'AI-ranked rooms and auto-assign beds', 'admin', 'allocations, beds'],
    'admin/smart_recommend_api.php' => ['Recommend JSON API', 'Returns ranked rooms for a student_id', 'admin', ''],
    'admin/complaints.php' => ['All complaints', 'Admin view including escalated items', 'admin', 'complaints'],
    'admin/room_changes.php' => ['Room change requests', 'Approve/reject student moves', 'admin', 'room_change_requests'],
    'admin/payments.php' => ['Payment management', 'Record fees and view history', 'admin', 'payments'],
    'admin/payment_voucher.php' => ['Admin payment voucher', 'Printable receipt for recorded payment', 'admin', 'payments'],
    'admin/notices.php' => ['Notices CMS', 'Create/edit/publish announcements', 'admin', 'notices'],
    'admin/wardens.php' => ['Warden management', 'Create wardens and assign hostels', 'admin', 'warden_assignments'],
    'admin/audit_log.php' => ['Audit log viewer', 'Filter security and business events', 'admin', 'audit_log'],
    'admin/reports.php' => ['Reports export', 'CSV downloads for reporting', 'admin', ''],
    'student/dashboard.php' => ['Student home', 'Profile, avatar, notices, charts', 'student', 'students, notices'],
    'student/apply.php' => ['Hostel application', 'Submit room_allocation request', 'student', 'room_allocation'],
    'student/my_room.php' => ['My allotment', 'Shows approved room_allocation status', 'student', 'room_allocation'],
    'student/complaints.php' => ['Student complaints', 'Create and escalate complaints', 'student', 'complaints'],
    'student/room_change.php' => ['Room change request', 'Ask to move between rooms', 'student', 'room_change_requests'],
    'student/payments.php' => ['Student payments', 'View dues and payment history', 'student', 'payments'],
    'student/fee_voucher.php' => ['Fee challan', 'Printable fee voucher for student', 'student', 'payments'],
    'student/hostel_card.php' => ['Hostel ID card', 'Printable card with QR code', 'student', 'students, room_allocation'],
    'student/allotment_slip.php' => ['Allotment slip', 'Printable official allotment document', 'student', 'room_allocation'],
    'student/help_chatbot.php' => ['Help assistant UI', 'Chat interface for rule-based bot', 'student', ''],
    'student/help_chat_api.php' => ['Help assistant API', 'JSON POST endpoint for chat replies', 'student', ''],
    'warden/students.php' => ['Warden student list', 'Residents in assigned hostel only', 'warden', 'room_allocation'],
    'setup/create_admin.php' => ['Local admin setup', 'Create/remove verified admin on localhost only', 'localhost', 'users'],
];

function inferMeta(string $rel): array
{
    global $descriptions;
    $rel = str_replace('\\', '/', $rel);
    if (isset($descriptions[$rel])) {
        return $descriptions[$rel];
    }
    if (str_starts_with($rel, 'admin/')) {
        return ['Admin module', basename($rel), 'admin', ''];
    }
    if (str_starts_with($rel, 'student/')) {
        return ['Student module', basename($rel), 'student', ''];
    }
    if (str_starts_with($rel, 'includes/models/')) {
        return ['Data model', basename($rel), 'system', ''];
    }
    return ['Project file', basename($rel), 'system', ''];
}

function buildHeader(string $rel, array $meta): string
{
    [$title, $desc, $access, $tables] = $meta;
    $tablesLine = $tables !== '' ? " * @database {$tables}\n" : '';
    return <<<HDR
/**
 * @file {$rel}
 * @brief {$title} — {$desc}
 * @access {$access}
{$tablesLine} */

HDR;
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$updated = 0;
$skipped = 0;

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));

    if (str_starts_with($rel, 'tools/')) {
        continue;
    }

    $content = file_get_contents($path);
    if ($content === false || str_contains(substr($content, 0, 800), $marker)) {
        $skipped++;
        continue;
    }

    if (!preg_match('/^<\?php\r?\ndeclare\(strict_types=1\);\r?\n/', $content, $m, PREG_OFFSET_CAPTURE)) {
        $skipped++;
        continue;
    }

    $insertAt = $m[0][1] + strlen($m[0][0]);
    $header = buildHeader($rel, inferMeta($rel));
    $newContent = substr($content, 0, $insertAt) . $header . substr($content, $insertAt);

    file_put_contents($path, $newContent);
    $updated++;
    echo "Annotated: {$rel}\n";
}

echo "\nDone. Updated: {$updated}, skipped: {$skipped}\n";
