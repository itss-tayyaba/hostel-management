<?php
declare(strict_types=1);
/**
 * @file includes/layout/header.php
 * @brief Dashboard HTML head — Bootstrap, CSS, opens dash-shell wrapper
 * @access layout
 */

require_once __DIR__ . '/../../includes/app.php';

// Expected vars:
// - $pageTitle (string)
// - $userName (string)
// - $userRole (string)
// - $dashShellClass (string) optional extra class on .dash-shell e.g. admin layout variant
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo e($pageTitle ?? 'Dashboard'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
<?php
$__stylesheet_path_fs = dirname(__DIR__, 2) . '/assets/css/style.css';
$__style_ver = is_file($__stylesheet_path_fs) ? (string)filemtime($__stylesheet_path_fs) : (string)time();
?>
    <link rel="stylesheet" href="<?php echo e(base_path() . '/assets/css/style.css?v=' . $__style_ver); ?>" />
<?php
$__components_path_fs = dirname(__DIR__, 2) . '/assets/css/components.css';
$__components_ver = is_file($__components_path_fs) ? (string)filemtime($__components_path_fs) : $__style_ver;
?>
    <link rel="stylesheet" href="<?php echo e(base_path() . '/assets/css/components.css?v=' . $__components_ver); ?>" />
</head>
<body<?php
$__bc = trim((string)($bodyClass ?? ''));
echo $__bc !== '' ? ' class="' . e($__bc) . '"' : '';
?>>
<div class="dash-shell <?php echo e(trim((string)($dashShellClass ?? ''))); ?>">
<?php require_once __DIR__ . '/../partials/flash_alerts.php'; ?>

<?php
// Sidebar includes the overlay button and nav.
?>

