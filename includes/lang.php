<?php

if (!isset($_SESSION)) { if(session_status()===PHP_SESSION_NONE) session_start(); }

$validLangs = ['en','ps','fa'];
if (isset($_GET['lang']) && in_array($_GET['lang'],$validLangs, true)) {
    $_SESSION['lang'] = $_GET['lang'];
    setcookie('app_lang', $_GET['lang'], time()+31536000, '/');
    $url = strtok($_SERVER['REQUEST_URI'],'?');
    $params = $_GET; unset($params['lang']);
    header('Location: '.$url.($params?'?'.http_build_query($params):''));
    exit;
}

$currentLang = $_SESSION['lang'] ?? ($_COOKIE['app_lang'] ?? getSetting('default_language', 'en'));
if (!in_array($currentLang, $validLangs, true)) $currentLang = 'en';
$GLOBALS['currentLang'] = $currentLang;

$langFile = __DIR__."/lang/{$currentLang}.php";
if (!file_exists($langFile)) $langFile = __DIR__.'/lang/en.php';

$L = require $langFile;

$LE = require __DIR__.'/lang/en.php';

if (!function_exists('t')) {
    function t(string $key, array $vars = [], string $fallback = ''): string {
        global $L, $LE;
        $str = $L[$key] ?? ($LE[$key] ?? ($fallback !== '' ? $fallback : $key));
        foreach ($vars as $k => $v) {
            $str = str_replace('{'.$k.'}', (string)$v, $str);
        }
        return $str;
    }
}

if (!function_exists('isRtl')) {
    function isRtl(): bool {
        return in_array($GLOBALS['currentLang'] ?? 'en', ['ps','fa','ur'], true);
    }
}

@require_once __DIR__ . '/lang_runtime_map.php';
