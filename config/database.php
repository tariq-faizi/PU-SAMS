<?php
/**
 * =====================================================================
 *  PUSAMS — Database Connection Layer
 *  File: config/database.php
 *
 *  Purpose: Provide a single, reusable PDO connection (getDB()) plus the
 *  standard DB_* constants for the rest of the codebase.
 *
 *  Recovery note (rebuilt after the file was found empty in production —
 *  which was the root cause of the site-wide 500 error): every page
 *  in the codebase calls getDB() (see permissions.php, auth.php,
 *  helpers.php, feature_helpers.php, header.php, etc.). If this file
 *  is missing or empty, every request past the includes returns 500
 *  with "Call to undefined function getDB()" in the PHP error log.
 *
 *  Configuration precedence (highest wins):
 *      1. Environment variables:  PUSAMS_DB_HOST / _NAME / _USER /
 *                                  _PASS / _PORT / _CHARSET
 *      2. Constants pre-defined before this file is included (lets a
 *         local `config/local.php`-style override work without editing
 *         this file).
 *      3. XAMPP-friendly defaults (host=127.0.0.1, user=root, empty
 *         password, db=PU_SAMS, utf8mb4).
 *
 *  Safe on shared hosting: connection errors are logged and rendered
 *  as a friendly page, never as a stack trace that would leak the
 *  DSN or credentials.
 * =====================================================================
 */

if (!defined('BASE_PATH')) {
    // config.php normally defines this; safety net in case database.php
    // is required stand-alone (e.g. by a CLI migration script).
    define('BASE_PATH', dirname(__DIR__));
}

/* ─── 1. Resolve connection parameters ───────────────────────────── */

if (!defined('DB_HOST')) {
    define('DB_HOST',    getenv('PUSAMS_DB_HOST')    ?: '127.0.0.1');
}
if (!defined('DB_PORT')) {
    define('DB_PORT',    (int)(getenv('PUSAMS_DB_PORT') ?: 3306));
}
if (!defined('DB_NAME')) {
    define('DB_NAME',    getenv('PUSAMS_DB_NAME')    ?: 'PU_SAMS');
}
if (!defined('DB_USER')) {
    define('DB_USER',    getenv('PUSAMS_DB_USER')    ?: 'root');
}
if (!defined('DB_PASS')) {
    // Empty password is the XAMPP default.
    $__envPass = getenv('PUSAMS_DB_PASS');
    define('DB_PASS',    $__envPass !== false ? $__envPass : '');
    unset($__envPass);
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', getenv('PUSAMS_DB_CHARSET') ?: 'utf8mb4');
}

/* ─── 2. Singleton PDO factory ───────────────────────────────────── */

if (!function_exists('getDB')) {
    /**
     * Return the shared PDO connection for the request. Creates it on
     * first call and reuses it thereafter. Uses FETCH_ASSOC by default
     * because the rest of the codebase calls ->fetch()/->fetchAll()
     * without arguments and expects associative rows.
     */
    function getDB(): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            // Force utf8mb4 on the session as an extra guarantee for
            // Pashto/Dari/Arabic script + emoji.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES ".DB_CHARSET." COLLATE ".DB_CHARSET."_unicode_ci",
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (Throwable $e) {
            // Log full detail to the PHP error log for the admin.
            error_log('[PUSAMS] DB connection failed: '.$e->getMessage());

            // Show the user a safe, translated-ish message. Never echo
            // the DSN, username or password.
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            $isJson = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false
                   || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'Database connection is currently unavailable. Please try again shortly.',
                ]);
            } else {
                echo '<!doctype html><html><head><meta charset="utf-8">'
                   . '<title>Service temporarily unavailable</title>'
                   . '<style>body{font-family:system-ui,Segoe UI,Tahoma,sans-serif;'
                   . 'max-width:640px;margin:8vh auto;padding:2rem;color:#333;'
                   . 'text-align:center}h1{color:#b02a37;margin-bottom:.5rem}'
                   . 'p{line-height:1.55;color:#555}code{background:#f4f4f4;'
                   . 'padding:.15rem .4rem;border-radius:4px}</style></head><body>'
                   . '<h1>⚠️ Database temporarily unavailable</h1>'
                   . '<p>The PUSAMS server could not reach the database.<br>'
                   . 'This is usually a temporary problem — please try again in a minute.</p>'
                   . '<p style="font-size:.85em;color:#888">If the problem persists, '
                   . 'the administrator should check the MySQL service and the '
                   . '<code>config/database.php</code> credentials.</p>'
                   . '</body></html>';
            }
            exit;
        }

        /* Best-effort session settings — never fatal. */
        try {
            // Match the app timezone at the SQL layer so NOW() / CURDATE()
            // agree with PHP's date() in helpers.
            if (defined('PUSAMS_ACTIVE_TZ')) {
                try {
                    $offset = (new DateTime('now', new DateTimeZone(PUSAMS_ACTIVE_TZ)))->format('P');
                    $pdo->exec("SET time_zone = '{$offset}'");
                } catch (Throwable $ignored) { /* keep server default */ }
            }
        } catch (Throwable $ignored) { /* non-fatal */ }

        return $pdo;
    }
}
