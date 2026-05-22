<?php
declare(strict_types=1);
/**
 * @file admin/smart_allocation.php
 * @brief Smart allocation UI — AI-ranked rooms and auto-assign beds
 * @access admin
 * @database allocations, beds
 */

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

require_once __DIR__ . '/../includes/models/smart_allocation_model.php';
require_once __DIR__ . '/../includes/services/recommendation_service.php';

$userName = (string)($_SESSION['name'] ?? 'Admin');
$userRole = (string)($_SESSION['role'] ?? 'admin');
$pageTitle = 'Smart Allocation';
$activePage = 'admin_smart_allocation';

$errors = [];
$messages = [];
$migrationMissing = false;
$pdo = db();
$migrationMissing = !smart_allocation_tables_ready($pdo);
$aiEngineLabel = hostel_recommend_engine_in_use();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$migrationMissing) {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid CSRF token. Please refresh the page.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'auto_assign_best') {
                $sid = (int)($_POST['student_id'] ?? 0);
                if ($sid <= 0) {
                    $errors[] = 'Select a student first.';
                } else {
                    $res = smart_allocation_try_auto_assign_best($pdo, $sid, (int)$_SESSION['user_id'], 'Admin smart auto-assign');
                    if ($res['ok']) {
                        $lbl = isset($res['room']) ? ' → ' . (string)$res['room'] : '';
                        $messages[] = $res['message'] . $lbl;
                    } else {
                        $errors[] = $res['message'];
                    }
                }
            } elseif ($action === 'assign_specific') {
                $sid = (int)($_POST['student_id'] ?? 0);
                $rid = (int)($_POST['room_id'] ?? 0);
                if ($sid <= 0 || $rid <= 0) {
                    $errors[] = 'Student and room identifiers are required.';
                } else {
                    $assign = smart_allocation_assign_student_to_room($pdo, $sid, $rid, (int)$_SESSION['user_id'], 'Admin manual modal assign');
                    if ($assign['ok']) {
                        $messages[] = $assign['message'];
                    } else {
                        $errors[] = $assign['message'];
                    }
                }
            } elseif ($action === 'enqueue_wait') {
                $sid = (int)($_POST['student_id'] ?? 0);
                $hid = (int)($_POST['hostel_id'] ?? 0);
                if ($sid <= 0) {
                    $errors[] = 'Student identifier missing.';
                } else {
                    $hostelPref = $hid > 0 ? $hid : null;
                    $q = smart_allocation_enqueue($pdo, $sid, $hostelPref);
                    if ($q['ok']) {
                        $messages[] = $q['message'];
                    } else {
                        $errors[] = $q['message'];
                    }
                }
            } elseif ($action === 'process_waiting') {
                $batch = smart_allocation_process_waiting_fifo($pdo, (int)$_SESSION['user_id']);
                $messages[] = 'Waiting list sweep completed — assigned ' . (string)$batch['assigned'] . ' student(s).';
                foreach ($batch['failures'] as $f) {
                    $errors[] = $f;
                }
            } else {
                $errors[] = 'Unknown submission action.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Operation failed unexpectedly.';
        }
    }
}

