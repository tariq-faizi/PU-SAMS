<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
requirePermission('manage_settings');

$db = getDB();
$pageTitle = 'Settings';

$allowedKeys = [
    'app_name','institution_name','session_timeout','max_login_attempts','lockout_seconds',
    'low_att_threshold','notify_warning_pct','notify_critical_pct',
    'deprivation_absences','edit_window_days','warning_buffer','default_language',
    
    'system_timezone',
];
$groupOrder = ['general','security','attendance'];

function settingsCsrf(): void { echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $_POST)) continue;
        $value = trim((string)$_POST[$key]);

        if ($key === 'system_timezone' && $value !== '' && !@timezone_open($value)) {
            flashSet('warning', '⚠ Invalid timezone ('.h($value).') — not saved.');
            continue;
        }

        $existsStmt = $db->prepare("SELECT id FROM settings_and_policy WHERE `key`=?");
        $existsStmt->execute([$key]);
        if ($existsStmt->fetchColumn()) {
            $db->prepare("UPDATE settings_and_policy SET value=? WHERE `key`=?")->execute([$value, $key]);
        } else {
            
            $grp = ($key === 'system_timezone') ? 'general' : 'general';
            $db->prepare("INSERT INTO settings_and_policy (`key`, value, `group`) VALUES (?,?,?)")->execute([$key, $value, $grp]);
        }
    }
    auditLog('settings_save','settings',null,'Supported system settings updated');
    if (function_exists('cacheBustDashboards')) cacheBustDashboards();
    flashSet('success', 'Settings saved successfully. Unsupported or unused options were not shown.');
    redirect(BASE_URL.'/admin/settings.php');
}

$placeholders = implode(',', array_fill(0, count($allowedKeys), '?'));
$stmt = $db->prepare("SELECT * FROM settings_and_policy WHERE `key` IN ($placeholders) ORDER BY FIELD(`group`,'general','security','attendance'), `key`");
$stmt->execute($allowedKeys);
$rows = $stmt->fetchAll();
$groups = array_fill_keys($groupOrder, []);
foreach ($rows as $row) $groups[$row['group']][] = $row;

$haveTzRow = false;
foreach ($groups['general'] as $g) { if (($g['key'] ?? '') === 'system_timezone') { $haveTzRow = true; break; } }
if (!$haveTzRow) {
    $groups['general'][] = [
        'key'   => 'system_timezone',
        'value' => '',
        'label' => 'System timezone (override)',
        'group' => 'general',
    ];
}

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h5 class="mb-0"><i class="fas fa-cog me-2"></i>Supported Settings</h5>
  </div>
</div>
<form method="POST">
  <?php settingsCsrf(); ?>
  <div class="row g-3">
    <?php foreach ($groups as $group => $items): ?>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header text-capitalize"><?= h($group) ?> settings</div>
        <div class="card-body">
          <?php if (!$items): ?><div class="text-muted small">No active settings in this group.</div><?php endif; ?>
          <?php foreach ($items as $item): ?>
          <div class="mb-3">
            <label class="form-label form-label-sm"><?= h($item['label'] ?: $item['key']) ?></label>
            <?php if ($item['key'] === 'default_language'): ?>
            <select name="<?= h($item['key']) ?>" class="form-select form-select-sm">
              <?php foreach (['en' => 'English','ps' => 'Pashto','fa' => 'Dari'] as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= ($item['value'] ?? 'en') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php elseif ($item['key'] === 'system_timezone'): ?>
            <select name="<?= h($item['key']) ?>" class="form-select form-select-sm">
              <option value="">— Automatic (Auto-detect from OS) —</option>
              <?php
                $tzList = [
                    'Asia/Kabul'        => 'Asia/Kabul (+04:30) — افغانستان',
                    'Asia/Tehran'       => 'Asia/Tehran (+03:30) — ایران',
                    'Asia/Karachi'      => 'Asia/Karachi (+05:00) — پاکستان',
                    'Asia/Kolkata'      => 'Asia/Kolkata (+05:30) — هند',
                    'Asia/Dubai'        => 'Asia/Dubai (+04:00) — امارات',
                    'Asia/Riyadh'       => 'Asia/Riyadh (+03:00) — عربستان',
                    'Europe/Istanbul'   => 'Europe/Istanbul (+03:00) — ترکيه',
                    'Europe/London'     => 'Europe/London (UTC) — برتانيه',
                    'Europe/Berlin'     => 'Europe/Berlin (+01:00) — جرمني',
                    'UTC'               => 'UTC (چاپڗېريایي)',
                ];
                $curTz = (string)($item['value'] ?? '');
                foreach ($tzList as $z => $lab):
              ?>
              <option value="<?= h($z) ?>" <?= $curTz === $z ? 'selected' : '' ?>><?= h($lab) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text small">
              <i class="fas fa-info-circle"></i> If the system time differs from your computer, set the university's local timezone here.
              — Current Active: <code><?= h(date_default_timezone_get()) ?></code> /
              PHP time: <code><?= h(date('Y-m-d H:i:s')) ?></code>
            </div>
            <?php else: ?>
            <input type="text" name="<?= h($item['key']) ?>" class="form-control form-control-sm" value="<?= h($item['value'] ?? '') ?>"/>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="mt-3"><button class="btn btn-primary">Save settings</button></div>
</form>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
