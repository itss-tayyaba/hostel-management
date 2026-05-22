<?php
declare(strict_types=1);
/**
 * @file includes/pages/voucher_view.php
 * @brief Printable voucher — Shared HTML for fee/payment vouchers
 * @access admin,student
 * @database payments
 */

/**
 * Reusable printable fee-voucher renderer.
 * $payment array must contain: id, amount, semester, payment_date, method, status,
 *                              reference, student_name, reg_no, department, student_email.
 */
function voucher_render(array $payment, array $opts = []): void
{
    $title = (string)($opts['doc_title'] ?? 'Hostel Fee Voucher');
    $org   = (string)($opts['org_name'] ?? 'University of Agriculture Faisalabad');
    $sub   = (string)($opts['org_sub'] ?? 'Hostel Management Office');
    $amount = (float)$payment['amount'];
    $words  = number_format($amount, 2);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title); ?> #<?php echo (int)$payment['id']; ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Roboto, Arial, sans-serif; background: #eef1f6; margin: 0; padding: 24px; color: #1a1a1a; }
    .voucher { max-width: 760px; margin: 0 auto; background: #fff; padding: 28px 32px; border: 2px solid #1b5e20; border-radius: 10px; position: relative; }
    .copy-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 12px; }
    .copy { border: 1px dashed #1b5e20; padding: 16px; border-radius: 6px; }
    .copy-label { position: absolute; top: -12px; right: 12px; background: #1b5e20; color: #fff; font-size: 11px; font-weight: 800; padding: 3px 10px; border-radius: 999px; letter-spacing: 1px; }
    .copy { position: relative; }
    h1 { font-size: 18px; margin: 0; color: #1b5e20; letter-spacing: 0.5px; }
    h2 { font-size: 12px; margin: 4px 0 0; color: #4c956c; font-weight: 600; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1b5e20; padding-bottom: 10px; margin-bottom: 16px; }
    .meta { font-size: 12px; color: #555; text-align: right; }
    table.fields { width: 100%; border-collapse: collapse; font-size: 13px; }
    table.fields td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; }
    table.fields td.k { color: #555; width: 45%; font-weight: 600; }
    table.fields td.v { font-weight: 700; }
    .stamp { position: absolute; bottom: 60px; right: 20px; font-size: 38px; color: rgba(76,175,80,0.18); transform: rotate(-20deg); font-weight: 900; letter-spacing: 4px; }
    .total { background: #f1f8f3; padding: 8px 12px; margin-top: 10px; border-radius: 5px; font-weight: 800; color: #1b5e20; font-size: 14px; display: flex; justify-content: space-between; }
    .signoff { margin-top: 18px; font-size: 11px; color: #555; display: flex; justify-content: space-between; }
    .actions { max-width: 760px; margin: 0 auto 14px; display: flex; gap: 8px; justify-content: flex-end; }
    .btn { background: #1b5e20; color: #fff; border: 0; padding: 8px 14px; border-radius: 6px; font-weight: 700; cursor: pointer; text-decoration: none; font-size: 13px; }
    .btn.ghost { background: #fff; color: #1b5e20; border: 1px solid #1b5e20; }
    @media print {
        body { background: #fff; padding: 0; }
        .actions { display: none; }
        .voucher { border: 2px solid #1b5e20; box-shadow: none; }
    }
</style>
</head>
<body>
<div class="actions">
    <a class="btn ghost" href="javascript:history.back();">← Back</a>
    <button class="btn" onclick="window.print();">Print / Save as PDF</button>
</div>

<div class="voucher">
    <div class="header">
        <div>
            <h1><?php echo htmlspecialchars($org); ?></h1>
            <h2><?php echo htmlspecialchars($sub); ?> · <?php echo htmlspecialchars($title); ?></h2>
        </div>
        <div class="meta">
            <strong>Voucher #</strong> <?php echo (int)$payment['id']; ?><br>
            <strong>Issued:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i')); ?>
        </div>
    </div>

    <div class="copy-pair">
        <?php foreach (['Student Copy', 'Office Copy'] as $copyName): ?>
            <div class="copy">
                <span class="copy-label"><?php echo htmlspecialchars($copyName); ?></span>
                <table class="fields">
                    <tr><td class="k">Student</td><td class="v"><?php echo htmlspecialchars((string)$payment['student_name']); ?></td></tr>
                    <tr><td class="k">Registration #</td><td class="v"><?php echo htmlspecialchars((string)$payment['reg_no']); ?></td></tr>
                    <tr><td class="k">Department</td><td class="v"><?php echo htmlspecialchars((string)($payment['department'] ?? '')); ?></td></tr>
                    <tr><td class="k">Semester</td><td class="v"><?php echo htmlspecialchars((string)$payment['semester']); ?></td></tr>
                    <tr><td class="k">Date</td><td class="v"><?php echo htmlspecialchars((string)$payment['payment_date']); ?></td></tr>
                    <tr><td class="k">Method</td><td class="v"><?php echo htmlspecialchars((string)$payment['method']); ?></td></tr>
                    <tr><td class="k">Reference</td><td class="v"><?php echo htmlspecialchars((string)($payment['reference'] ?? '—')); ?></td></tr>
                    <tr><td class="k">Status</td><td class="v"><?php echo htmlspecialchars(strtoupper((string)$payment['status'])); ?></td></tr>
                </table>
                <div class="total">
                    <span>Amount (PKR)</span>
                    <span><?php echo $words; ?></span>
                </div>
                <div class="signoff">
                    <span>Issued by: Hostel Office</span>
                    <span>Signature: ______________</span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (strtolower((string)$payment['status']) === 'paid'): ?>
        <div class="stamp">PAID</div>
    <?php endif; ?>
</div>
</body>
</html>
<?php
}
