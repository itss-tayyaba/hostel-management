<?php
declare(strict_types=1);
/**
 * @file includes/partials/empty_state.php
 * @brief Empty UI block — Reusable no-data message for tables/lists
 * @access layout
 */

/**
 * Empty state block.
 * Vars: $emptyIcon (bi class), $emptyTitle, $emptyDesc, $emptyActionHtml (optional)
 */
$emptyIcon = $emptyIcon ?? 'bi-inbox';
$emptyTitle = $emptyTitle ?? 'Nothing here yet';
$emptyDesc = $emptyDesc ?? '';
?>
<div class="hostel-empty" role="status">
    <div class="hostel-empty-icon"><i class="bi <?php echo e((string)$emptyIcon); ?>"></i></div>
    <div class="hostel-empty-title"><?php echo e((string)$emptyTitle); ?></div>
    <?php if ($emptyDesc !== ''): ?>
        <p class="hostel-empty-desc"><?php echo e((string)$emptyDesc); ?></p>
    <?php endif; ?>
    <?php if (!empty($emptyActionHtml)): ?>
        <div class="mt-3"><?php echo $emptyActionHtml; ?></div>
    <?php endif; ?>
</div>
