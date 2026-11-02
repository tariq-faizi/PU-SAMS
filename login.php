<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/helpers.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/lang.php';

if (isLoggedIn()) {
    header('Location: '.BASE_URL.'/index.php');
    exit;
}

$flashes = flashGet();
$error   = '';
$selectedRole = (int)($_POST['role_id'] ?? 0);

$maxAttempts  = (int)(getSetting('max_login_attempts') ?: MAX_LOGIN_ATTEMPTS);
$lockoutTotal = (int)(getSetting('lockout_seconds')   ?: LOCKOUT_SECONDS);

$attemptsUsed      = 0;
$remainingAttempts = $maxAttempts;
$lockoutSecs       = 0;

$ipForCheck       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$usernameForCheck = trim((string)($_POST['username'] ?? ''));
if ($usernameForCheck !== '') {
    $preStatus = loginLockoutStatus($usernameForCheck, $ipForCheck);
    if ($preStatus['remaining'] > 0) {
        $lockoutSecs       = (int)$preStatus['remaining'];
        $attemptsUsed      = (int)$preStatus['fails'];
        $maxAttempts       = (int)$preStatus['max'];
        $remainingAttempts = 0;
        $error = "Too many failed attempts. Account is locked for {$lockoutSecs} seconds.";
    }
}

$roleOptions = [
    ROLE_ADMIN     => 'Vice_chancellor',
    ROLE_DEPT_HEAD => 'Faculty_dean',
    ROLE_ATT_MGR   => 'Manager',
    ROLE_TEACHER   => 'Teacher',
    ROLE_STUDENT   => 'Student',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lockoutSecs <= 0) {
    $username     = trim($_POST['username'] ?? '');
    $password     = $_POST['password'] ?? '';
    $selectedRole = (int)($_POST['role_id'] ?? 0);

    if (!$selectedRole || !isset($roleOptions[$selectedRole])) {
        $error = 'Please select an account role.';
    } else {
        $result = loginUser($username, $password, $selectedRole);
        if ($result['success']) {
            if (!empty($result['force_pw'])) {
                flashSet('warning', 'Please change your password before continuing.');
                redirect(BASE_URL.'/profile/password.php');
            }
            $redir = $_SESSION['redirect_after_login'] ?? '';
            unset($_SESSION['redirect_after_login']);
            redirect($redir ?: BASE_URL.'/index.php');
        } else {
            $lockoutSecs       = (int)($result['lockout'] ?? 0);
            $attemptsUsed      = (int)($result['attempts_used'] ?? 0);
            $maxAttempts       = (int)($result['max_attempts'] ?? $maxAttempts);
            $remainingAttempts = (int)($result['remaining_attempts'] ?? max(0, $maxAttempts - $attemptsUsed));

            if ($lockoutSecs > 0) {
                $error = "Too many failed attempts. Account is locked for {$lockoutSecs} seconds.";
            } else {
                $error = "Invalid username or password. Remaining attempts: {$remainingAttempts} of {$maxAttempts}.";
            }
        }
    }
}

