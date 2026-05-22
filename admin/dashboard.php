<?php
declare(strict_types=1);
/**
 * @file admin/dashboard.php
 * @brief Admin home — KPIs and Chart.js analytics
 * @access admin
 * @database room_allocation, complaints, rooms
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/models/smart_allocation_model.php';
require_role('admin');

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Administrator Dashboard';
$activePage = 'admin_dashboard';

// Reference-style dashboard shell (analytics cards + top bar); sidebar uses green theme
$dashShellClass = 'admin-shell-ref';
$bodyClass = trim((string)($bodyClass ?? '') . ' page-admin-dash');

$kpis = [
    'total_students' => 0,
    'pending_applications' => 0,
    'open_complaints' => 0,
    'available_rooms' => 0,
    'total_hostels' => 0,
    'total_rooms' => 0,
    'active_wardens' => 0,
    'occupied_rooms' => 0,
    'empty_beds' => 0,
    'waitlist' => 0,
];

$smartBedMetrics = false;
$bedChart = ['occupied' => 0, 'free' => 0];

$allocPie = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$applicationsByDayLabels = [];
$applicationsByDayCounts = [];
$applicationsByDayLabelsFmt = [];
$deptLabels = [];
$deptCounts = [];

try {
    $pdo = db();

    $kpis['total_students'] = (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
    $kpis['pending_applications'] = (int)$pdo->query('SELECT COUNT(*) FROM room_allocation WHERE status = \'pending\'')->fetchColumn();
    $kpis['open_complaints'] = (int)$pdo->query(
        'SELECT COUNT(*) FROM complaints WHERE status IN (\'open\',\'in_progress\')'
    )->fetchColumn();
    $kpis['total_hostels'] = (int)$pdo->query('SELECT COUNT(*) FROM hostels')->fetchColumn();
    $kpis['total_rooms'] = (int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();

    if (smart_allocation_tables_ready($pdo)) {
        $smartBedMetrics = true;
        $kpis['occupied_rooms'] = (int)$pdo->query('SELECT COUNT(*) FROM rooms WHERE occupied > 0')->fetchColumn();
        $kpis['empty_beds'] = smart_allocation_total_empty_beds($pdo);
        $kpis['available_rooms'] = (int)$pdo->query(
            'SELECT COUNT(*) FROM rooms WHERE GREATEST(capacity - occupied, 0) > 0'
        )->fetchColumn();
        try {
            $kpis['waitlist'] = (int)$pdo->query(
                'SELECT COUNT(*) FROM allocation_waiting_list WHERE status = \'queued\''
            )->fetchColumn();
        } catch (Throwable $e) {
            $kpis['waitlist'] = 0;
        }
        $sumRow = $pdo->query('SELECT COALESCE(SUM(capacity),0), COALESCE(SUM(occupied),0) FROM rooms')->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $capSum = (int)$sumRow[0];
        $occSum = (int)$sumRow[1];
        $bedChart['occupied'] = $occSum;
        $bedChart['free'] = max($capSum - $occSum, 0);
    } else {
        $kpis['available_rooms'] = (int)$pdo->query('SELECT COUNT(*) FROM rooms WHERE occupied = 0')->fetchColumn();
    }

    try {
        $kpis['active_wardens'] = (int)$pdo->query(
            'SELECT COUNT(*) FROM warden_assignments WHERE is_active = 1'
        )->fetchColumn();
    } catch (Throwable $e) {
        $kpis['active_wardens'] = 0;
    }

    $stPie = $pdo->query('SELECT status, COUNT(*) AS c FROM room_allocation GROUP BY status');
    foreach ($stPie->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $s = (string)$row['status'];
        if (isset($allocPie[$s])) {
            $allocPie[$s] = (int)$row['c'];
        }
    }

    $labels = [];
    for ($i = 13; $i >= 0; $i--) {
        $labels[] = date('Y-m-d', strtotime('-' . $i . ' days'));
    }
    $applicationsByDayLabels = $labels;
    $dayMap = array_fill_keys($labels, 0);
    $stmtTrend = $pdo->query(
        '
        SELECT DATE(applied_at) AS d, COUNT(*) AS c
        FROM room_allocation
        WHERE applied_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY DATE(applied_at)
        ORDER BY d ASC'
    );
    foreach ($stmtTrend->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = (string)$row['d'];
        if (array_key_exists($d, $dayMap)) {
            $dayMap[$d] = (int)$row['c'];
        }
    }
    foreach ($labels as $lb) {
        $applicationsByDayCounts[] = $dayMap[$lb];
    }
    $applicationsByDayLabelsFmt = array_map(static function (string $d): string {
        $ts = strtotime($d);
        return $ts ? date('j M', $ts) : $d;
    }, $applicationsByDayLabels);

    $stmtDept = $pdo->query(
        '
        SELECT department, COUNT(*) AS c
        FROM students
        GROUP BY department
        ORDER BY c DESC
        LIMIT 8
    '
    );
    foreach ($stmtDept->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $deptLabels[] = (string)$row['department'];
        $deptCounts[] = (int)$row['c'];
    }
    if ($deptLabels === []) {
        $deptLabels = ['—'];
        $deptCounts = [0];
    }
} catch (Throwable $e) {
}

$b = base_path();

function admin_initial(string $name): string
{
    $n = trim($name);
    $ch = mb_substr($n !== '' ? $n : 'A', 0, 1, 'UTF-8');
    return mb_strtoupper($ch, 'UTF-8');
}

$adminIni = admin_initial($userName);

$chartPalette = ['#1B5E20', '#2E7D32', '#388E3C', '#43A047', '#4CAF50', '#66BB6A', '#81C784', '#A5D6A7'];
$deptBarColors = array_slice($chartPalette, 0, max(count($deptLabels), 1));
if ($deptBarColors === []) {
    $deptBarColors = [$chartPalette[0]];
}

$allocPieSum = (int)$allocPie['pending'] + (int)$allocPie['approved'] + (int)$allocPie['rejected'];
?>

<?php require_once __DIR__ . '/../includes/layout/header.php'; ?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<!-- Top bar: title + profile (reference style) -->
<div class="admin-ref-topbar">
    <div class="d-flex align-items-center gap-2 flex-grow-1 min-w-0 me-2">
        <button class="btn btn-icon-modern d-inline-flex flex-shrink-0 d-lg-none" type="button" onclick="toggleDashboardSidebar()" aria-label="Menu">
            <i class="bi bi-list fs-5"></i>
        </button>
        <i class="bi bi-house-door-fill text-success d-none d-sm-inline flex-shrink-0"></i>
        <span class="admin-ref-topbar-title text-truncate"><?php echo e($pageTitle); ?></span>
    </div>
    <div class="admin-ref-topbar-profile flex-shrink-0" title="<?php echo e($userName); ?>">
        <span class="admin-ref-avatar"><?php echo e($adminIni); ?></span>
        <div class="admin-ref-profile-text d-none d-md-block">
            <div class="admin-ref-profile-name"><?php echo e($userName); ?></div>
            <div class="admin-ref-profile-role">Administrator</div>
        </div>
    </div>
</div>

<div class="dash-content admin-ref-content">
    <!-- Hero -->
    <div class="admin-ref-welcome mb-4">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <p class="admin-ref-welcome-kicker mb-2">Administrator overview</p>
                <h1 class="admin-ref-welcome-title">Welcome back, <?php echo e($userName); ?></h1>
                <p class="admin-ref-welcome-text mb-0">
                    Capacity, allocations, complaints, and warden assignments at a glance. Use the KPIs and analytics below to prioritize approvals and day-to-day hostel operations.
                </p>
            </div>
            <div class="col-lg-4">
                <div class="admin-ref-welcome-visual" aria-hidden="true">
                    <span class="admin-ref-welcome-iconwrap"><i class="bi bi-building-check"></i></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat grid 2×3 -->
    <div class="row g-3 mb-4 admin-ref-stat-grid">
        <div class="col-sm-6 col-xl-4">
            <div class="admin-ref-stat-card">
                <span class="admin-ref-stat-icon admin-ref-stat-icon--1"><i class="bi bi-building"></i></span>
                <div class="admin-ref-stat-meta">
                    <div class="admin-ref-stat-name">Hostel inventory</div>
                    <div class="admin-ref-stat-value"><?php echo e((string)$kpis['total_hostels']); ?></div>
                    <div class="admin-ref-stat-note"><span class="admin-ref-badge">Active blocks</span></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-4">
            <div class="admin-ref-stat-card">
                <span class="admin-ref-stat-icon admin-ref-stat-icon--2"><i class="bi bi-people-fill"></i></span>
                <div class="admin-ref-stat-meta">
                    <div class="admin-ref-stat-name">Registered students</div>
                    <div class="admin-ref-stat-value"><?php echo e((string)$kpis['total_students']); ?></div>
                    <div class="admin-ref-stat-note"><span class="admin-ref-badge admin-ref-badge--muted">On portal</span></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-4">
            <div class="admin-ref-stat-card">
                <span class="admin-ref-stat-icon admin-ref-stat-icon--3"><i class="bi bi-hourglass-split"></i></span>
                <div class="admin-ref-stat-meta">
                    <div class="admin-ref-stat-name">Pending applications</div>
                    <div class="admin-ref-stat-value"><?php echo e((string)$kpis['pending_applications']); ?></div>
                    <div class="admin-ref-stat-note"><span class="admin-ref-badge admin-ref-badge--warn">Awaiting approval</span></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-4">
            <div class="admin-ref-stat-card">
                <span class="admin-ref-stat-icon admin-ref-stat-icon--4"><i class="bi bi-door-closed"></i></span>
                <div class="admin-ref-stat-meta">
                    <div class="admin-ref-stat-name"><?php echo $smartBedMetrics ? 'Room inventory (bed-based)' : 'Rooms &amp; capacity'; ?></div>
                    <div class="admin-ref-stat-value"><?php echo e((string)$kpis['total_rooms']); ?></div>
                    <div class="admin-ref-stat-note"><span class="admin-ref-badge">
                        <?php if ($smartBedMetrics): ?>
                            <?php echo e((string)$kpis['empty_beds']); ?> empty berths · <?php echo e((string)$kpis['occupied_rooms']); ?> active rooms
                        <?php else: ?>
                            <?php echo e((string)$kpis['available_rooms']); ?> fully vacant rooms
                        <?php endif; ?>
                    </span></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-4">
            <div class="admin-ref-stat-card">
                <span class="admin-ref-stat-icon admin-ref-stat-icon--5"><i class="bi bi-megaphone-fill"></i></span>
                <div class="admin-ref-stat-meta">
                    <div class="admin-ref-stat-name">Complaints queue</div>
                    <div class="admin-ref-stat-value"><?php echo e((string)$kpis['open_complaints']); ?></div>
                    <div class="admin-ref-stat-note"><span class="admin-ref-badge admin-ref-badge--muted">Open / in progress</span></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-4">
            <div class="admin-ref-stat-card">
                <span class="admin-ref-stat-icon admin-ref-stat-icon--6"><i class="bi bi-<?php echo $smartBedMetrics ? 'hourglass-bottom' : 'person-badge-fill'; ?>"></i></span>
                <div class="admin-ref-stat-meta">
                    <div class="admin-ref-stat-name"><?php echo $smartBedMetrics ? 'Smart waitlist' : 'Active wardens'; ?></div>
                    <div class="admin-ref-stat-value"><?php echo $smartBedMetrics ? e((string)$kpis['waitlist']) : e((string)$kpis['active_wardens']); ?></div>
                    <div class="admin-ref-stat-note"><span class="admin-ref-badge"><?php echo $smartBedMetrics ? 'Students queued for auto-assign' : 'Allocated by admin'; ?></span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <section class="admin-ref-charts mb-4" aria-label="Analytics">
        <div class="row g-4 align-items-stretch admin-ref-chart-row">
            <div class="col-lg-5 d-flex">
                <div class="admin-ref-chart-card w-100 d-flex flex-column">
                    <div class="admin-ref-chart-intro">
                        <h2 class="admin-ref-chart-head">Allocation outcomes</h2>
                        <p class="admin-ref-chart-desc">Room requests by decision status.</p>
                        <?php if ($allocPieSum < 1): ?>
                            <p class="admin-ref-chart-placeholder mb-0">No submissions yet—the chart updates when allocation requests exist.</p>
                        <?php endif; ?>
                    </div>
                    <div class="admin-ref-chart-canvas-wrap admin-ref-chart-canvas-wrap--pie">
                        <canvas id="chartAllocPie" aria-label="Allocation distribution"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-7 d-flex">
                <div class="admin-ref-chart-card w-100 d-flex flex-column">
                    <div class="admin-ref-chart-intro">
                        <h2 class="admin-ref-chart-head">Application activity</h2>
                        <p class="admin-ref-chart-desc">New allocation requests per day (last 14 days).</p>
                    </div>
                    <div class="admin-ref-chart-canvas-wrap admin-ref-chart-canvas-wrap--line">
                        <canvas id="chartAppLine" aria-label="Applications trend"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-4 mt-0 mb-4">
            <div class="col-12">
                <div class="admin-ref-chart-card d-flex flex-column">
                    <div class="admin-ref-chart-intro admin-ref-chart-intro--split">
                        <div>
                            <h2 class="admin-ref-chart-head">Students by department</h2>
                            <p class="admin-ref-chart-desc mb-0">Ranked headcount by department on record in the portal.</p>
                        </div>
                    </div>
                    <div class="admin-ref-chart-canvas-wrap admin-ref-chart-canvas-wrap--bar">
                        <canvas id="chartDeptBar" aria-label="Department distribution"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($smartBedMetrics): ?>
            <div class="row g-4 mt-0 mb-4">
                <div class="col-lg-5 d-flex">
                    <div class="admin-ref-chart-card w-100 d-flex flex-column">
                        <div class="admin-ref-chart-intro">
                            <h2 class="admin-ref-chart-head">Bed utilization</h2>
                            <p class="admin-ref-chart-desc">Filled vs empty berths across all inventory rows.</p>
                            <?php $bedUtot = (int)$bedChart['occupied'] + (int)$bedChart['free']; ?>
                            <?php if ($bedUtot < 1): ?>
                                <p class="admin-ref-chart-placeholder mb-0">No beds defined yet.</p>
                            <?php endif; ?>
                        </div>
                        <div class="admin-ref-chart-canvas-wrap admin-ref-chart-canvas-wrap--pie">
                            <canvas id="chartBedPie" aria-label="Bed utilization"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <!-- Quick reports + table -->
    <div class="admin-ref-chart-card admin-ref-cta-bar mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="admin-ref-chart-intro mb-0">
                <h2 class="admin-ref-chart-head mb-1">Exports</h2>
                <p class="admin-ref-chart-desc mb-0">Comma-separated downloads for reconciliation and audits.</p>
            </div>
            <a class="btn dash-btn-primary rounded-pill px-4 fw-bold" href="<?php echo e($b . '/admin/reports.php'); ?>">
                <i class="bi bi-download me-2"></i>Open reports
            </a>
        </div>
    </div>

    <div class="admin-ref-table-card">
        <div class="admin-ref-table-head">
            <span>Pending applications</span>
            <a class="btn btn-sm btn-outline-success rounded-pill fw-bold" href="<?php echo e($b . '/admin/applications.php'); ?>">Open queue</a>
        </div>
        <div class="table-responsive">
            <table class="table align-middle admin-ref-table mb-0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Department</th>
                        <th>Hostel</th>
                        <th>Room</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $rows = [];
                try {
                    $pdo = db();
                    $stmt = $pdo->prepare('
                        SELECT s.reg_no,
                               s.department,
                               h.name AS hostel_name,
                               r.room_number,
                               ra.status
                        FROM room_allocation ra
                        JOIN students s ON ra.student_id = s.id
                        JOIN rooms r ON ra.room_id = r.id
                        JOIN hostels h ON r.hostel_id = h.id
                        WHERE ra.status = \'pending\'
                        ORDER BY ra.applied_at DESC
                        LIMIT 10
                    ');
                    $stmt->execute();
                    $rows = $stmt->fetchAll();
                } catch (Throwable $e) {
                    $rows = [];
                }

                if (empty($rows)) {
                    echo '<tr><td colspan="5" class="text-center py-5 text-muted fw-semibold">No pending items right now.</td></tr>';
                } else {
                    foreach ($rows as $r) {
                        echo '<tr>';
                        echo '<td class="fw-bold">' . e((string)$r['reg_no']) . '</td>';
                        echo '<td class="text-muted small">' . e((string)$r['department']) . '</td>';
                        echo '<td>' . e((string)$r['hostel_name']) . '</td>';
                        echo '<td class="fw-bold">' . e((string)$r['room_number']) . '</td>';
                        echo '<td><span class="admin-ref-pill">' . e((string)$r['status']) . '</span></td>';
                        echo '</tr>';
                    }
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  var fontFamily = "'Plus Jakarta Sans', system-ui, sans-serif";
  if (typeof Chart !== 'undefined' && Chart.defaults) {
    Chart.defaults.font.family = fontFamily;
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#64748b';
  }

  var greens = <?php echo json_encode($chartPalette, JSON_HEX_TAG | JSON_HEX_APOS); ?>;
  var gridLine = 'rgba(27, 94, 32, 0.08)';
  var gridLineBold = 'rgba(27, 94, 32, 0.12)';

  var pieEl = document.getElementById('chartAllocPie');
  if (pieEl) {
    var allocSum = <?php echo (int)$allocPieSum; ?>;
    var pieLabels = allocSum ? ['Pending', 'Approved', 'Rejected'] : ['No allocation records yet'];
    var pieVals = allocSum ? [
      <?php echo (int)$allocPie['pending']; ?>,
      <?php echo (int)$allocPie['approved']; ?>,
      <?php echo (int)$allocPie['rejected']; ?>
    ] : [1];
    var pieColors = allocSum ? [greens[6], greens[3], greens[0]] : ['rgba(148,163,184,0.55)'];

    new Chart(pieEl, {
      type: 'doughnut',
      data: {
        labels: pieLabels,
        datasets: [{
          data: pieVals,
          backgroundColor: pieColors,
          borderWidth: 0,
          hoverOffset: 4
        }]
      },
      options: {
        layout: { padding: { top: 4, bottom: 8 } },
        plugins: {
          legend: {
            display: !!allocSum,
            position: 'bottom',
            align: 'center',
            labels: {
              usePointStyle: true,
              padding: 18,
              boxWidth: 8,
              font: { family: fontFamily, size: 11, weight: '600' }
            }
          }
        },
        cutout: '62%',
        maintainAspectRatio: false
      }
    });
  }

  var lineEl = document.getElementById('chartAppLine');
  if (lineEl) {
    new Chart(lineEl, {
      type: 'line',
      data: {
        labels: <?php echo json_encode($applicationsByDayLabelsFmt ?? [], JSON_HEX_TAG | JSON_HEX_APOS); ?>,
        datasets: [{
          label: 'Applications',
          data: <?php echo json_encode($applicationsByDayCounts ?? [], JSON_HEX_TAG | JSON_HEX_APOS); ?>,
          borderColor: greens[4],
          borderWidth: 2,
          backgroundColor: 'rgba(76, 175, 80, 0.08)',
          fill: true,
          tension: 0.38,
          pointRadius: 0,
          pointHoverRadius: 5,
          pointBackgroundColor: '#fff',
          pointBorderWidth: 2,
          pointBorderColor: greens[2]
        }]
      },
      options: {
        interaction: { intersect: false, mode: 'index' },
        scales: {
          y: {
            beginAtZero: true,
            border: { display: false },
            ticks: { precision: 0, padding: 8, font: { weight: '600' }, color: '#64748b' },
            grid: { color: gridLine, drawTicks: false }
          },
          x: {
            border: { display: false },
            grid: { display: false },
            ticks: { maxRotation: 0, padding: 6, font: { weight: '600' }, color: '#64748b' }
          }
        },
        plugins: { legend: { display: false }, tooltip: { displayColors: false } },
        maintainAspectRatio: false
      }
    });
  }

  var barEl = document.getElementById('chartDeptBar');
  if (barEl) {
    new Chart(barEl, {
      type: 'bar',
      data: {
        labels: <?php echo json_encode($deptLabels ?? [], JSON_HEX_TAG | JSON_HEX_APOS); ?>,
        datasets: [{
          label: 'Students',
          data: <?php echo json_encode($deptCounts ?? [], JSON_HEX_TAG | JSON_HEX_APOS); ?>,
          backgroundColor: <?php echo json_encode($deptBarColors, JSON_HEX_TAG | JSON_HEX_APOS); ?>,
          borderRadius: 6,
          maxBarThickness: 22,
          categoryPercentage: 0.74,
          barPercentage: 0.9
        }]
      },
      options: {
        indexAxis: 'y',
        layout: { padding: { left: 0, right: 12, top: 4, bottom: 4 } },
        scales: {
          x: {
            beginAtZero: true,
            border: { display: false },
            ticks: { precision: 0, padding: 6, font: { weight: '600' }, color: '#64748b' },
            grid: { color: gridLineBold, tickLength: 0 }
          },
          y: {
            border: { display: false },
            ticks: {
              mirror: false,
              autoSkipPadding: 12,
              font: { size: 11, weight: '600' },
              color: '#334155'
            },
            grid: { display: false }
          }
        },
        plugins: { legend: { display: false } },
        maintainAspectRatio: false
      }
    });
  }

  var bedEl = document.getElementById('chartBedPie');
  if (bedEl) {
    var bo = <?php echo (int)($bedChart['occupied'] ?? 0); ?>;
    var bf = <?php echo (int)($bedChart['free'] ?? 0); ?>;
    var bedSum = bo + bf;
    var bedLabels = bedSum ? ['Occupied berths', 'Empty berths'] : ['No capacity configured yet'];
    var bedVals = bedSum ? [bo, bf] : [1];
    var bedColors = bedSum ? [greens[2], greens[6]] : ['rgba(148,163,184,0.55)'];
    new Chart(bedEl, {
      type: 'doughnut',
      data: {
        labels: bedLabels,
        datasets: [{
          data: bedVals,
          backgroundColor: bedColors,
          borderWidth: 0,
          hoverOffset: 4
        }]
      },
      options: {
        layout: { padding: { top: 4, bottom: 8 } },
        plugins: {
          legend: {
            display: !!bedSum,
            position: 'bottom',
            align: 'center',
            labels: {
              usePointStyle: true,
              padding: 18,
              boxWidth: 8,
              font: { family: fontFamily, size: 11, weight: '600' }
            }
          }
        },
        cutout: '62%',
        maintainAspectRatio: false
      }
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
