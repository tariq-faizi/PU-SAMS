<?php

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
if (!defined('BACKUP_DIR')) define('BACKUP_DIR', BASE_PATH . '/backups/');
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', BASE_PATH . '/uploads/');
if (!defined('DEFAULT_PER_PAGE')) define('DEFAULT_PER_PAGE', 20);
if (!defined('MAX_LOGIN_ATTEMPTS')) define('MAX_LOGIN_ATTEMPTS', 3);
if (!defined('LOCKOUT_SECONDS')) define('LOCKOUT_SECONDS', 60);

if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    
    $segments = ['/admin','/dean','/manager','/teacher','/student','/api','/profile','/missed','/notifications','/reports'];
    foreach ($segments as $seg) {
        if (str_ends_with($scriptDir, $seg)) { $scriptDir = substr($scriptDir, 0, -strlen($seg)); break; }
    }
    define('BASE_URL', rtrim($scriptDir, '/'));
}

if (!defined('ROLE_ADMIN'))     define('ROLE_ADMIN',     1); 
if (!defined('ROLE_DEPT_HEAD')) define('ROLE_DEPT_HEAD', 2); 
if (!defined('ROLE_TEACHER'))   define('ROLE_TEACHER',   3); 
if (!defined('ROLE_STUDENT'))   define('ROLE_STUDENT',   4); 
if (!defined('ROLE_ATT_MGR'))   define('ROLE_ATT_MGR',   5); 

if (!defined('APP_NAME'))      define('APP_NAME',      'PU_SAMS');
if (!defined('APP_FULL_NAME')) define('APP_FULL_NAME', 'PUSAMS');
if (!defined('INSTITUTION_NAME')) define('INSTITUTION_NAME', 'Paktia University');

if (!defined('CSRF_TOKEN_NAME')) define('CSRF_TOKEN_NAME', '_csrf');
if (!defined('PASSWORD_MIN_LEN')) define('PASSWORD_MIN_LEN', 8);
if (!defined('SESSION_TIMEOUT')) define('SESSION_TIMEOUT', 30); 
if (!defined('LOGIN_MAX_ATTEMPTS')) define('LOGIN_MAX_ATTEMPTS', 3);
if (!defined('LOGIN_LOCKOUT_SECONDS')) define('LOGIN_LOCKOUT_SECONDS', 60);
if (!defined('EDIT_WINDOW_DAYS')) define('EDIT_WINDOW_DAYS', 7);
if (!defined('SEMESTER_WEEKS')) define('SEMESTER_WEEKS', 16);
if (!defined('PRIMARY_FACULTY_ID')) define('PRIMARY_FACULTY_ID', 1);

if (!defined('CACHE_DIR')) define('CACHE_DIR', BASE_PATH . '/cache/');
if (!defined('CHART_CACHE_TTL')) define('CHART_CACHE_TTL', 300); 
if (!defined('STAT_CACHE_TTL')) define('STAT_CACHE_TTL', 60);

if (!defined('MISSED_SESSION_GRACE_MINUTES')) define('MISSED_SESSION_GRACE_MINUTES', 0);

if (!defined('ATTENDANCE_EARLY_GRACE_MINUTES')) define('ATTENDANCE_EARLY_GRACE_MINUTES', 5); 

if (!defined('DAYS_OF_WEEK')) {
    define('DAYS_OF_WEEK', [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ]);
}

/*
 * =============================================================================
 *  PUSAMS Timezone Resolver (FIX 17)
 * -----------------------------------------------------------------------------
 *  - The default app timezone is Asia/Kabul (Afghanistan: +04:30).
 *  - Order of precedence:
 *      1. PUSAMS_FORCE_TZ env var   (hard override, never auto-detect)
 *      2. PUSAMS_TZ env var          (explicit user choice)
 *      3. Windows OS timezone via tzutil (mapped to IANA)
 *      4. /etc/timezone or /etc/localtime symlink (Linux/Mac)
 *      5. Hardcoded fallback: Asia/Kabul
 *  - The previous version read ini_get('date.timezone') BEFORE the Kabul
 *    fallback. On UTC servers (common on shared hosting/cloud) it returned
 *    "UTC", which caused all date('Y-m-d') calls to report the wrong day
 *    after 19:30 UTC (== 00:00 Kabul next day). That is the root cause of
 *    the "system date is one day behind the computer" issue.
 *  - We now NEVER fall back to ini_get('date.timezone') because that value
 *    is set by hosting providers and rarely matches the institution's
 *    physical location. We trust the OS timezone or our Kabul default.
 * =============================================================================
 */
if (!defined('PUSAMS_DEFAULT_TZ')) define('PUSAMS_DEFAULT_TZ', 'Asia/Kabul');