function loginSvg(string $name, string $extraClass = ''): string {
    $cls = trim('login-svg '.$extraClass);
    $svgs = [
        'role'   => '<svg class="'.$cls.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.31 0-6 2.69-6 6h12c0-3.31-2.69-6-6-6Zm11.7-7.7-3-3a1 1 0 0 0-.7-.3h-3a1 1 0 0 0-1 1v3a1 1 0 0 0 .3.7l3 3a1 1 0 0 0 1.4 0l3-3a1 1 0 0 0 0-1.4Z"/></svg>',
        'user'   => '<svg class="'.$cls.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.42 0-8 3.13-8 7h16c0-3.87-3.58-7-8-7Z"/></svg>',
        'lock'   => '<svg class="'.$cls.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 10V8a5 5 0 0 1 10 0v2h1a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h1Zm2 0h6V8a3 3 0 0 0-6 0v2Z"/></svg>',
        'eye'    => '<svg class="'.$cls.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 5c-5.5 0-9.5 4.7-11 7 1.5 2.3 5.5 7 11 7s9.5-4.7 11-7c-1.5-2.3-5.5-7-11-7Zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10Z"/></svg>',
        'eyeoff' => '<svg class="'.$cls.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2.1 3.5 3.5 2.1l18.4 18.4-1.4 1.4-3-3A12 12 0 0 1 12 19C6.5 19 2.5 14.3 1 12c.7-1.1 2-2.8 3.8-4.3L2.1 3.5Z"/></svg>',
        'signin' => '<svg class="'.$cls.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 17v-2H5V9h5V7l5 5-5 5Zm9-14H12a2 2 0 0 0-2 2v3h2V5h7v14h-7v-3h-2v3a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Z"/></svg>',
        'moon'   => '<svg class="'.$cls.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>',
        'sun'    => '<svg class="'.$cls.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10Zm0-5a1 1 0 0 1 1 1v2a1 1 0 1 1-2 0V3a1 1 0 0 1 1-1Z"/></svg>',
        'info'   => '<svg class="'.$cls.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-1 5h2v6h-2V7Zm0 8h2v2h-2v-2Z"/></svg>',
        'warn'   => '<svg class="'.$cls.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2 1 21h22L12 2Zm-1 7h2v6h-2V9Zm0 8h2v2h-2v-2Z"/></svg>',
    ];
    return $svgs[$name] ?? '';
}

