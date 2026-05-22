<?php
declare(strict_types=1);
/**
 * @file includes/partials/flash_alerts.php
 * @brief Flash → toast — Outputs session flash for HostelUI.toast()
 * @access layout
 */

/** Renders session flash as a toast payload (consumed by assets/js/ui.js). */
$__flash = flash_get();
if ($__flash !== null):
?>
<div id="hostel-flash-data" class="d-none"
     data-type="<?php echo e((string)$__flash['type']); ?>"
     data-message="<?php echo e((string)$__flash['message']); ?>"></div>
<?php endif; ?>
