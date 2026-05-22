<?php
declare(strict_types=1);
/**
 * @file includes/student_avatar.php
 * @brief Avatar uploads — Validate, save, delete student profile photos
 * @access student
 * @database students
 */

/**
 * Student profile photo helpers: secure filename storage & public URL.
 */

function student_avatar_dir(): string
{
    return __DIR__ . '/../uploads/student_avatars';
}

/** Web path suffix (after base_path) including leading slash segment */
function student_avatar_url(?string $filename): ?string
{
    $f = $filename !== null ? basename($filename) : '';
    if ($f === '' || str_contains($f, '..')) {
        return null;
    }
    $physical = student_avatar_dir() . DIRECTORY_SEPARATOR . $f;
    if (!is_file($physical)) {
        return null;
    }
    return '/uploads/student_avatars/' . $f;
}

/**
 * Validates upload, saves new file under uploads/student_avatars.
 *
 * @return array{ok:bool, basename?:string, error?:string}
 */
function student_avatar_save_upload(array $file, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'missing'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Could not upload file.'];
    }
    if (($file['size'] ?? 0) > 2_097_152) {
        return ['ok' => false, 'error' => 'Photo must be 2 MB or smaller.'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload payload.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmp) : false;
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!is_string($mime) || !isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Use JPG, PNG, WebP, or GIF only.'];
    }
    $ext = $allowed[$mime];

    $dir = student_avatar_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Avatar storage unavailable.'];
    }

    $basename = 'u' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $basename;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'Could not store photo file.'];
    }

    @chmod($dest, 0644);
    return ['ok' => true, 'basename' => $basename];
}

/** Remove filesystem file safely if basename matches ours */
function student_avatar_delete(?string $filename): void
{
    $f = $filename !== null ? basename($filename) : '';
    if ($f === '' || str_contains($f, '..')) {
        return;
    }
    $path = student_avatar_dir() . DIRECTORY_SEPARATOR . $f;
    if (is_file($path)) {
        @unlink($path);
    }
}
