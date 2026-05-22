<?php
declare(strict_types=1);
/**
 * @file admin/reports.php
 * @brief Reports export — CSV downloads for reporting
 * @access admin
 */

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Reports & exports';
$activePage = 'admin_reports';

$messages = [];

// ---------------- CSV STREAM ----------------
function admin_report_send_csv(string $filename, iterable $headers, iterable $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }
    fputcsv($out, [...$headers]);
    foreach ($rows as $row) {
        fputcsv($out, is_array($row) ? [...$row] : iterator_to_array($row));
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $messages[] = ['danger', 'Bad CSRF token—refresh Reports and retry.'];
    } else {
        $export = trim((string)($_POST['export'] ?? ''));
        try {
            $pdo = db();
            switch ($export) {
                case 'students':
                    $q = $pdo->query(
                        'SELECT u.name, u.email, s.reg_no, s.department, s.gender, u.gender AS user_gender, s.phone, s.created_at
                         FROM students s JOIN users u ON u.id=s.user_id ORDER BY s.id DESC'
                    );
                    admin_report_send_csv('students-export.csv', ['name','email','reg_no','department','gender_student','gender_user','phone','created'], $q->fetchAll(PDO::FETCH_NUM));
                    break;

                case 'allocations':
                    $stmt = $pdo->query(
                        'SELECT ra.id, u.name AS student_name, s.reg_no, h.name hostel, r.room_number,
                                ra.status, ra.applied_at, ra.responded_at,
                                ua.name AS responder_name
                         FROM room_allocation ra
                         JOIN students s ON s.id=ra.student_id
                         JOIN users u ON u.id=s.user_id
                         JOIN rooms r ON r.id=ra.room_id
                         JOIN hostels h ON h.id=r.hostel_id
                         LEFT JOIN users ua ON ua.id=ra.admin_user_id
                         ORDER BY ra.applied_at DESC'
                    );
                    admin_report_send_csv(
                        'room-allocations.csv',
                        ['id','student','reg_no','hostel','room','status','applied','responded','responder'],
                        $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : []
                    );
                    break;

                case 'payments':
                    $stmt = $pdo->query(
                        'SELECT p.id, u.name, s.reg_no, p.semester, p.amount, p.status, p.method, p.payment_date, p.reference
                         FROM payments p JOIN students s ON s.id=p.student_id
                         JOIN users u ON u.id=s.user_id
                         ORDER BY p.created_at DESC'
                    );
                    admin_report_send_csv(
                        'payments.csv',
                        ['id','student','reg_no','semester','amount','status','method','paid_on','reference'],
                        $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : []
                    );
                    break;

                case 'complaints':
                    $stmt = $pdo->query(
                        'SELECT c.id, s.reg_no, COALESCE(h.name, \'\') hostel,
                                  COALESCE(r.room_number, \'\') room,
                                  c.title, c.status, c.created_at
                         FROM complaints c
                         JOIN students s ON s.id=c.student_id
                         LEFT JOIN hostels h ON h.id=c.hostel_id
                         LEFT JOIN rooms r ON r.id=c.room_id
                         ORDER BY c.created_at DESC'
                    );
                    admin_report_send_csv(
                        'complaints.csv',
                        ['id','student_reg','hostel','room','title','status','created'],
                        $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : []
                    );
                    break;

                case 'occupancy':
                    $stmt = $pdo->query(
                        'SELECT h.name hostel, COUNT(r.id) total_rooms,
                                SUM(CASE WHEN r.occupied=1 THEN 1 ELSE 0 END) occupied,
                                SUM(r.capacity) total_capacity,
                                ROUND(SUM(CASE WHEN r.occupied=1 THEN 1 ELSE 0 END)*100/GREATEST(COUNT(r.id),1),2) pct_occupied_room_rows
                         FROM hostels h
                         LEFT JOIN rooms r ON r.hostel_id=h.id
                         GROUP BY h.id, h.name ORDER BY h.name ASC'
                    );
                    admin_report_send_csv(
                        'occupancy-overview.csv',
                        ['hostel','rooms','occupied','total_capacity_label','pct_occupied_approx'],
                        $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : []
                    );
                    break;

                default:
                    $messages[] = ['warning', 'Unknown export type.'];
            }
        } catch (Throwable $e) {
            $messages[] = ['danger', 'Export failed—the database connectivity or joins may still be migrating.'];
        }
    }
}

