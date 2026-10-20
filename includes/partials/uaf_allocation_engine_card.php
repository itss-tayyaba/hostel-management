<?php
declare(strict_types=1);
/**
 * Copy-paste allocation engine card for admin dashboard.
 */
?>
<div id="uafAllocationCard" style="border:1px solid #e5e7eb;border-radius:14px;padding:18px;background:#ffffff;box-shadow:0 10px 28px rgba(2,6,23,0.06);">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div>
            <h3 style="margin:0;font-size:18px;font-weight:800;color:#0f172a;">Automated UAF Room Allocation</h3>
            <p style="margin:6px 0 0;color:#475569;font-size:13px;">
                Run deterministic faculty/gender/room-type allocation in memory-safe chunks.
            </p>
        </div>
        <button
            type="button"
            id="runUafAllocationBtn"
            style="display:inline-flex;align-items:center;gap:8px;background:#0f766e;color:#fff;border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;"
        >
            <span id="uafAllocSpinner" style="display:none;width:14px;height:14px;border:2px solid rgba(255,255,255,0.35);border-top-color:#fff;border-radius:999px;"></span>
            <span id="uafAllocBtnText">Execute Room Allocation Engine</span>
        </button>
    </div>

    <div id="uafAllocationResult" style="margin-top:12px;"></div>
</div>

<script>
(() => {
    const btn = document.getElementById('runUafAllocationBtn');
    const spinner = document.getElementById('uafAllocSpinner');
    const btnText = document.getElementById('uafAllocBtnText');
    const resultBox = document.getElementById('uafAllocationResult');
    const csrfToken = <?php echo json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const endpoint = <?php echo json_encode(base_path() . '/allocation_api.php?action=run', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    if (!btn || !spinner || !btnText || !resultBox) return;

    const renderNotice = (ok, html) => {
        resultBox.innerHTML =
            `<div style="padding:10px 12px;border-radius:10px;font-size:13px;font-weight:600;` +
            (ok
                ? `background:#ecfdf5;border:1px solid #10b981;color:#065f46;`
                : `background:#fef2f2;border:1px solid #ef4444;color:#7f1d1d;`) +
            `">${html}</div>`;
    };

    const setLoading = (isLoading) => {
        btn.disabled = isLoading;
        btn.style.opacity = isLoading ? '0.75' : '1';
        btn.style.cursor = isLoading ? 'not-allowed' : 'pointer';
        spinner.style.display = isLoading ? 'inline-block' : 'none';
        spinner.style.animation = isLoading ? 'uaf-spin 0.8s linear infinite' : 'none';
        btnText.textContent = isLoading ? 'Running allocation...' : 'Execute Room Allocation Engine';
    };

    btn.addEventListener('click', async () => {
        try {
            setLoading(true);
            resultBox.innerHTML = '';

            const body = new URLSearchParams();
            body.set('csrf_token', csrfToken);

            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body.toString()
            });
            const payload = await res.json();

            if (!res.ok || !payload.success) {
                renderNotice(false, `Allocation failed: ${payload.message || payload.error || 'unknown_error'}`);
                return;
            }

            renderNotice(
                true,
                `Processed <strong>${payload.processed}</strong> applications, allocated <strong>${payload.allocated}</strong>, ` +
                `waitlisted <strong>${payload.waitlisted}</strong>, failed <strong>${payload.failed}</strong> in ` +
                `<strong>${payload.time_ms} ms</strong>.`
            );
        } catch (err) {
            renderNotice(false, `Allocation failed: ${err && err.message ? err.message : 'network_error'}`);
        } finally {
            setLoading(false);
        }
    });
})();
</script>

<style>
@keyframes uaf-spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