if (!function_exists('pusamsDetectSystemTimezone')) {
    function pusamsDetectSystemTimezone(): string {

        // 1) Hard override — always wins (set in config/.env: PUSAMS_FORCE_TZ=Asia/Kabul)
        $forceTz = trim((string) (getenv('PUSAMS_FORCE_TZ') ?: ''));
        if ($forceTz !== '' && @timezone_open($forceTz)) return $forceTz;

        // 2) Soft override — explicit institutional choice
        $envTz = trim((string) (getenv('PUSAMS_TZ') ?: ''));
        if ($envTz !== '' && @timezone_open($envTz)) return $envTz;

        // 3) Windows — map Windows TZ names to IANA
        if (DIRECTORY_SEPARATOR === '\\' && function_exists('shell_exec')) {
            $winTz = @shell_exec('tzutil /g 2>NUL');
            if (is_string($winTz)) {
                $winTz = trim($winTz);
                $map = [
                    'Afghanistan Standard Time'      => 'Asia/Kabul',
                    'Iran Standard Time'             => 'Asia/Tehran',
                    'Pakistan Standard Time'         => 'Asia/Karachi',
                    'India Standard Time'            => 'Asia/Kolkata',
                    'Arabian Standard Time'          => 'Asia/Dubai',
                    'Arab Standard Time'             => 'Asia/Riyadh',
                    'Turkey Standard Time'           => 'Europe/Istanbul',
                    'GTB Standard Time'              => 'Europe/Bucharest',
                    'W. Europe Standard Time'        => 'Europe/Berlin',
                    'Central Europe Standard Time'   => 'Europe/Budapest',
                    'Central European Standard Time' => 'Europe/Warsaw',
                    'Romance Standard Time'          => 'Europe/Paris',
                    'GMT Standard Time'              => 'Europe/London',
                    'UTC'                            => 'UTC',
                    'Greenwich Standard Time'        => 'Atlantic/Reykjavik',
                    'E. Europe Standard Time'        => 'Europe/Chisinau',
                    'FLE Standard Time'              => 'Europe/Kiev',
                    'Russian Standard Time'          => 'Europe/Moscow',
                    'China Standard Time'            => 'Asia/Shanghai',
                    'Tokyo Standard Time'            => 'Asia/Tokyo',
                    'Korea Standard Time'            => 'Asia/Seoul',
                    'Singapore Standard Time'        => 'Asia/Singapore',
                    'SE Asia Standard Time'          => 'Asia/Bangkok',
                    'AUS Eastern Standard Time'      => 'Australia/Sydney',
                    'Eastern Standard Time'          => 'America/New_York',
                    'Central Standard Time'          => 'America/Chicago',
                    'Mountain Standard Time'         => 'America/Denver',
                    'Pacific Standard Time'          => 'America/Los_Angeles',
                ];
                if (isset($map[$winTz]) && @timezone_open($map[$winTz])) return $map[$winTz];
            }
        }

        // 4) Linux/Mac — read OS timezone file
        if (@is_readable('/etc/timezone')) {
            $tz = trim((string) @file_get_contents('/etc/timezone'));
            if ($tz !== '' && @timezone_open($tz) && $tz !== 'UTC' && $tz !== 'Etc/UTC') {
                return $tz;
            }
        }
        if (@is_link('/etc/localtime')) {
            $link = @readlink('/etc/localtime');
            if (is_string($link) && preg_match('#zoneinfo/(.+)$#', $link, $m)) {
                $detected = $m[1];
                if (@timezone_open($detected) && $detected !== 'UTC' && $detected !== 'Etc/UTC') {
                    return $detected;
                }
            }
        }

        // 5) Hardcoded fallback — Paktia University is in Afghanistan
        //    NOTE: we deliberately do NOT use ini_get('date.timezone') because
        //    shared hosting providers typically set it to UTC, which would
        //    cause the system date to be 1 day behind the wall-clock date in
        //    Afghanistan after 19:30 UTC.
        return PUSAMS_DEFAULT_TZ;
    }
}

// Apply the resolved timezone immediately so every later date()/strtotime()
// call (including those in autoloaded helpers) runs against the correct zone.
$__pusamsTz = pusamsDetectSystemTimezone();
date_default_timezone_set($__pusamsTz);
if (!defined('PUSAMS_ACTIVE_TZ')) define('PUSAMS_ACTIVE_TZ', $__pusamsTz);
unset($__pusamsTz);

/**
 * pusamsToday() — Canonical "today" in the active app timezone.
 * Use this instead of date('Y-m-d') in any new code so that the date is
 * always anchored to PUSAMS_ACTIVE_TZ even if a third-party library
 * temporarily overrides the default timezone.
 */
if (!function_exists('pusamsToday')) {
    function pusamsToday(string $format = 'Y-m-d'): string {
        try {
            $tz  = new DateTimeZone(defined('PUSAMS_ACTIVE_TZ') ? PUSAMS_ACTIVE_TZ : 'Asia/Kabul');
            $now = new DateTimeImmutable('now', $tz);
            return $now->format($format);
        } catch (Throwable $e) {
            return date($format);
        }
    }
}
if (!function_exists('pusamsNow')) {
    function pusamsNow(string $format = 'Y-m-d H:i:s'): string {
        return pusamsToday($format);
    }
}

if (!defined('DEBUG_MODE')) define('DEBUG_MODE', false);
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('verifyCsrf')) {
    function verifyCsrf(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        
        $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            die('CSRF token validation failed.');
        }
    }
}
