<?php
declare(strict_types=1);
/**
 * @file includes/partials/notice_board.php
 * @brief Notice list UI — Compact or card layout for public notices
 * @access layout
 * @database notices
 */

/**
 * Render published hostel notices.
 *
 * Expected: $publicNotices (array of notice rows)
 * Optional: $noticeBoardLayout ('cards'|'compact'), $noticeBoardEmpty (string),
 *           $noticeBoardViewAll (string|null URL)
 */

$publicNotices = $publicNotices ?? [];
$noticeBoardLayout = (string)($noticeBoardLayout ?? 'cards');
$noticeBoardEmpty = (string)($noticeBoardEmpty ?? 'No notices have been published yet.');
$noticeBoardViewAll = isset($noticeBoardViewAll) ? (string)$noticeBoardViewAll : '';

if (!isset($noticeCatColours) || !is_array($noticeCatColours)) {
    $noticeCatColours = [
        'urgent'    => ['#fce4ec', '#c62828'],
        'fee'       => ['#fff8e1', '#f9a825'],
        'allotment' => ['#e8f5e9', '#1b5e20'],
        'rules'     => ['#e3f2fd', '#0d47a1'],
        'event'     => ['#ede7f6', '#4527a0'],
        'general'   => ['#f5f5f5', '#424242'],
    ];
}
?>

<?php if ($publicNotices === []): ?>
    <div class="dash-notice-empty text-muted fw-semibold py-4 text-center"><?php echo e($noticeBoardEmpty); ?></div>
<?php elseif ($noticeBoardLayout === 'compact'): ?>
    <ul class="dash-notice-list list-unstyled mb-0">
        <?php foreach ($publicNotices as $n): ?>
            <?php
            $cat = (string)$n['category'];
            [$bg, $fg] = $noticeCatColours[$cat] ?? $noticeCatColours['general'];
            $isPinned = (int)($n['pinned'] ?? 0) === 1;
            $body = (string)$n['body'];
            $snippet = mb_strlen($body) > 160 ? mb_substr($body, 0, 157, 'UTF-8') . '…' : $body;
            $pubTs = strtotime((string)($n['published_at'] ?? '')) ?: time();
            ?>
            <li class="dash-notice-list-item<?php echo $isPinned ? ' dash-notice-list-item--pinned' : ''; ?>">
                <div class="dash-notice-list-meta">
                    <span class="dash-notice-cat" style="background:<?php echo e($bg); ?>;color:<?php echo e($fg); ?>;">
                        <?php echo e($cat); ?>
                    </span>
                    <?php if ($isPinned): ?>
                        <span class="dash-notice-cat dash-notice-cat--pin">Pinned</span>
                    <?php endif; ?>
                    <span class="dash-notice-date"><?php echo e(date('d M Y', $pubTs)); ?></span>
                </div>
                <h3 class="dash-notice-list-title"><?php echo e((string)$n['title']); ?></h3>
                <p class="dash-notice-list-body"><?php echo nl2br(e($snippet)); ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($publicNotices as $n): ?>
            <?php
            $cat = (string)$n['category'];
            [$bg, $fg] = $noticeCatColours[$cat] ?? $noticeCatColours['general'];
            $isPinned = (int)($n['pinned'] ?? 0) === 1;
            $body = (string)$n['body'];
            $snippet = mb_strlen($body) > 200 ? mb_substr($body, 0, 197, 'UTF-8') . '…' : $body;
            $pubTs = strtotime((string)($n['published_at'] ?? '')) ?: time();
            ?>
            <div class="col-md-6">
                <article class="student-dash-announce-card dash-notice-card<?php echo $isPinned ? ' dash-notice-card--pinned' : ''; ?>">
                    <span class="student-dash-announce-ic"><i class="bi bi-megaphone-fill"></i></span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <span class="dash-notice-cat" style="background:<?php echo e($bg); ?>;color:<?php echo e($fg); ?>;">
                                <?php echo e($cat); ?>
                            </span>
                            <?php if ($isPinned): ?>
                                <span class="dash-notice-cat dash-notice-cat--pin"><i class="bi bi-pin-angle-fill me-1"></i>Pinned</span>
                            <?php endif; ?>
                        </div>
                        <h3 class="student-dash-announce-heading"><?php echo e((string)$n['title']); ?></h3>
                        <p class="student-dash-announce-snippet mb-2"><?php echo nl2br(e($snippet)); ?></p>
                        <div class="student-dash-announce-meta">Published <?php echo e(date('d M Y · H:i', $pubTs)); ?></div>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($noticeBoardViewAll !== ''): ?>
    <div class="text-end mt-3">
        <a href="<?php echo e($noticeBoardViewAll); ?>" class="student-dash-link-all fw-bold">
            View all notices <i class="bi bi-chevron-right small"></i>
        </a>
    </div>
<?php endif; ?>
