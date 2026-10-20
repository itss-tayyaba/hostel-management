<?php
declare(strict_types=1);
/**
 * @file student/help_chatbot.php
 * @brief Help assistant UI — Chat interface for rule-based bot
 * @access student
 */

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/help_chatbot.php';
require_role('student');

$userName = (string)($_SESSION['name'] ?? 'Student');
$userRole = 'student';
$pageTitle = 'Hostel help assistant';
$activePage = 'student_help_chat';
$dashShellClass = 'student-shell-ref';

$pdo = db();
$studentContext = help_chatbot_student_context($pdo, (int)$_SESSION['user_id']);
?>

<?php require_once __DIR__ . '/../includes/layout/header.php'; ?>
<div class="dash-overlay" aria-hidden="true"></div>
<?php require_once __DIR__ . '/../includes/layout/sidebar.php'; ?>

<div class="dash-topbar dash-topbar-modern d-flex align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
        <div class="dash-mobile-toggle">
            <button class="btn btn-sm btn-icon-modern" type="button" onclick="toggleDashboardSidebar()" aria-label="Menu">
                <i class="bi bi-list fs-5"></i>
            </button>
        </div>
        <div>
            <div class="dash-page-kicker"><i class="bi bi-robot me-1"></i> AI assistant</div>
            <div class="title mb-0"><?php echo e($pageTitle); ?></div>
        </div>
    </div>
    <?php if (help_chatbot_uses_llm()): ?>
        <span class="badge bg-success">Claude AI</span>
    <?php else: ?>
        <span class="badge bg-secondary">FAQ mode</span>
    <?php endif; ?>
</div>

<div class="dash-content dash-content-modern">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="chat-shell">
                <div class="chat-shell-header">
                    <h3>UAF Hostel Assistant</h3>
                    <p><?php echo help_chatbot_uses_llm()
                        ? 'Powered by Claude — ask anything about apply, fees, complaints, room change, ID card'
                        : 'FAQ mode — add ANTHROPIC_API_KEY in config/env.php for AI answers'; ?></p>
                </div>
                <div id="chatLog" class="chat-log" role="log" aria-live="polite"></div>
                <div id="typingHint" class="chat-typing">Assistant typing…</div>
                <div class="chat-input-row">
                    <input type="text" id="chatInput" class="form-control rounded-pill"
                           maxlength="480"
                           placeholder="Ask about hostel apply, fees, complaints…"
                           autocomplete="off" aria-label="Your question"/>
                    <button type="button" id="chatSendBtn" class="btn btn-success rounded-pill px-4 fw-bold">
                        <i class="bi bi-send-fill me-1"></i> Ask
                    </button>
                </div>
            </div>
            <p class="text-muted small mt-3 fw-semibold">
                AI answers use your profile context. Confirm deadlines on official Notices.
                <button type="button" class="btn btn-link btn-sm p-0 fw-bold" id="chatResetBtn">Clear chat</button>
            </p>
        </div>
        <div class="col-lg-4">
            <div class="dash-card dash-card-glass p-4">
                <div class="fw-bold text-success mb-3"><i class="bi bi-person-badge me-2"></i>Your context</div>
                <ul class="list-unstyled small mb-0 fw-semibold text-muted" style="line-height:1.8;">
                    <li><strong class="text-dark"><?php echo e($studentContext['student_name']); ?></strong></li>
                    <li><?php echo e($studentContext['department']); ?> department</li>
                    <li><?php echo e($studentContext['allocation']); ?></li>
                </ul>
                <div class="mt-4">
                    <div class="small fw-bold text-muted mb-2">Quick prompts</div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-success chat-quick chat-quick-chip">Apply for hostel?</button>
                        <button type="button" class="btn btn-sm btn-outline-success chat-quick chat-quick-chip">Payments help</button>
                        <button type="button" class="btn btn-sm btn-outline-success chat-quick chat-quick-chip">Raise complaint?</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var csrf = <?php echo json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
    var logEl = document.getElementById('chatLog');
    var inputEl = document.getElementById('chatInput');
    var sendBtn = document.getElementById('chatSendBtn');
    var hint = document.getElementById('typingHint');

    function append(who, txt) {
        txt = txt == null ? '' : String(txt);
        var bubble = document.createElement('div');
        bubble.className = 'chat-bubble ' + (who === 'user' ? 'chat-bubble-user' : 'chat-bubble-bot');
        var meta = document.createElement('div');
        meta.className = 'chat-bubble-meta';
        meta.textContent = who === 'user' ? 'You' : 'Assistant';
        bubble.appendChild(meta);
        var body = document.createElement('div');
        body.textContent = txt;
        bubble.appendChild(body);
        logEl.appendChild(bubble);
        logEl.scrollTop = logEl.scrollHeight;
    }

    var welcome = <?php echo json_encode(
        help_chatbot_uses_llm()
            ? 'Hi! I am your UAF hostel AI assistant. Ask how to apply, pay fees, raise complaints, change rooms, or print your ID card.'
            : 'Ask about allocating a bed, dues, grievances or room swaps.',
        JSON_HEX_TAG | JSON_HEX_APOS
    ); ?>;
    append('assistant', welcome);

    async function submitMessage(txt) {
        var text = txt || (inputEl && inputEl.value) || '';
        text = String(text || '').trim();
        if (!text) return;
        append('user', text);
        if (inputEl) inputEl.value = '';
        if (hint) hint.classList.add('is-visible');
        if (sendBtn) sendBtn.disabled = true;
        try {
            var res = await fetch(<?php echo json_encode(base_path() . '/student/help_chat_api.php'); ?>, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({message: text})
            });
            var data = await res.json().catch(function () { return {reply: 'Could not read response.', ok: false}; });
            if (!res.ok || !data.ok) {
                append('assistant', data.reply || 'Something went wrong. Refresh and retry.');
                if (window.HostelUI) HostelUI.toast(data.reply || 'Request failed', 'danger');
                return;
            }
            append('assistant', data.reply || '');
            if (data.engine === 'claude' && hint) {
                hint.textContent = 'Claude AI';
            }
        } catch (e) {
            append('assistant', 'Offline or blocked request—reload the dashboard and try once more.');
        } finally {
            if (hint) hint.classList.remove('is-visible');
            if (sendBtn) sendBtn.disabled = false;
            if (inputEl) inputEl.focus();
        }
    }

    if (sendBtn) sendBtn.addEventListener('click', function () { submitMessage(); });
    if (inputEl) inputEl.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); submitMessage(); }
    });
    var resetBtn = document.getElementById('chatResetBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', async function () {
            await fetch(<?php echo json_encode(base_path() . '/student/help_chat_api.php'); ?>, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({reset: true})
            });
            if (logEl) logEl.innerHTML = '';
            append('assistant', welcome);
        });
    }

    document.querySelectorAll('.chat-quick').forEach(function (btn, idx) {
        var prompts = [
            'How do I apply for hostel?',
            'How should I submit hostel payments?',
            'How do I raise a hostel complaint?'
        ];
        btn.addEventListener('click', function () { submitMessage(prompts[idx] || prompts[0]); });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/layout/footer.php'; ?>
