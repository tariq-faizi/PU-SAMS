<?php
/* FIX #2 (v23.1) — د انځور د اپلوډ ستونزو لپاره ساده تشخیصي پاڼه.
 *
 *  دا پاڼه یوازې VC/Rais ته اجازه ورکوي چې وګوري آیا د سرور تنظیمات
 *  (post_max_size, upload_max_filesize, uploads/ folder writable) د دې
 *  لپاره مساعد دي چې انځورونه بریالۍ ذخیره شي. د هیڅ Type بدلون نه کوي.
 */

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';

requireLogin();
requireRole([ROLE_ADMIN]);

$pageTitle = 'Upload diagnostics';

$uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__).'/uploads/');
$exists    = is_dir($uploadDir);
$writable  = $exists && is_writable($uploadDir);
$readable  = $exists && is_readable($uploadDir);

$postMax   = ini_get('post_max_size');
$uplMax    = ini_get('upload_max_filesize');
$fileUpl   = ini_get('file_uploads');
$tmpDir    = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
$tmpOk     = is_dir($tmpDir) && is_writable($tmpDir);

$photoCap  = function_exists('uploadPhotoMaxBytes') ? uploadPhotoMaxBytes() : (2*1024*1024);

function _toBytes(string $val): int {
    $val = trim($val); if ($val === '') return 0;
    $last = strtolower($val[strlen($val)-1]);
    $num  = (int)$val;
    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return $num;
}
$postMaxBytes = _toBytes((string)$postMax);
$uplMaxBytes  = _toBytes((string)$uplMax);
$postOk = $postMaxBytes >= $photoCap + 256*1024;  // د form overhead ته لږ ځای
$uplOk  = $uplMaxBytes  >= $photoCap;

require_once __DIR__.'/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h5 class="mb-0"><i class="fas fa-stethoscope me-2"></i>Photo Upload Diagnostics</h5>
</div>

<div class="card mb-3"><div class="card-body">
  <table class="table table-sm align-middle mb-0">
    <thead><tr><th>Check</th><th>Value</th><th>Status</th></tr></thead>
    <tbody>
      <tr>
        <td><code>uploads/</code> folder exists</td>
        <td><code><?= h($uploadDir) ?></code></td>
        <td><?= $exists ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>' ?></td>
      </tr>
      <tr>
        <td><code>uploads/</code> writable</td>
        <td><?= $exists ? ('perms: '.substr(sprintf('%o', @fileperms($uploadDir)), -4)) : '—' ?></td>
        <td><?= $writable ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">No</span>' ?></td>
      </tr>
      <tr>
        <td>PHP <code>file_uploads</code></td>
        <td><code><?= h((string)$fileUpl) ?></code></td>
        <td><?= $fileUpl ? '<span class="badge bg-success">On</span>' : '<span class="badge bg-danger">Off</span>' ?></td>
      </tr>
      <tr>
        <td>PHP <code>upload_max_filesize</code></td>
        <td><code><?= h((string)$uplMax) ?></code> (<?= number_format($uplMaxBytes) ?> B)</td>
        <td><?= $uplOk ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-warning text-dark">Low</span>' ?></td>
      </tr>
      <tr>
        <td>PHP <code>post_max_size</code></td>
        <td><code><?= h((string)$postMax) ?></code> (<?= number_format($postMaxBytes) ?> B)</td>
        <td><?= $postOk ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-warning text-dark">Low</span>' ?></td>
      </tr>
      <tr>
        <td>PUSAMS photo limit</td>
        <td><?= number_format($photoCap) ?> B (<?= round($photoCap/1024/1024, 2) ?> MB)</td>
        <td><span class="badge bg-info text-dark">app-cap</span></td>
      </tr>
      <tr>
        <td>Temp folder</td>
        <td><code><?= h((string)$tmpDir) ?></code></td>
        <td><?= $tmpOk ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">No</span>' ?></td>
      </tr>
    </tbody>
  </table>
</div></div>

<?php if (!$writable || !$exists): ?>
  <div class="alert alert-danger">
    <strong>Solution guide:</strong>
    <pre class="mb-0 mt-2">mkdir -p <?= h($uploadDir) ?>
chown -R www-data:www-data <?= h($uploadDir) ?>
chmod 0775 <?= h($uploadDir) ?></pre>
  </div>
<?php endif; ?>

<?php if (!$postOk || !$uplOk): ?>
  <div class="alert alert-warning">
    <strong>PHP.ini recommendation:</strong>
    <pre class="mb-0 mt-2">upload_max_filesize = 4M
post_max_size = 8M
file_uploads = On</pre>
    
  </div>
<?php endif; ?>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
