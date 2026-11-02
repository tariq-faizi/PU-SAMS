<?php

if (!function_exists('uploadPhotoMaxBytes')) {
    function uploadPhotoMaxBytes(): int { return 2 * 1024 * 1024; }
}

if (!function_exists('uploadPhoto')) {
    /**
     * Validate and store an uploaded photo (jpg/png/webp, <= 2MB).
     * Backwards-compatible signature: $reason (optional 3rd arg) receives a
     * human-readable failure reason when the function returns null.
     */
    function uploadPhoto(array $file, string $oldPhoto = '', ?string &$reason = null): ?string {
        $reason = '';
        if (!isset($file['tmp_name'], $file['error'], $file['size'])) {
            $reason = 'No file received.';
            return null;
        }

        $err = (int)$file['error'];
        if ($err !== UPLOAD_ERR_OK) {
            switch ($err) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $reason = 'File is too large (max 2MB).'; break;
                case UPLOAD_ERR_PARTIAL:
                    $reason = 'Upload was interrupted. Please try again.'; break;
                case UPLOAD_ERR_NO_FILE:
                    $reason = 'No file was selected.'; break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $reason = 'Server is missing a temp folder for uploads.'; break;
                case UPLOAD_ERR_CANT_WRITE:
                    $reason = 'Server could not write the uploaded file.'; break;
                case UPLOAD_ERR_EXTENSION:
                    $reason = 'Upload blocked by a PHP extension.'; break;
                default:
                    $reason = 'Upload failed (error code '.$err.').';
            }
            return null;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $reason = 'Invalid upload source.';
            return null;
        }

        $maxBytes = uploadPhotoMaxBytes(); // 2 MB hard cap
        $size = (int)$file['size'];
        if ($size <= 0) { $reason = 'Empty file.'; return null; }
        if ($size > $maxBytes) {
            $reason = 'File is too large (max 2MB). Your file is '.round($size/1024/1024, 2).'MB.';
            return null;
        }

        $allowedExt   = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $origName = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        if (!isset($allowedExt[$ext])) {
            $reason = 'Only JPG, PNG or WEBP images are allowed.';
            return null;
        }

        $imgInfo = @getimagesize($file['tmp_name']);
        if (!$imgInfo || empty($imgInfo['mime'])) {
            $reason = 'File is not a valid image.';
            return null;
        }
        if (!in_array($imgInfo['mime'], $allowedMimes, true)) {
            $reason = 'Image type not allowed (got '.$imgInfo['mime'].').';
            return null;
        }

        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = $fi ? finfo_file($fi, $file['tmp_name']) : '';
            if ($fi) finfo_close($fi);
            if ($realMime && !in_array($realMime, $allowedMimes, true)) {
                $reason = 'File content does not match an allowed image type.';
                return null;
            }
        }

        $dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__) . '/uploads/');
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        if (!is_dir($dir)) {
            // FIX #2 (v23.1) — د سرور لپاره روښانه پیغام.
            $reason = 'Upload folder is missing on the server (uploads/ does not exist).';
            return null;
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0775);
            if (!is_writable($dir)) {
                // FIX #2 (v23.1) — د ادمین پوست-mortem پارا د فولډر اجازې بیان.
                $reason = 'Upload folder is not writable on the server (chmod uploads/ to 0775).';
                return null;
            }
        }

        try {
            $rand = bin2hex(random_bytes(12));
        } catch (Throwable $e) {
            $rand = substr(md5(uniqid('', true)), 0, 24);
        }
        $newName = 'pho_' . date('Ymd') . '_' . $rand . '.' . $ext;
        $dest = rtrim($dir, '/').'/'.$newName;

        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            $reason = 'Could not save the uploaded file.';
            return null;
        }
        @chmod($dest, 0644);

        if ($oldPhoto !== '' && $oldPhoto !== 'default.png' && preg_match('/^[A-Za-z0-9_.\-]+$/', $oldPhoto)) {
            $oldPath = rtrim($dir, '/').'/'.$oldPhoto;
            if (is_file($oldPath)) @unlink($oldPath);
        }

        return $newName;
    }
}

if (!function_exists('pusamsSecurityHeaders')) {
    
    function pusamsSecurityHeaders(): void {
        if (headers_sent()) return;
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
        header('X-Permitted-Cross-Domain-Policies: none');
        header_remove('X-Powered-By');

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443')
            || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        $csp = "default-src 'self'; "
             . "img-src 'self' data: blob:; "
             . "style-src 'self' 'unsafe-inline'; "
             . "script-src 'self' 'unsafe-inline'; "
             . "font-src 'self' data:; "
             . "connect-src 'self'; "
             . "frame-ancestors 'self'; "
             . "base-uri 'self'; "
             . "form-action 'self'; "
             . "object-src 'none'";
        header('Content-Security-Policy: ' . $csp);
    }
}

if (!function_exists('pusamsRateLimit')) {
    
    function pusamsRateLimit(string $bucket, int $maxRequests = 60, int $windowSeconds = 60): bool {
        if (empty($_SESSION['user_id'])) return true;
        try {
            $db  = getDB();
            $uid = (int)$_SESSION['user_id'];
            $stmt = $db->prepare("SELECT COUNT(*) FROM audit_log
                WHERE user_id=? AND action=? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([$uid, 'rl_'.$bucket, $windowSeconds]);
            $count = (int)$stmt->fetchColumn();
            if ($count >= $maxRequests) return false;
            $db->prepare("INSERT INTO audit_log (user_id, action, module, description, ip_address)
                          VALUES (?,?,?,?,?)")
               ->execute([$uid, 'rl_'.$bucket, 'security', 'rate-limit tick', $_SERVER['REMOTE_ADDR'] ?? '']);
            return true;
        } catch (Throwable $e) {
            return true;
        }
    }
}
