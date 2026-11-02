<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $cookieParams = [
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ];
    session_set_cookie_params($cookieParams);
    session_name('APP_SID');
    session_start();
}

function isLoggedIn(): bool { return !empty($_SESSION['user_id']) && !empty($_SESSION['sess_token']); }

function requireLogin(): void {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    checkSessionTimeout();
    validateSessionToken();
    
    if (function_exists('pusamsApplyAdminTimezoneOverride')) {
        pusamsApplyAdminTimezoneOverride();
    }
    
    if (function_exists('pusamsAutoSync')) {
        pusamsAutoSync();
    }
}

function checkSessionTimeout(): void {
    $timeout = (int)(getSetting('session_timeout') ?: SESSION_TIMEOUT);
    $last = $_SESSION['last_activity'] ?? 0;
    if ($last && time() - $last > $timeout * 60) {
        destroySession();
        
        if(session_status()===PHP_SESSION_NONE){ session_start(); }
        $_SESSION['flash'][] = ['type'=>'warning','msg'=>'Session expired. Please log in again.'];
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function validateSessionToken(): void {
    if (empty($_SESSION['sess_token'])) {
        destroySession();
        header('Location:' . BASE_URL . '/login.php');
        exit;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM session_tokens WHERE token=? AND user_id=? AND is_revoked=0 AND expires_at > NOW()");
    $stmt->execute([$_SESSION['sess_token'], $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        destroySession();
        if(session_status()===PHP_SESSION_NONE){ session_start(); }
        $_SESSION['flash'][] = ['type'=>'danger','msg'=>'Your session has been revoked. Please log in again.'];
        header('Location:' . BASE_URL . '/login.php');
        exit;
    }
    $db->prepare("UPDATE session_tokens SET last_active=NOW() WHERE token=?")->execute([$_SESSION['sess_token']]);
}

function loginLockoutStatus(string $username, string $ip): array {
    $db = getDB();
    $max = (int)(getSetting('max_login_attempts') ?: MAX_LOGIN_ATTEMPTS);
    $lockSec = (int)(getSetting('lockout_seconds') ?: LOCKOUT_SECONDS);

    
    
    $extendedLockSec  = (int)(getSetting('extended_lockout_seconds') ?: 3600);
    $cyclesForExtended = (int)(getSetting('lockouts_before_extended') ?: 3);

    $longStmt = $db->prepare("SELECT COUNT(*) as c, MAX(created_at) as last_at
                              FROM audit_log
                              WHERE action='login_fail'
                                AND description LIKE ?
                                AND ip_address=?
                                AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $longStmt->execute(["%username:{$username}%", $ip, $extendedLockSec]);
    $longRow   = $longStmt->fetch() ?: ['c' => 0, 'last_at' => null];
    $longFails = (int)$longRow['c'];
    $cyclesCompleted = (int)floor($longFails / max(1, $max));

    $stmt = $db->prepare("SELECT COUNT(*) as c, MAX(created_at) as last_at
                          FROM audit_log
                          WHERE action='login_fail'
                            AND description LIKE ?
                            AND ip_address=?
                            AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute(["%username:{$username}%", $ip, $lockSec]);
    $row = $stmt->fetch() ?: ['c' => 0, 'last_at' => null];
    $fails = (int)$row['c'];

    $remaining      = 0;
    $appliedLockSec = $lockSec;
    $isExtended     = false;

    if ($cyclesCompleted >= $cyclesForExtended && !empty($longRow['last_at'])) {
        $lastTs = strtotime($longRow['last_at']);
        $extRemaining = max(0, $extendedLockSec - (time() - $lastTs));
        if ($extRemaining > 0) {
            $remaining      = $extRemaining;
            $appliedLockSec = $extendedLockSec;
            $isExtended     = true;
        }
    }

    if (!$isExtended && $fails >= $max && !empty($row['last_at'])) {
        $lastTs = strtotime($row['last_at']);
        $remaining = max(0, $lockSec - (time() - $lastTs));
    }

    return [
        'remaining'          => $remaining,
        'fails'              => $isExtended ? $longFails : $fails,
        'max'                => $max,
        'lock_seconds'       => $appliedLockSec,
        'remaining_attempts' => max(0, $max - $fails),
        'lockout_cycles'     => $cyclesCompleted,
        'extended'           => $isExtended,
        'extended_threshold' => $cyclesForExtended,
    ];
}

function loginLockoutRemaining(string $username, string $ip): int {
    return loginLockoutStatus($username, $ip)['remaining'];
}

function loginUser(string $username, string $password, int $selectedRoleId = 0): array {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $status = loginLockoutStatus($username, $ip);
    if ($status['remaining'] > 0) {
        $isExt = !empty($status['extended']);
        $msg = $isExt
            ? "After three lockout cycles, the account is suspended for one hour. You may try again in: {$status['remaining']}s"
            : "Too many failed attempts. Account is temporarily locked. Please wait {$status['remaining']} seconds.";
        return [
            'success'            => false,
            'error'              => $msg,
            'lockout'            => $status['remaining'],
            'attempts_used'      => $status['fails'],
            'max_attempts'       => $status['max'],
            'remaining_attempts' => 0,
            'extended'           => $isExt,
            'lock_seconds'       => $status['lock_seconds'],
            'lockout_cycles'     => $status['lockout_cycles'] ?? 0,
        ];
    }

    $failHelper = function() use (&$status, $username, $ip, $db) {
        
        return loginLockoutStatus($username, $ip);
    };

    $stmt = $db->prepare("SELECT id,username,password_hash,role_id,faculty_id,department_id,class_id,semester_id,full_name,photo,status,force_password_change FROM users WHERE username=? AND deleted_at IS NULL");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) {
        auditLog('login_fail', 'auth', null, "username:{$username}", null, $ip);
        $st = $failHelper();
        $msg = $st['remaining'] > 0
            ? (!empty($st['extended'])
                ? "After three lockout cycles, the account is suspended for one hour. You may try again in: {$st['remaining']}s"
                : "Too many failed attempts. Account is temporarily locked. Please wait {$st['remaining']} seconds.")
            : 'Invalid username or password. (' . max(0, $st['max'] - $st['fails']) . ' of ' . $st['max'] . ' attempts remaining)';
        return [
            'success'            => false,
            'error'              => $msg,
            'lockout'            => $st['remaining'],
            'attempts_used'      => $st['fails'],
            'max_attempts'       => $st['max'],
            'remaining_attempts' => max(0, $st['max'] - $st['fails']),
            'extended'           => !empty($st['extended']),
            'lock_seconds'       => $st['lock_seconds'] ?? 0,
            'lockout_cycles'     => $st['lockout_cycles'] ?? 0,
        ];
    }

    if ($selectedRoleId > 0 && (int)$user['role_id'] !== $selectedRoleId) {
        auditLog('login_fail', 'auth', null, "username:{$username}; role_mismatch:{$selectedRoleId}", null, $ip);
        $st = $failHelper();
        $msg = $st['remaining'] > 0
            ? (!empty($st['extended'])
                ? "After three lockout cycles, the account is suspended for one hour. You may try again in: {$st['remaining']}s"
                : "Too many failed attempts. Account is temporarily locked. Please wait {$st['remaining']} seconds.")
            : 'Selected role does not match this account. (' . max(0, $st['max'] - $st['fails']) . ' of ' . $st['max'] . ' attempts remaining)';
        return [
            'success'            => false,
            'error'              => $msg,
            'lockout'            => $st['remaining'],
            'attempts_used'      => $st['fails'],
            'max_attempts'       => $st['max'],
            'remaining_attempts' => max(0, $st['max'] - $st['fails']),
            'extended'           => !empty($st['extended']),
            'lock_seconds'       => $st['lock_seconds'] ?? 0,
            'lockout_cycles'     => $st['lockout_cycles'] ?? 0,
        ];
    }
    if ($user['status'] !== 'active') return ['success'=>false,'error'=>'Account is '.$user['status'].'.'];
    if (!password_verify($password, $user['password_hash'])) {
        auditLog('login_fail', 'auth', null, "username:{$username}", null, $ip);
        $st = $failHelper();
        $msg = $st['remaining'] > 0
            ? (!empty($st['extended'])
                ? "After three lockout cycles, the account is suspended for one hour. You may try again in: {$st['remaining']}s"
                : "Too many failed attempts. Account is temporarily locked. Please wait {$st['remaining']} seconds.")
            : 'Invalid username or password. (' . max(0, $st['max'] - $st['fails']) . ' of ' . $st['max'] . ' attempts remaining)';
        return [
            'success'            => false,
            'error'              => $msg,
            'lockout'            => $st['remaining'],
            'attempts_used'      => $st['fails'],
            'max_attempts'       => $st['max'],
            'remaining_attempts' => max(0, $st['max'] - $st['fails']),
            'extended'           => !empty($st['extended']),
            'lock_seconds'       => $st['lock_seconds'] ?? 0,
            'lockout_cycles'     => $st['lockout_cycles'] ?? 0,
        ];
    }

    session_regenerate_id(true);
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 86400);
    $db->prepare("INSERT INTO session_tokens (user_id,token,ip_address,user_agent,last_active,expires_at) VALUES (?,?,?,?,NOW(),?)")
       ->execute([$user['id'],$token,$ip,$_SERVER['HTTP_USER_AGENT']??'',$expires]);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role_id'] = (int)$user['role_id'];
    $_SESSION['faculty_id'] = $user['faculty_id'];
    $_SESSION['department_id'] = $user['department_id'];
    $_SESSION['class_id'] = $user['class_id'] ?? null;
    $_SESSION['semester_id'] = $user['semester_id'] ?? null;
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['photo'] = $user['photo'];
    $_SESSION['sess_token'] = $token;
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
    auditLog('login_success','auth',$user['id'],'User logged in',$user['id'],$ip);
    return ['success'=>true,'force_pw'=>$user['force_password_change']];
}

function destroySession(): void {
    if (isLoggedIn()) {
        getDB()->prepare("UPDATE session_tokens SET is_revoked=1 WHERE token=?")->execute([$_SESSION['sess_token']??'']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function logoutEverywhere(int $userId): void { getDB()->prepare("UPDATE session_tokens SET is_revoked=1 WHERE user_id=?")->execute([$userId]); }
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string { if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
}
if (!function_exists('verifyCsrf')) {
    function verifyCsrf(): void {
        $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            die('<p style="color:red;font-family:sans-serif;padding:20px">⚠ CSRF token invalid. Please go back and try again.</p>');
        }
    }
}
