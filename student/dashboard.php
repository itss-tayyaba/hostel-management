<?php
declare(strict_types=1);
/**
 * @file student/dashboard.php
 * @brief Student home — Profile, avatar, notices, charts
 * @access student
 * @database students, notices
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/student_avatar.php';
require_once __DIR__ . '/../includes/models/student_model.php';
require_once __DIR__ . '/../includes/models/notice_model.php';
require_role('student');

$userRole = 'student';
$pageTitle = 'Student Dashboard';
$activePage = 'student_dashboard';

$dashShellClass = 'student-shell-ref';

$userId = (int)$_SESSION['user_id'];
$pdo = db();

$student = student_get_by_user_id($pdo, $userId);
$userName = (string)($_SESSION['name'] ?? 'Student');

// Profile POST: editable details + optional photo upload
$profileMessages = [];
$profileErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $profileErrors[] = 'Invalid CSRF token. Refresh and retry.';
    } elseif ((string)($_POST['action'] ?? '') === 'profile') {
        if (!$student) {
            $profileErrors[] = 'Student profile missing—contact administrator.';
        } else {
            $name = trim((string)($_POST['display_name'] ?? ''));
            $department = trim((string)($_POST['department'] ?? ''));
            $phoneRaw = preg_replace('/\s+/', ' ', trim((string)($_POST['phone'] ?? '')));
            $genderNorm = normalize_user_gender((string)($_POST['gender'] ?? ''));

            if ($name === '' || mb_strlen($name) < 2) {
                $profileErrors[] = 'Enter your full display name.';
            }
            if ($department === '') {
                $profileErrors[] = 'Department cannot be blank.';
            }
            if ($genderNorm === null) {
                $profileErrors[] = 'Choose gender (male or female).';
            }
            if ($phoneRaw !== '' && mb_strlen($phoneRaw) > 40) {
                $profileErrors[] = 'Phone is too long (max 40 characters).';
            }

            if (!$profileErrors) {
                try {
                    $pdo->beginTransaction();

                    $u = $pdo->prepare('UPDATE users SET name = :n, gender = :g WHERE id = :uid');
                    $u->execute([':n' => $name, ':g' => $genderNorm, ':uid' => $userId]);

                    $sUp = $pdo->prepare(
                        'UPDATE students SET department = :d, phone = :p, gender = :sg WHERE user_id = :uid'
                    );
                    $sUp->execute([
                        ':d' => $department,
                        ':p' => $phoneRaw === '' ? null : $phoneRaw,
                        ':sg' => $genderNorm,
                        ':uid' => $userId,
                    ]);

                    // Avatar (optional upload)
                    if (!empty($_FILES['photo']) && is_array($_FILES['photo'])) {
                        $saved = student_avatar_save_upload($_FILES['photo'], $userId);
                        if ($saved['ok'] === true && !empty($saved['basename'])) {
                            $old = isset($student['avatar_path']) ? (string)$student['avatar_path'] : '';
                            $avStmt = $pdo->prepare(
                                'UPDATE students SET avatar_path = :p WHERE user_id = :uid'
                            );
                            $avStmt->execute([
                                ':p' => $saved['basename'],
                                ':uid' => $userId,
                            ]);
                            if ($old !== '') {
                                student_avatar_delete($old);
                            }
                        } elseif (($saved['error'] ?? '') !== 'missing') {
                            $profileErrors[] = (string)$saved['error'];
                        }
                    }

                    if ($profileErrors) {
                        $pdo->rollBack();
                    } else {
                        $pdo->commit();
                        $_SESSION['name'] = $name;
                        $userName = $name;
                        $student = student_get_by_user_id($pdo, $userId);
                        $profileMessages[] = 'Profile updated successfully.';
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $profileErrors[] = 'Could not save profile right now.';
                }
            }
        }
    }
}

if ($student) {
    $userName = (string)$student['name'];
}

$st = $student ?? [];
$avatarRel = isset($st['avatar_path'])
    ? student_avatar_url((string)$st['avatar_path'])
    : null;

$studentGenderDisp = strtolower((string)($st['gender'] ?? ''));
if ($studentGenderDisp !== 'male' && $studentGenderDisp !== 'female') {
    $fallbackG = strtolower((string)($st['user_gender'] ?? ''));
    $studentGenderDisp = ($fallbackG === 'male' || $fallbackG === 'female') ? $fallbackG : 'male';
}

$studentForDisplay = [
    'id' => isset($st['id']) ? (int)$st['id'] : null,
    'reg_no' => (string)($st['reg_no'] ?? ''),
    'department' => (string)($st['department'] ?? ''),
    'gender' => $studentGenderDisp,
    'phone' => (string)($st['phone'] ?? ''),
    'email' => (string)($st['email'] ?? ''),
];

$cards = [
    'your_allocation_status' => 'No allocation yet',
    'open_complaints' => 0,
    'pending_change_requests' => 0,
    'payments' => [
        'paid_count' => 0,
        'last_payment' => null,
    ],
];

$allocatedRoom = null;
$allocationRows = [];
$pendingApplicationsCount = 0;
$weekChartLabels = [];
$weekChartCounts = [];
$complaintOpenCount = 0;
$complaintTotalForDonut = 0;
$complaintDonutPct = 0;
$studentFeed = [];
$recentComplaintRows = [];

for ($wi = 6; $wi >= 0; $wi--) {
    $weekChartLabels[] = date('D', strtotime('-' . $wi . ' days'));
}
$weekChartCounts = array_fill(0, count($weekChartLabels), 0);

try {
    if (!empty($studentForDisplay['id'])) {
        $sid = $studentForDisplay['id'];

        $stmtApproved = $pdo->prepare('
            SELECT ra.id AS allocation_id,
                   h.name AS hostel_name,
                   r.room_number,
                   ra.status,
                   ra.applied_at,
                   ra.responded_at
            FROM room_allocation ra
            JOIN rooms r ON ra.room_id = r.id
            JOIN hostels h ON r.hostel_id = h.id
            WHERE ra.student_id = :sid AND ra.status = "approved"
            ORDER BY COALESCE(ra.responded_at, ra.applied_at) DESC
            LIMIT 1
        ');
        $stmtApproved->execute([':sid' => $sid]);
        $allocatedRoom = $stmtApproved->fetch();

        $cards['your_allocation_status'] = $allocatedRoom ? 'Allocated' : 'Pending / Not allocated';

        $stmtComplaints = $pdo->prepare('
            SELECT COUNT(*) FROM complaints
            WHERE student_id = :sid AND status IN ("open","in_progress")
        ');
        $stmtComplaints->execute([':sid' => $sid]);
        $cards['open_complaints'] = (int)$stmtComplaints->fetchColumn();

        $stmtChanges = $pdo->prepare('
            SELECT COUNT(*) FROM room_change_requests
            WHERE student_id = :sid AND status IN ("pending","approved")
        ');
        $stmtChanges->execute([':sid' => $sid]);
        $cards['pending_change_requests'] = (int)$stmtChanges->fetchColumn();

        $stmtPayments = $pdo->prepare('
            SELECT COUNT(*) AS cnt
            FROM payments
            WHERE student_id = :sid AND status = "paid"
        ');
        $stmtPayments->execute([':sid' => $sid]);
        $cards['payments']['paid_count'] = (int)$stmtPayments->fetchColumn();

        $stmtLastPayment = $pdo->prepare('
            SELECT amount, payment_date, method, status
            FROM payments
            WHERE student_id = :sid
            ORDER BY payment_date DESC
            LIMIT 1
        ');
        $stmtLastPayment->execute([':sid' => $sid]);
        $cards['payments']['last_payment'] = $stmtLastPayment->fetch();

        $studentNameForApp = (string)($student['name'] ?? '');
        $stmtAllocations = $pdo->prepare(
            '
            SELECT sa.id AS application_id,
                   sa.allocation_status AS status,
                   sa.created_at AS applied_at,
                   ua.allocated_at AS responded_at,
                   ur.hostel_name,
                   ur.id AS room_id,
                   uw.position AS waitlist_position
            FROM student_applications sa
            LEFT JOIN uaf_allotments ua ON ua.student_application_id = sa.id
            LEFT JOIN uaf_hostel_rooms ur ON ur.id = ua.room_id
            LEFT JOIN uaf_waitlist uw ON uw.student_application_id = sa.id
            WHERE sa.student_name = :student_name
            ORDER BY sa.id DESC
            LIMIT 8
        '
        );
        $stmtAllocations->execute([':student_name' => $studentNameForApp]);
        $allocationRows = $stmtAllocations->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtPendingApps = $pdo->prepare(
            'SELECT COUNT(*) FROM student_applications WHERE student_name = :student_name AND allocation_status = "pending"'
        );
        $stmtPendingApps->execute([':student_name' => $studentNameForApp]);
        $pendingApplicationsCount = (int)$stmtPendingApps->fetchColumn();

        $weekKeys = [];
        for ($wi = 6; $wi >= 0; $wi--) {
            $weekKeys[] = date('Y-m-d', strtotime('-' . $wi . ' days'));
        }
        $dayActivity = array_fill_keys($weekKeys, 0);

        $stmtAllocWeek = $pdo->prepare(
            '
            SELECT DATE(applied_at) AS d, COUNT(*) AS c
            FROM room_allocation
            WHERE student_id = :sid
              AND applied_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(applied_at)
        '
        );
        $stmtAllocWeek->execute([':sid' => $sid]);
        foreach ($stmtAllocWeek->fetchAll(PDO::FETCH_ASSOC) as $wr) {
            $d = (string)$wr['d'];
            if (array_key_exists($d, $dayActivity)) {
                $dayActivity[$d] += (int)$wr['c'];
            }
        }

        $stmtCompWeek = $pdo->prepare(
            '
            SELECT DATE(created_at) AS d, COUNT(*) AS c
            FROM complaints
            WHERE student_id = :sid
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(created_at)
        '
        );
        $stmtCompWeek->execute([':sid' => $sid]);
        foreach ($stmtCompWeek->fetchAll(PDO::FETCH_ASSOC) as $wr) {
            $d = (string)$wr['d'];
            if (array_key_exists($d, $dayActivity)) {
                $dayActivity[$d] += (int)$wr['c'];
            }
        }
        $weekChartCounts = array_values($dayActivity);

        $stmtCompStatus = $pdo->prepare(
            'SELECT status, COUNT(*) AS c FROM complaints WHERE student_id = :sid GROUP BY status'
        );
        $stmtCompStatus->execute([':sid' => $sid]);
        $openish = 0;
        $resolvedish = 0;
        foreach ($stmtCompStatus->fetchAll(PDO::FETCH_ASSOC) as $cs) {
            $st = strtolower((string)$cs['status']);
            $n = (int)$cs['c'];
            if ($st === 'open' || $st === 'in_progress') {
                $openish += $n;
            } else {
                $resolvedish += $n;
            }
        }
        $complaintOpenCount = $openish;
        $complaintTotalForDonut = $openish + $resolvedish;
        $complaintDonutPct = $complaintTotalForDonut > 0 ? (int)round(100 * $openish / $complaintTotalForDonut) : 0;

        $stmtRecentComplaints = $pdo->prepare(
            '
            SELECT title, status, created_at
            FROM complaints
            WHERE student_id = :sid
            ORDER BY created_at DESC
            LIMIT 6
        '
        );
        $stmtRecentComplaints->execute([':sid' => $sid]);
        $recentComplaintRows = $stmtRecentComplaints->fetchAll(PDO::FETCH_ASSOC);

        $feedTs = static function (string $raw): int {
            $t = strtotime($raw);
            return $t !== false ? $t : 0;
        };

        foreach ($allocationRows as $ar) {
            $st = strtolower((string)$ar['status']);
            $ts = $feedTs((string)($st === 'pending' ? $ar['applied_at'] : ($ar['responded_at'] ?: $ar['applied_at'])));
            $line = $st === 'pending'
                ? 'Application pending review for ' . $ar['hostel_name'] . ' — room ' . $ar['room_number'] . '.'
                : 'Allocation ' . $st . ' • ' . $ar['hostel_name'] . ' room ' . $ar['room_number'] . '.';
            $studentFeed[] = ['ts' => $ts, 'label' => $line, 'badge' => ucfirst($st)];
        }
        foreach ($recentComplaintRows as $cr) {
            $ts = $feedTs((string)$cr['created_at']);
            $studentFeed[] = [
                'ts' => $ts,
                'label' => 'Complaint: ' . (string)$cr['title'],
                'badge' => ucfirst((string)$cr['status']),
            ];
        }
        usort(
            $studentFeed,
            static function (array $a, array $b): int {
                return $b['ts'] <=> $a['ts'];
            }
        );
        $studentFeed = array_slice($studentFeed, 0, 8);
    }
} catch (Throwable $e) {
}

$b = base_path();

$publicNotices = notice_table_ready($pdo) ? notice_list_public($pdo, 6) : [];

// Prepend published notices to the activity feed
foreach (array_reverse($publicNotices) as $pn) {
    $pubTs = strtotime((string)($pn['published_at'] ?? '')) ?: 0;
    $studentFeed[] = [
        'ts' => $pubTs,
        'label' => (string)$pn['title'],
        'badge' => ucfirst((string)$pn['category']),
        'is_notice' => true,
    ];
}
if ($publicNotices !== []) {
    usort(
        $studentFeed,
        static function (array $a, array $b): int {
            return $b['ts'] <=> $a['ts'];
        }
    );
    $studentFeed = array_slice($studentFeed, 0, 10);
}

$studentInitial = mb_strtoupper(
    mb_substr($userName !== '' ? $userName : 'S', 0, 1, 'UTF-8'),
    'UTF-8'
);

$greenPalette = ['#1B5E20', '#2E7D32', '#43A047', '#66BB6A', '#81C784'];

function dash_status_class(string $status): string
{
    $status = strtolower($status);
    if ($status === 'pending') return 'dash-badge dash-badge-warning';
    if ($status === 'allocated' || $status === 'approved') return 'dash-badge dash-badge-success';
    if ($status === 'waitlisted') return 'dash-badge' ;
    if ($status === 'ineligible') return 'dash-badge dash-badge-danger';
    return 'dash-badge dash-badge-danger';
}
?>

<?php require_once __DIR__ . '/../includes/layout/header.php'; ?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="student-dash-shell-inner">
<div class="student-dash-topbar">
    <button class="btn btn-student-dash-icon d-inline-flex d-lg-none flex-shrink-0" type="button" onclick="toggleDashboardSidebar()" aria-label="Menu">
        <i class="bi bi-list fs-5"></i>
    </button>
    <div class="student-dash-greet">
        Welcome back <span aria-hidden="true">👋</span>
    </div>
    <form class="student-dash-search" method="get" action="<?php echo e($b . '/student/apply.php'); ?>" role="search">
        <label class="visually-hidden" for="studentDashSearch">Search portal</label>
        <span class="student-dash-search-ic"><i class="bi bi-search"></i></span>
        <input id="studentDashSearch" type="search" name="from" autocomplete="off" placeholder="Jump to Apply — type and press Enter"/>
    </form>
    <div class="student-dash-top-actions">
        <button type="button" class="student-dash-notif" aria-label="Notifications" disabled title="No push notifications yet">
            <i class="bi bi-bell"></i><span class="student-dash-notif-dot" aria-hidden="true"></span>
        </button>
        <div class="student-dash-head-profile">
            <div class="student-dash-head-avatar">
                <?php if ($avatarRel): ?>
                    <img src="<?php echo e($b . $avatarRel); ?>" alt=""/>
                <?php else: ?>
                    <?php echo e($studentInitial); ?>
                <?php endif; ?>
            </div>
            <div class="student-dash-head-meta d-none d-sm-block">
                <div class="student-dash-head-name text-truncate"><?php echo e($userName); ?></div>
                <div class="student-dash-head-sub text-truncate"><?php echo e($studentForDisplay['reg_no'] !== '' ? $studentForDisplay['reg_no'] : $studentForDisplay['email']); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="dash-content student-dash-content">
    <?php foreach ($profileMessages as $m): ?>
        <div class="alert alert-success border-0 student-dash-alert"><?php echo e($m); ?></div>
    <?php endforeach; ?>
    <?php foreach ($profileErrors as $err): ?>
        <div class="alert alert-danger border-0 student-dash-alert"><?php echo e($err); ?></div>
    <?php endforeach; ?>

    <div class="student-dash-cards row g-3 mb-4">
        <div class="col-md-4">
            <div class="student-dash-mini-card student-dash-mini-card--room">
                <span class="student-dash-mini-ic"><i class="bi bi-door-closed"></i></span>
                <div>
                    <?php if ($allocatedRoom): ?>
                        <div class="student-dash-mini-title"><?php echo e('Room ' . (string)$allocatedRoom['room_number']); ?></div>
                        <div class="student-dash-mini-sub"><?php echo e((string)$allocatedRoom['hostel_name']); ?><?php echo $studentForDisplay['department'] !== '' ? ' · ' . e($studentForDisplay['department']) : ''; ?></div>
                    <?php else: ?>
                        <div class="student-dash-mini-title">No room yet</div>
                        <div class="student-dash-mini-sub">Submit an application when intake opens.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="student-dash-mini-card student-dash-mini-card--complaints">
                <span class="student-dash-mini-ic student-dash-mini-ic--amber"><i class="bi bi-question-circle"></i></span>
                <div>
                    <div class="student-dash-mini-title"><?php echo e((string)$cards['open_complaints']); ?> active</div>
                    <div class="student-dash-mini-sub">Complaints</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="student-dash-mini-card student-dash-mini-card--apps">
                <span class="student-dash-mini-ic student-dash-mini-ic--blue"><i class="bi bi-megaphone"></i></span>
                <div>
                    <div class="student-dash-mini-title"><?php echo e((string)$pendingApplicationsCount); ?> new</div>
                    <div class="student-dash-mini-sub">Applications</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="student-dash-panel student-dash-panel--chart h-100">
                <div class="student-dash-panel-head">
                    <h2 class="student-dash-panel-title">Portal activity</h2>
                    <span class="student-dash-chip">This week</span>
                </div>
                <p class="student-dash-panel-note">Combined allocation events and complaints you logged.</p>
                <div class="student-dash-chart-h">
                    <canvas id="studentChartWeek" aria-label="Weekly activity"></canvas>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="student-dash-panel student-dash-panel--donut mb-4">
                <h2 class="student-dash-panel-title">Complaints</h2>
                <p class="student-dash-panel-note">Share still open versus closed.</p>
                <div class="student-dash-donut-h">
                    <canvas id="studentChartComplaints" aria-label="Complaints overview"></canvas>
                </div>
            </div>
            <div class="student-dash-panel student-dash-panel--feed" id="updates">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-2 flex-wrap">
                    <h2 class="student-dash-panel-title mb-0">Recent notifications</h2>
                    <a href="<?php echo e($b . '/notices.php'); ?>" class="student-dash-link-all">All notices <i class="bi bi-chevron-right small"></i></a>
                </div>
                <ul class="student-dash-feed list-unstyled mb-0">
                <?php
                $feedDayPrev = '';
                foreach ($studentFeed as $fi) {
                    $dayLabel = $fi['ts'] > 0 ? date('j F Y', $fi['ts']) : 'Timeline';
                    if ($dayLabel !== $feedDayPrev) {
                        $feedDayPrev = $dayLabel;
                        echo '<li class="student-dash-feed-day">' . e($dayLabel) . '</li>';
                    }
                    $clock = $fi['ts'] > 0 ? date('H:i', $fi['ts']) : '—';
                    $ago = '';
                    if ($fi['ts'] > 0) {
                        $agoSec = max(0, time() - $fi['ts']);
                        if ($agoSec < 3600) {
                            $ago = (int)floor($agoSec / 60) . ' min ago';
                        } elseif ($agoSec < 86400) {
                            $ago = (int)floor($agoSec / 3600) . ' hr ago';
                        } else {
                            $ago = (int)floor($agoSec / 86400) . ' d ago';
                        }
                    }
                    echo '<li class="student-dash-feed-item">';
                    echo '<span class="student-dash-feed-time">' . e($clock) . '</span>';
                    echo '<div class="student-dash-feed-body">';
                    echo '<span class="student-dash-feed-badge">' . e($fi['badge']) . '</span>';
                    echo '<div class="student-dash-feed-msg">' . e($fi['label']) . '</div>';
                    if ($ago !== '') {
                        echo '<div class="student-dash-feed-ago">' . e($ago) . '</div>';
                    }
                    echo '</div></li>';
                }
                ?>
                <?php if ($studentFeed === []): ?>
                    <li class="student-dash-feed-empty text-muted fw-semibold py-4">Nothing new yet—you will see statuses and replies here.</li>
                <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <section class="student-dash-announcements mb-4" id="notices" aria-label="Hostel notices">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
            <div>
                <h2 class="student-dash-section-title mb-1">Hostel notices</h2>
                <p class="student-dash-panel-note mb-0">Official announcements published by the administration.</p>
            </div>
            <?php if ($publicNotices !== []): ?>
                <span class="student-dash-chip"><?php echo e((string)count($publicNotices)); ?> recent</span>
            <?php endif; ?>
        </div>
        <?php
        $noticeBoardViewAll = $b . '/notices.php';
        require __DIR__ . '/../includes/partials/notice_board.php';
        ?>
    </section>

    <details class="student-dash-panel student-dash-details" id="account-settings">
        <summary class="student-dash-acc-toggle">
            <span><i class="bi bi-person-gear me-2"></i>Account &amp; profile</span><i class="bi bi-chevron-down student-dash-chev"></i>
        </summary>
        <div class="student-dash-details-body">
            <?php if ($student): ?>
                <div class="row g-4 align-items-start">
                    <div class="col-md-auto text-center text-md-start">
                        <div class="student-dash-profile-ring mx-auto mx-md-0 mb-3">
                            <?php if ($avatarRel): ?>
                                <img src="<?php echo e($b . $avatarRel); ?>" alt=""/>
                            <?php else: ?>
                                <span class="student-dash-profile-initial"><?php echo e($studentInitial); ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="student-dash-mini-pill">Signed in securely</span>
                    </div>
                    <div class="col-md">
                        <form method="post" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>"/>
                            <input type="hidden" name="action" value="profile"/>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Full name</label>
                                    <input type="text" name="display_name" class="form-control student-dash-input" required maxlength="150"
                                           value="<?php echo e((string)$userName); ?>" autocomplete="name"/>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">University email</label>
                                    <input type="email" class="form-control student-dash-input bg-light-subtle" value="<?php echo e($studentForDisplay['email']); ?>" disabled/>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Registration number</label>
                                    <input type="text" class="form-control student-dash-input bg-light-subtle" value="<?php echo e($studentForDisplay['reg_no']); ?>" disabled/>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Department / faculty</label>
                                    <input type="text" name="department" class="form-control student-dash-input" required maxlength="150"
                                           value="<?php echo e($studentForDisplay['department']); ?>"/>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Gender</label>
                                    <select name="gender" class="form-select student-dash-input" required>
                                        <option value="male" <?php echo $studentForDisplay['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo $studentForDisplay['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Phone (optional)</label>
                                    <input type="text" name="phone" class="form-control student-dash-input" maxlength="40" placeholder="+92 …"
                                           value="<?php echo e($studentForDisplay['phone']); ?>"/>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold small">Profile picture</label>
                                    <input type="file" name="photo" class="form-control student-dash-input" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif"/>
                                    <small class="text-muted">Up to 2 MB — JPG, PNG, WebP, GIF</small>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success rounded-pill px-5 fw-bold mt-4"><i class="bi bi-check2-circle me-2"></i>Save profile</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning rounded-4 border-0">Student record not loaded—contact the administrator.</div>
            <?php endif; ?>
        </div>
    </details>

    <div class="student-dash-panel mt-4 mb-5">
        <div class="d-flex justify-content-between flex-wrap gap-2 align-items-start mb-3">
            <div>
                <h2 class="student-dash-panel-title mb-1">Allocation timeline</h2>
                <p class="student-dash-panel-note mb-0">Hostel requests and statuses</p>
            </div>
            <span class="student-dash-chip"><?php echo e((string)count($allocationRows)); ?> entries</span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0 student-dash-table">
                <thead><tr><th>Hostel</th><th>Room</th><th>Applied</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (empty($allocationRows)): ?>
                    <tr><td colspan="4" class="text-center py-5 text-muted fw-semibold">No rows yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($allocationRows as $r): ?>
                        <?php $status = (string)$r['status']; ?>
                        <tr>
                            <td class="fw-bold"><?php echo e((string)($r['hostel_name'] ?? '—')); ?></td>
                            <td class="fw-semibold"><?php echo e((string)($r['room_id'] ?? '—')); ?></td>
                            <td class="small text-muted"><?php echo e((string)$r['applied_at']); ?></td>
                            <td>
                                <?php if (strtolower($status) === 'allocated' || strtolower($status) === 'approved'): ?>
                                    <span class="dash-badge dash-badge-success">Room Allocated</span>
                                <?php elseif (strtolower($status) === 'pending'): ?>
                                    <span class="dash-badge dash-badge-warning">Under Review</span>
                                <?php elseif (strtolower($status) === 'waitlisted'): ?>
                                    <span class="badge text-bg-warning">
                                        Waitlisted — Position #<?php echo (int)($r['waitlist_position'] ?? 0); ?>
                                    </span>
                                <?php elseif (strtolower($status) === 'ineligible'): ?>
                                    <div>
                                        <span class="badge text-bg-danger">Ineligible</span>
                                        <div class="small text-danger mt-1">
                                            Please clear outstanding dues or verify UAF enrollment to be considered for allocation.
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="dash-badge dash-badge-danger"><?php echo e($status); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div><!-- student-dash-shell-inner -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  var font = "'Plus Jakarta Sans',system-ui,sans-serif";
  var greens = <?php echo json_encode($greenPalette, JSON_HEX_TAG | JSON_HEX_APOS); ?>;
  if (typeof Chart !== 'undefined' && Chart.defaults) {
    Chart.defaults.font.family = font;
    Chart.defaults.color = '#64748b';
  }

  var wEl = document.getElementById('studentChartWeek');
  if (wEl && typeof Chart !== 'undefined') {
    new Chart(wEl, {
      type: 'bar',
      data: {
        labels: <?php echo json_encode($weekChartLabels, JSON_HEX_TAG | JSON_HEX_APOS); ?>,
        datasets: [{
          label: 'Events',
          data: <?php echo json_encode($weekChartCounts ?? [], JSON_HEX_TAG | JSON_HEX_APOS); ?>,
          borderRadius: 10,
          maxBarThickness: 36,
          backgroundColor: greens[4],
        }]
      },
      options: {
        scales: {
          x: { grid: { display: false }, ticks: { font: { weight: '600' } } },
          y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(27,94,32,0.07)' }, border: { display: false } }
        },
        plugins: { legend: { display: false } },
        maintainAspectRatio: false
      }
    });
  }

  var dEl = document.getElementById('studentChartComplaints');
  if (dEl && typeof Chart !== 'undefined') {
    var tot = <?php echo (int)$complaintTotalForDonut; ?>;
    var openC = <?php echo (int)$complaintOpenCount; ?>;
    var closed = Math.max(0, tot - openC);
    var labels = ['Open', 'Closed'];
    var data = tot ? [openC, closed] : [1];
    var colors = tot ? [greens[0], '#cbd5e1'] : ['#e2e8f0'];

    new Chart(dEl, {
      type: 'doughnut',
      data: { labels: tot ? labels : ['No data'], datasets: [{ data: data, backgroundColor: colors, borderWidth: 0 }] },
      options: {
        cutout: '68%',
        maintainAspectRatio: false,
        plugins: {
          legend: { display: !!tot, position: 'bottom', labels: { usePointStyle: true, padding: 12, font: { weight: '600' } } },
        },
      },
      plugins: [{
        id: 'studentDonutPct',
        afterDraw(chart) {
          var area = chart.chartArea;
          if (!area) return;
          var ctx = chart.ctx;
          var x = (area.left + area.right) / 2;
          var y = (area.top + area.bottom) / 2;
          var pct = <?php echo (int)$complaintDonutPct; ?>;
          var txt = tot ? pct + '%' : '—';
          var line2 = tot ? 'open' : '';
          ctx.save();
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.font = '800 22px ' + font;
          ctx.fillStyle = '#0f172a';
          ctx.fillText(txt, x, y - (line2 ? 6 : 0));
          if (line2) {
            ctx.font = '600 11px ' + font;
            ctx.fillStyle = '#64748b';
            ctx.fillText(line2, x, y + 14);
          }
          ctx.restore();
        },
      }],
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