$students = [];
$waiting = [];
try {
    if (!$migrationMissing) {
        $stmt = $pdo->query(
            '
            SELECT s.id AS student_id,
                   u.name,
                   s.reg_no,
                   s.gender AS student_record_gender,
                   s.department,
                   COALESCE(NULLIF(TRIM(s.semester), \'\'), \'\') AS semester_label,
                   s.room_preference,
                   s.fee_status,
                   a.id AS active_allocation_id
            FROM students s
            INNER JOIN users u ON u.id = s.user_id
            LEFT JOIN allocations a ON a.student_id = s.id
            ORDER BY u.name ASC
        ');
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $waitStmt = $pdo->query(
            '
            SELECT w.id,
                   u.name,
                   s.reg_no,
                   h.name AS hostel_name,
                   w.requested_at
            FROM allocation_waiting_list w
            INNER JOIN students s ON s.id = w.student_id
            INNER JOIN users u ON u.id = s.user_id
            LEFT JOIN hostels h ON h.id = w.hostel_id
            WHERE w.status = \'queued\'
            ORDER BY w.priority DESC, w.requested_at ASC
        ');
        $waiting = $waitStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $students = [];
    $waiting = [];
    $migrationMissing = true;
}

$b = base_path();

require_once __DIR__ . '/../includes/layout/header.php';
?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="dash-topbar d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <div class="dash-mobile-toggle">
        <button class="btn btn-sm" type="button" onclick="toggleDashboardSidebar()"
                style="background:rgba(27,94,32,0.08); color:var(--dark); border:1px solid rgba(27,94,32,0.18); font-weight:900;">
            ☰
        </button>
    </div>
    <div class="title"><?php echo e($pageTitle); ?></div>
    <form method="post" class="d-flex align-items-center gap-2 mb-0">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="process_waiting">
        <button type="submit" class="btn btn-sm btn-success rounded-pill fw-bold px-3" <?php echo $migrationMissing ? 'disabled' : ''; ?>>
            <i class="bi bi-play-circle me-1"></i>Process waiting list (auto)
        </button>
    </form>
</div>

<div class="dash-content">
    <?php if ($migrationMissing): ?>
        <div class="alert alert-danger">
            <strong>Database migration pending.</strong> Import <code>database/smart_allocation_schema.sql</code> via phpMyAdmin, then refresh.
            Optionally load <code>database/smart_allocation_sample_data.sql</code> for demo scoring fields.
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-warning"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($messages): ?>
        <div class="alert alert-success"><ul class="mb-0"><?php foreach ($messages as $msg): ?><li><?php echo e($msg); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-column flex-lg-row gap-3 align-items-lg-center justify-content-lg-between mb-3">
                        <div>
                            <div class="text-uppercase text-muted fw-bold small">Inventory snapshot</div>
                            <div class="h5 mb-0">AI-assisted roommate matching</div>
                        </div>
                        <input type="search" id="smartStudentFilter" class="form-control rounded-pill" placeholder="Search name, department, reg #…"
                               <?php echo $migrationMissing ? 'disabled' : ''; ?> style="max-width:340px;">
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="smartStudentsTable">
                            <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Department</th>
                                <th>Semester</th>
                                <th>Preference</th>
                                <th>Fee</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$students): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No students found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($students as $stu): ?>
                                    <?php
                                    $hay = strtolower(
                                        trim((string)$stu['name'] . ' ' . (string)$stu['department'] . ' ' . (string)$stu['reg_no'] . ' ' . (string)$stu['semester_label'])
                                    );
                                    ?>
                                    <tr data-search="<?php echo e($hay); ?>">
                                        <td class="fw-bold"><?php echo e((string)$stu['name']); ?>
                                            <div class="small text-muted"><?php echo e((string)$stu['reg_no']); ?></div>
                                        </td>
                                        <td><?php echo e((string)$stu['department']); ?></td>
                                        <td><?php echo e((string)$stu['semester_label']); ?></td>
                                        <td><span class="badge rounded-pill text-bg-secondary"><?php echo e((string)$stu['room_preference']); ?></span></td>
                                        <td><?php echo e((string)$stu['fee_status']); ?></td>
                                        <td><?php echo $stu['active_allocation_id'] ? '<span class="badge text-bg-success">Housed</span>' : '<span class="badge text-bg-warning text-dark">Unassigned</span>'; ?></td>
                                        <td class="text-end">
                                            <?php if ($stu['active_allocation_id']): ?>
                                                <span class="small text-muted">Already allocated</span>
                                            <?php else: ?>
                                                <div class="d-flex gap-2 justify-content-end flex-wrap">
                                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#allocationModal"
                                                            data-student-id="<?php echo (int)$stu['student_id']; ?>"
                                                            onclick="window.smartAllocOpenModal(this)">
                                                        Recommend
                                                    </button>
                                                    <form method="post" class="d-inline mb-0" onsubmit="return confirm('Auto-assign best-ranked available room?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="auto_assign_best">
                                                        <input type="hidden" name="student_id" value="<?php echo (int)$stu['student_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success rounded-pill fw-bold" <?php echo $migrationMissing ? 'disabled' : ''; ?>>
                                                            Auto Assign
                                                        </button>
                                                    </form>
                                                    <form method="post" class="d-inline mb-0">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="enqueue_wait">
                                                        <input type="hidden" name="student_id" value="<?php echo (int)$stu['student_id']; ?>">
                                                        <input type="hidden" name="hostel_id" value="0">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill" <?php echo $migrationMissing ? 'disabled' : ''; ?>>
                                                            Waiting list
                                                        </button>
                                                    </form>
                                                </div>
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
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6 text-uppercase text-muted fw-bold">Waiting list queue</h2>
                    <?php if ($migrationMissing): ?>
                        <p class="text-muted small mb-0">Unavailable until migration succeeds.</p>
                    <?php elseif (!$waiting): ?>
                        <p class="text-muted small mb-0">Nobody is queued — students land here automatically when capacities overflow.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($waiting as $w): ?>
                                <li class="list-group-item px-0 border-0 border-bottom">
                                    <div class="fw-bold"><?php echo e((string)$w['name']); ?></div>
                                    <div class="small text-muted"><?php echo e((string)$w['reg_no']); ?> · <?php echo e((string)($w['hostel_name'] ?? 'Any hostel')); ?></div>
                                    <div class="small">Queued <?php echo e((string)$w['requested_at']); ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-body small text-muted lh-lg">
                    <div class="fw-bold text-dark mb-2"><i class="bi bi-diagram-3 me-1"></i>How scoring works</div>
                    <div class="mb-2">
                        Engine in use:
                        <?php if ($aiEngineLabel === 'python-knn'): ?>
                            <span class="badge text-bg-success">Python · scikit-learn KNN</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">PHP rule scorer (Python service offline)</span>
                        <?php endif; ?>
                    </div>
                    <ul class="ps-3 mb-0">
                        <li>Same department roommate signal · <strong>+10</strong></li>
                        <li>Aligned semester cohort · <strong>+5</strong></li>
                        <li>Room type vs stated preference · <strong>+3</strong></li>
                        <li>More empty berths relieve crowding · <strong>+2 × free beds</strong></li>
                    </ul>
                    <div class="mt-2 small">
                        Start the Python AI service from <code>ai_service/</code> to switch to KNN ranking.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="allocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5">Room recommendation preview</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="allocModalSpinner" class="text-center py-5 d-none"><div class="spinner-border text-success"></div></div>
                <div id="allocModalError" class="alert alert-danger d-none"></div>
                <div id="allocModalBody" class="d-none">
                    <p class="text-muted mb-3" id="allocStudentSummary"></p>
                    <div class="fw-bold mb-2">Recommended room</div>
                    <div class="p-3 rounded-4 border mb-4" style="background:rgba(76,175,80,0.06);border-color:rgba(27,94,32,0.15)!important;">
                        <div class="display-6 fs-4 fw-black text-success" id="allocTopRoom">—</div>
                        <div class="fw-bold mb-2 mt-3">Recommendation score</div>
                        <div class="badge text-bg-success fs-6 fw-bold px-3 py-2 rounded-pill" id="allocTopScore">0</div>
                        <div class="mt-3">
                            <div class="fw-bold mb-1">Reasons</div>
                            <ul class="small mb-0 text-muted ps-3" id="allocReasonList"></ul>
                        </div>
                    </div>
                    <div class="d-flex flex-column flex-md-row gap-2 mb-3">
                        <form method="post" class="flex-grow-1 mb-0" id="modalAutoAssignForm" onsubmit="return confirm('Confirm automatic assignment using the highlighted room?');">
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="assign_specific">
                            <input type="hidden" name="student_id" id="modal_student_id_assign" value="">
                            <input type="hidden" name="room_id" id="modal_room_id_assign" value="">
                            <button type="submit" class="btn btn-success w-100 rounded-pill fw-bold" id="btnModalAutoSave">
                                <i class="bi bi-magic"></i> Auto Assign (recommended room)
                            </button>
                        </form>
                        <button type="button" class="btn btn-outline-secondary rounded-pill fw-bold" data-bs-dismiss="modal">Close</button>
                    </div>
                    <div class="small text-muted">Auto Assign persists the berth, increments room occupancy counters, records the allocation FK graph, then locks duplicate attempts via database constraints.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function smartAllocEscapeHtml(str) {
  return String(str).replace(/[&<>'"]/g, function (c) {
    return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '\'': '&#039;', '\"': '&quot;'})[c];
  });
}

window.smartAllocOpenModal = function (btn) {
  var sid = btn.getAttribute('data-student-id');
  var modal = document.getElementById('allocationModal');
  if (!sid) return;

  document.getElementById('allocModalSpinner').classList.remove('d-none');
  document.getElementById('allocModalError').classList.add('d-none');
  document.getElementById('allocModalBody').classList.add('d-none');
  document.getElementById('modal_student_id_assign').value = sid;
  document.getElementById('modal_room_id_assign').value = '';

  fetch('<?php echo e($b . '/admin/smart_recommend_api.php'); ?>?student_id=' + encodeURIComponent(sid), {
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' }
  }).then(function (r) { return r.json(); }).then(function (data) {
    document.getElementById('allocModalSpinner').classList.add('d-none');
    if (!data.ok) {
      document.getElementById('allocModalError').textContent =
        data.error === 'migration_required'
          ? 'Run database/smart_allocation_schema.sql before using this modal.'
          : 'Could not build recommendations.';
      document.getElementById('allocModalError').classList.remove('d-none');
      return;
    }

    document.getElementById('allocModalBody').classList.remove('d-none');

    var s = data.student || {};
    document.getElementById('allocStudentSummary').innerHTML =
      '<strong>' + smartAllocEscapeHtml(String(s.name || '')) + '</strong> · ' +
      smartAllocEscapeHtml(String(s.department || '')) +
      (s.semester ? '<br/><span class="small">' + smartAllocEscapeHtml(String(s.semester)) + '</span>' : '') +
      '<br/><span class="badge text-bg-light border mt-2">Preference: ' + smartAllocEscapeHtml(String(s.preference || '')) + '</span>';

    var top = data.top;
    if (!top) {
      document.getElementById('allocTopRoom').textContent = 'Inventory full — add to waiting list instead';
      document.getElementById('allocTopScore').textContent = 'N/A';
      document.getElementById('allocReasonList').innerHTML = '<li>No compliant vacant berths satisfy gender-safe policies.</li>';
      document.getElementById('btnModalAutoSave').disabled = true;
      return;
    }

    document.getElementById('btnModalAutoSave').disabled = false;
    document.getElementById('allocTopRoom').textContent = top.label || ('Room #' + top.room_id);
    document.getElementById('allocTopScore').textContent = String(top.score);
    document.getElementById('modal_room_id_assign').value = String(top.room_id);

    var ul = document.getElementById('allocReasonList');
    ul.innerHTML = '';
    (top.reasons || []).forEach(function (reason) {
      var li = document.createElement('li');
      li.innerHTML = '✔ ' + smartAllocEscapeHtml(String(reason));
      ul.appendChild(li);
    });

    window.__smartAllocExtras = Array.isArray(data.ranked) ? data.ranked : [];
  }).catch(function () {
    document.getElementById('allocModalSpinner').classList.add('d-none');
    document.getElementById('allocModalError').textContent = 'Network/API error retrieving recommendations.';
    document.getElementById('allocModalError').classList.remove('d-none');
  });
};

document.getElementById('smartStudentFilter')?.addEventListener('input', function (evt) {
  var needle = evt.target.value.toLowerCase();
  document.querySelectorAll('#smartStudentsTable tbody tr[data-search]').forEach(function (tr) {
    var hay = (tr.getAttribute('data-search') || '');
    tr.style.display = (!needle || hay.indexOf(needle) !== -1) ? '' : 'none';
  });
});
</script>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