$summary = [
    ['label' => 'Students', 'count' => 0],
    ['label' => 'Pending allocations', 'count' => 0],
    ['label' => 'Approved allocations', 'count' => 0],
    ['label' => 'Open complaints', 'count' => 0],
    ['label' => 'Recorded payments rows', 'count' => 0],
];

try {
    $pdo = db();
    $summary[0]['count'] = (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
    $summary[1]['count'] = (int)$pdo->query("SELECT COUNT(*) FROM room_allocation WHERE status='pending'")->fetchColumn();
    $summary[2]['count'] = (int)$pdo->query("SELECT COUNT(*) FROM room_allocation WHERE status='approved'")->fetchColumn();
    $summary[3]['count'] = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status IN ('open','in_progress')")->fetchColumn();
    $summary[4]['count'] = (int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
} catch (Throwable $e) {
}

?>

<?php require_once __DIR__ . '/../includes/layout/header.php'; ?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="dash-topbar d-flex align-items-center justify-content-between gap-3">
    <div class="dash-mobile-toggle">
        <button class="btn btn-sm" type="button" onclick="toggleDashboardSidebar()"
                style="background:rgba(27,94,32,0.08); color:var(--dark); border:1px solid rgba(27,94,32,0.18); font-weight:900;">
            ☰
        </button>
    </div>
    <div class="title"><?php echo e($pageTitle); ?></div>
    <div style="font-size:13px; color:var(--text-muted); font-weight:800;">
        Institutional CSV summaries
    </div>
</div>

<div class="dash-content">
    <?php foreach ($messages as $tier): ?>
        <div class="alert alert-<?php echo e($tier[0]); ?>"><?php echo e($tier[1]); ?></div>
    <?php endforeach; ?>

    <div id="printArea" class="dash-card mb-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <div style="font-weight:900;color:var(--dark);">Executive printable snapshot</div>
                <div class="small text-muted" style="font-weight:650;">Operational counts at query time • print button omits sidebar chrome automatically</div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-dark" style="font-weight:800;"
                    onclick="window.print();">Print view</button>
        </div>
        <table class="table table-sm align-middle">
            <thead>
            <tr>
                <?php foreach ($summary as $s): ?>
                    <th style="border-bottom:none;font-size:13px;color:var(--text-muted);"><?php echo e($s['label']); ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <tr>
                <?php foreach ($summary as $s): ?>
                    <td style="font-weight:900;font-size:20px;color:var(--dark);"><?php echo e((string)$s['count']); ?></td>
                <?php endforeach; ?>
            </tr>
            </tbody>
        </table>
        <div class="small text-muted" style="font-weight:640;">
            Use CSV exports underneath for auditors / Excel pivot drills; printable panel above condenses KPIs instantly.
        </div>
    </div>

    <div class="row g-3">
        <?php
        $tiles = [
            ['label' => 'Students directory', 'key' => 'students', 'blurb' => 'Names, registrations & departments'],
            ['label' => 'Room allocations ledger', 'key' => 'allocations', 'blurb' => 'Pending + approved timelines'],
            ['label' => 'Financial ledger rows', 'key' => 'payments', 'blurb' => 'Semester-linked hostel dues'],
            ['label' => 'Complaints log', 'key' => 'complaints', 'blurb' => 'Maintenance & escalations timeline'],
            ['label' => 'Hostel occupancy', 'key' => 'occupancy', 'blurb' => 'Room counts aggregated per hostel block'],
        ];
        ?>
        <?php foreach ($tiles as $tile): ?>
            <div class="col-md-6 col-xl-4">
                <div class="dash-card h-100 d-flex flex-column">
                    <div style="font-weight:900;color:var(--dark);"><?php echo e($tile['label']); ?></div>
                    <p class="small text-muted mb-3" style="flex:1;font-weight:680;"><?php echo e($tile['blurb']); ?></p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="export" value="<?php echo e($tile['key']); ?>">
                        <button type="submit" class="btn btn-primary w-100"
                                style="background:var(--dark);border:none;font-weight:800;">
                            Download CSV
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<style media="print">
    .dash-sidebar, .dash-topbar, .dash-overlay, aside, nav ~ .dash-overlay {display:none!important;}
    .dash-main{padding:20px!important;}
</style>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
