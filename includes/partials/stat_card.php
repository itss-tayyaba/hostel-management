<?php
declare(strict_types=1);
/**
 * @file includes/partials/stat_card.php
 * @brief KPI card — Reusable statistics card partial
 * @access layout
 */

/**
 * Stat card partial.
 * Vars: $statIcon, $statValue, $statLabel, $statTrend (optional), $statAccent (optional css class)
 */
$statIcon = $statIcon ?? 'bi-graph-up';
$statValue = $statValue ?? '0';
$statLabel = $statLabel ?? '';
$statTrend = $statTrend ?? '';
$statAccent = $statAccent ?? 'bg-success-subtle text-success';
?>
<div class="hostel-stat-card">
    <div class="stat-icon <?php echo e((string)$statAccent); ?>">
        <i class="bi <?php echo e((string)$statIcon); ?>"></i>
    </div>
    <div class="stat-value"><?php echo e((string)$statValue); ?></div>
    <div class="stat-label"><?php echo e((string)$statLabel); ?></div>
    <?php if ($statTrend !== ''): ?>
        <div class="stat-trend stat-trend-up"><?php echo e((string)$statTrend); ?></div>
    <?php endif; ?>
</div>