$rtl    = isRtl();
$dir    = $rtl ? 'rtl' : 'ltr';
$lang   = $currentLang ?? 'en';
$isDark = !empty($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === '1';
?>
<!DOCTYPE html>
<html lang="<?=h($lang)?>" dir="<?=$dir?>">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <meta name="theme-color" content="#1a73e8"/>
  <title><?=t('login_title')?> | <?=h(getSetting('app_name', APP_NAME))?></title>
  <link rel="stylesheet" href="<?=BASE_URL?>/assets/css/<?= $rtl ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css' ?>"/>
  <link rel="stylesheet" href="<?=BASE_URL?>/assets/css/all.min.css"/>
  <link rel="stylesheet" href="<?=BASE_URL?>/assets/css/style.css"/>
  <link rel="stylesheet" href="<?=BASE_URL?>/assets/css/pusams-ui.css"/>
  <link rel="stylesheet" href="<?=BASE_URL?>/assets/css/pusams-modern.css"/>
  <link rel="stylesheet" href="<?=BASE_URL?>/assets/css/pusams-ultra.css"/>
  <link rel="icon" href="<?=BASE_URL?>/assets/images/favicon.png"/>
  <?php if($isDark):?><link rel="stylesheet" href="<?=BASE_URL?>/assets/css/dark.css"/><?php endif;?>
  <style>
    body{margin:0;font-family:"Times New Roman", Georgia, Cambria, "Liberation Serif", serif;}
    .login-svg{width:1.05em;height:1.05em;vertical-align:-.18em;flex-shrink:0;fill:currentColor;display:inline-block}
    .login-svg.lg{width:1.4em;height:1.4em}
    .login-bg{min-height:100vh;background:linear-gradient(rgba(15,23,42,.78),rgba(29,78,216,.68)),url('./assets/images/backgrounds/login-bg.jpg') center/cover no-repeat,linear-gradient(135deg,#0f172a 0%,#1d4ed8 50%,#0ea5e9 100%);display:flex;align-items:center;justify-content:center;padding:20px;position:relative;overflow:hidden}
    .login-bg::before{content:'';position:absolute;inset:-50px;background:radial-gradient(circle at 20% 30%,rgba(255,255,255,.18),transparent 40%),radial-gradient(circle at 80% 70%,rgba(255,255,255,.10),transparent 50%);z-index:0;animation:floatBg 18s ease-in-out infinite alternate}
    @keyframes floatBg{from{transform:translate(0,0) scale(1)}to{transform:translate(20px,-15px) scale(1.04)}}
    .login-card{position:relative;z-index:1;background:#fff;border-radius:28px;padding:42px 38px;max-width:520px;width:100%;box-shadow:0 30px 80px rgba(0,0,0,.34),0 0 0 1px rgba(255,255,255,.5)}
    .dark-mode .login-card{background:#132238;color:#dbeafe}
    .login-logo{text-align:center;margin-bottom:24px}
    .login-logo h3{font-weight:800;margin-top:10px;margin-bottom:4px;background:linear-gradient(135deg,#1d4ed8,#0ea5e9);-webkit-background-clip:text;background-clip:text;color:transparent}
    .lang-btns{text-align:center;margin-bottom:14px}
    .lang-btns a{font-size:.85rem;margin:0 6px;color:#6c757d;text-decoration:none;padding:4px 10px;border-radius:8px;transition:.2s}
    .lang-btns a:hover{color:#1a73e8;background:#eef4ff}
    .lang-btns a.active{font-weight:800;color:#fff;background:linear-gradient(135deg,#1d4ed8,#0ea5e9)}

    /* ───── Simplified lockout / attempts UI (PUSAMS v24) ──────────── */
    .login-message{
      margin-top:4px;padding:12px 14px;border-radius:14px;
      display:flex;align-items:center;gap:12px;font-size:.92rem;
      border:1px solid transparent;
    }
    .login-message.is-warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
    .login-message.is-lock{background:#fef2f2;border-color:#fecaca;color:#991b1b}
    .dark-mode .login-message.is-warn{background:#2d2410;border-color:#78350f;color:#fde68a}
    .dark-mode .login-message.is-lock{background:#3f1d1d;border-color:#7f1d1d;color:#fecaca}
    .login-message .msg-icon{flex:0 0 32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.6)}
    .dark-mode .login-message .msg-icon{background:rgba(255,255,255,.08)}
    .login-message .msg-body{flex:1;min-width:0;line-height:1.45}
    .login-message .msg-body strong{font-weight:800}
    .login-countdown{
      flex:0 0 auto;min-width:54px;padding:6px 10px;border-radius:10px;
      background:#fff;color:#dc2626;font-weight:800;font-variant-numeric:tabular-nums;
      border:1px solid #fecaca;text-align:center;font-size:1.05rem;
    }
    .dark-mode .login-countdown{background:#1a2336;border-color:#7f1d1d;color:#fecaca}

    /* د قفل پر مهال د فورم disable کول */
    .login-form-locked .form-control,
    .login-form-locked .form-select,
    .login-form-locked .input-group .btn{
      background:#f1f5f9 !important;color:#94a3b8 !important;cursor:not-allowed !important;
      border-color:#e2e8f0 !important;pointer-events:none;
    }

    .login-subtitle{display:none}
    .login-input-group{display:flex;align-items:center;border:1px solid #dbe4f0;border-radius:16px;background:#fff;overflow:hidden;box-shadow:0 8px 20px rgba(15,23,42,.04)}
    .login-input-group:focus-within{border-color:#1d4ed8;box-shadow:0 0 0 4px rgba(29,78,216,.10)}
    .login-input-icon{width:48px;display:flex;align-items:center;justify-content:center;color:#1d4ed8;background:linear-gradient(180deg,#eff6ff,#f8fbff);border-inline-end:1px solid #e5eef8;font-size:1rem;flex:0 0 48px}
    .login-input-group .form-control,.login-input-group .form-select{border:0;box-shadow:none;background:transparent;height:48px;padding-inline:.95rem;font-weight:600}
    .login-input-group .form-select{padding-inline-end:2rem;cursor:pointer}
    .login-input-group .btn{border:0;border-inline-start:1px solid #e5eef8;border-radius:0;height:48px;background:#fff;color:#64748b;padding-inline:14px}
    .login-input-group .btn:hover{background:#f8fafc;color:#1d4ed8}
    .login-field-label{display:flex;align-items:center;justify-content:space-between;margin-bottom:.45rem;font-size:.88rem;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.04em}
    .login-select-compact .form-select{height:44px;font-size:.92rem}
    .dark-mode .login-input-group{background:#0f1c2f;border-color:#29405f;box-shadow:none}
    .dark-mode .login-input-icon{background:linear-gradient(180deg,#17263a,#132238);border-color:#29405f;color:#93c5fd}
    .dark-mode .login-input-group .form-control,.dark-mode .login-input-group .form-select,.dark-mode .login-input-group .btn{background:#0f1c2f;color:#dbeafe;border-color:#29405f}
  </style>
</head>
<body class="<?=$isDark?'dark-mode':''?>" data-bs-theme="<?=$isDark?'dark':'light'?>">
<div class="login-bg">
  <div class="login-card">
    <div class="lang-btns">
      <a href="?lang=ps" class="<?=$lang==='ps'?'active':''?>">پښتو</a>
      <a href="?lang=fa" class="<?=$lang==='fa'?'active':''?>">دری</a>
      <a href="?lang=en" class="<?=$lang==='en'?'active':''?>">EN</a>
      &nbsp;
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="toggleDarkLogin()" title="Dark mode">
        <?= loginSvg($isDark ? 'sun' : 'moon') ?>
      </button>
    </div>

    <div class="login-logo">
      <img src="<?=BASE_URL?>/assets/images/pusams-logo-192.png" alt="PUSAMS" class="pusams-login-logo" onerror="this.style.display='none'" style="max-width:96px"/>
      <h3><?=h(getSetting('app_name', defined('APP_NAME') ? APP_NAME : 'PUSAMS'))?></h3>
      <div class="small text-muted"><?=h(getSetting('institution_name', defined('INSTITUTION_NAME') ? INSTITUTION_NAME : 'Paktia University'))?></div>
      <div class="pusams-brand-stripe"></div>
    </div>

    <?php foreach($flashes as $f): ?>
      <div class="alert alert-<?=h($f['type'])?> py-2 small"><?= loginSvg('info') ?> <?=h($f['msg'])?></div>
    <?php endforeach; ?>

    <?php if ($error): ?>
      
      <div class="login-message <?= $lockoutSecs > 0 ? 'is-lock' : 'is-warn' ?>">
        <span class="msg-icon"><?= loginSvg($lockoutSecs > 0 ? 'lock' : 'warn','lg') ?></span>
        <div class="msg-body"><?= h($error) ?></div>
        <?php if ($lockoutSecs > 0): ?>
          <div class="login-countdown" id="lockoutCountdown"><?= (int)$lockoutSecs ?>s</div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="" autocomplete="off" novalidate id="loginForm" class="<?= $lockoutSecs>0 ? 'login-form-locked' : '' ?>">
      <div class="mb-3">
        <div class="login-field-label"><span><?=t('account_role')?></span></div>
        <div class="login-input-group login-select-compact">
          <span class="login-input-icon"><?= loginSvg('role') ?></span>
          <select name="role_id" id="fldRole" class="form-select form-select-sm" required <?=$lockoutSecs>0?'disabled':''?>>
            <option value="">-- <?=t('select')?> --</option>
            <?php foreach($roleOptions as $rid=>$label):?>
              <option value="<?=$rid?>" <?=$selectedRole===$rid?'selected':''?>><?=h($label)?></option>
            <?php endforeach;?>
          </select>
        </div>
      </div>
      <div class="mb-3">
        <div class="login-field-label"><span><?=t('username')?></span></div>
        <div class="login-input-group">
          <span class="login-input-icon"><?= loginSvg('user') ?></span>
          <input type="text" name="username" id="fldUser" class="form-control" required value="<?=h($_POST['username']??'')?>" placeholder="<?=h(t('enter_username'))?>" autocomplete="username" <?=$lockoutSecs>0?'disabled':'autofocus'?>/>
        </div>
      </div>
      <div class="mb-4">
        <div class="login-field-label"><span><?=t('password')?></span></div>
        <div class="login-input-group">
          <span class="login-input-icon"><?= loginSvg('lock') ?></span>
          <input type="password" name="password" id="fldPw" class="form-control" required placeholder="<?=h(t('enter_password'))?>" autocomplete="current-password" <?=$lockoutSecs>0?'disabled':''?>/>
          <button type="button" class="btn" id="btnTogglePw" onclick="togglePw()" <?=$lockoutSecs>0?'disabled':''?> aria-label="toggle password">
            <span id="eyeIconWrap"><?= loginSvg('eye') ?></span>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 py-2 fw-bold" id="loginBtn" <?=$lockoutSecs>0?'disabled':''?>>
        <?php if($lockoutSecs>0): ?>
          <?= loginSvg('lock') ?> <span id="btnLabel">Locked (<span id="btnSec"><?=(int)$lockoutSecs?></span>s)</span>
        <?php else: ?>
          <?= loginSvg('signin') ?> <?=t('login')?>
        <?php endif; ?>
      </button>
    </form>

  </div>
</div>
<script src="<?=BASE_URL?>/assets/js/bootstrap.bundle.min.js"></script>
<script>
const LOGIN_SVG_EYE    = `<?= addslashes(loginSvg('eye')) ?>`;
const LOGIN_SVG_EYEOFF = `<?= addslashes(loginSvg('eyeoff')) ?>`;
function togglePw(){
    const i = document.getElementById('fldPw');
    const wrap = document.getElementById('eyeIconWrap');
    if (!i || !wrap) return;
    const showing = i.type === 'password';
    i.type = showing ? 'text' : 'password';
    wrap.innerHTML = showing ? LOGIN_SVG_EYEOFF : LOGIN_SVG_EYE;
}
function toggleDarkLogin(){
    const isDark = document.body.classList.toggle('dark-mode');
    document.cookie = 'dark_mode=' + (isDark?'1':'0') + ';path=/;max-age=31536000';
    location.reload();
}

<?php if ($lockoutSecs > 0): ?>
/* د قفل ساده countdown — هر ثانیه نوې کیږي. کله چې وخت پای ته ورسي،
   Role / Username / Password / submit دواړه بیا Activeیږي. */
(function(){
  let s = <?= (int)$lockoutSecs ?>;
  const cd     = document.getElementById('lockoutCountdown');
  const btn    = document.getElementById('loginBtn');
  const btnSec = document.getElementById('btnSec');
  const btnLbl = document.getElementById('btnLabel');
  const form   = document.getElementById('loginForm');
  const fields = [
    document.getElementById('fldRole'),
    document.getElementById('fldUser'),
    document.getElementById('fldPw'),
    document.getElementById('btnTogglePw'),
  ].filter(Boolean);

  if (form){
    form.addEventListener('submit', function(ev){
      if (s > 0){ ev.preventDefault(); ev.stopPropagation(); return false; }
    });
  }

  function tick(){
    if (s <= 0){
      if (form) form.classList.remove('login-form-locked');
      fields.forEach(el => { el.disabled = false; });
      if (cd) cd.textContent = '0s';
      if (btn){
        btn.disabled = false;
        const SIGNIN_SVG = '<?= addslashes(loginSvg('signin')) ?>';
        if (btnLbl) btnLbl.innerHTML = SIGNIN_SVG + ' <?= addslashes(t('login')) ?>';
        else        btn.innerHTML    = SIGNIN_SVG + ' <?= addslashes(t('login')) ?>';
      }
      return;
    }
    if (cd)     cd.textContent     = s + 's';
    if (btnSec) btnSec.textContent = s;
    s--;
    setTimeout(tick, 1000);
  }
  tick();
})();
<?php endif; ?>
</script>
</body>
</html>
