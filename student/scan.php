<?php
/**
 * =====================================================================
 *  PUSAMS — Student QR Scan page
 *  File: student/scan.php
 *
 *  A student either:
 *    (a) opens the camera and lets it decode the teacher's rotating QR
 *        (JS-side via jsQR minified library), or
 *    (b) types the 6-digit backup code shown by the teacher.
 *
 *  The QR/backup code alone identifies the exact attendance_sessions row.
 *  The student cannot pick a class, subject, or date — the identifier
 *  carries all that context.
 *
 *  Additive-only: this file writes ONE row per successful scan into
 *  attendance_records (mark_method='qr'). It does not touch existing
 *  sessions, timetable, or roster logic.
 * =====================================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/lang.php';

requireLogin();
requireRole([ROLE_STUDENT]);
requirePermission('scan_qr');

$db  = getDB();
$uid = (int)$_SESSION['user_id'];
$pageTitle = t('scan_attendance');

$result       = null;   // ['success'=>bool,'message'=>...]
$resultType   = 'success';
$prefilled    = trim((string)($_GET['token'] ?? ''));

function csrfFieldScan(): void {
    echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>';
}

/**
 * Core: validate + record one attendance row.
 * Returns array with 'success' and human 'message'.
 */
function processAttendanceCode(string $code, int $studentId, PDO $db): array {
    $code = trim($code);
    if ($code === '') {
        return ['success' => false, 'message' => 'Please scan or enter a valid code.'];
    }

    // Look up the session by either qr_token or backup_code.
    // Only QR-claimed, open, non-missed sessions are eligible.
    $stmt = $db->prepare(
        "SELECT s.id, s.class_id, s.subject_id, s.teacher_id,
                s.session_date, s.credit_slot_no, s.slot_label,
                s.status, s.is_missed, s.attendance_method,
                s.qr_expires_at,
                c.name AS class_name, sub.name AS subject_name
           FROM attendance_sessions s
           JOIN classes  c   ON c.id  = s.class_id
           JOIN subjects sub ON sub.id = s.subject_id
          WHERE (s.qr_token = ? OR s.backup_code = ?)
            AND s.status = 'open'
            AND s.is_missed = 0
            AND s.attendance_method = 'qr'
          LIMIT 1"
    );
    $stmt->execute([$code, $code]);
    $session = $stmt->fetch();

    if (!$session) {
        return ['success' => false, 'message' => t('qr_scan_invalid')];
    }

    // Expiry check
    if (!empty($session['qr_expires_at']) && strtotime((string)$session['qr_expires_at']) < time()) {
        return ['success' => false, 'message' => t('qr_expired')];
    }

    // Enrollment: reuses the same rule as classActiveStudentIds()
    if (!isStudentEnrolledInClass($studentId, (int)$session['class_id'])) {
        return ['success' => false, 'message' => t('qr_not_enrolled')];
    }

    // Duplicate check — the unique index (session_id, student_id) also protects us
    $dup = $db->prepare("SELECT id FROM attendance_records WHERE session_id=? AND student_id=? LIMIT 1");
    $dup->execute([(int)$session['id'], $studentId]);
    if ($dup->fetch()) {
        return ['success' => false, 'message' => t('already_marked')];
    }

    // Insert the record (INSERT IGNORE guards the concurrency race)
    try {
        $ins = $db->prepare(
            "INSERT IGNORE INTO attendance_records
                 (session_id, student_id, status, marked_at, mark_method, ip_address, device_info)
             VALUES (?, ?, 'present', NOW(), 'qr', ?, ?)"
        );
        $ip = $_SERVER['REMOTE_ADDR']     ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        // Clamp device_info to fit VARCHAR(255)
        if (strlen($ua) > 255) $ua = substr($ua, 0, 255);
        $ins->execute([(int)$session['id'], $studentId, $ip, $ua]);
        if ($ins->rowCount() === 0) {
            // A concurrent scan already inserted the same record — treat as already-marked.
            return ['success' => false, 'message' => t('already_marked')];
        }
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }

    @auditLog('qr_scan', 'attendance', (int)$session['id'], 'Student marked present via qr');

    return [
        'success' => true,
        'message' => t('qr_scan_success'),
        'class'   => (string)$session['class_name'],
        'subject' => (string)$session['subject_name'],
        'credit'  => (string)($session['slot_label'] ?: ('Credit '.(int)$session['credit_slot_no'])),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    /* ── Simple per-student rate-limit (soft anti-abuse) ──
       Prevents mashing the submit button / scripted scan attempts.
       Uses in-session state only — no DB touch, no user impact under
       normal use. Allows a burst of 6 attempts, then enforces a 3-second
       cooldown between attempts for 30 seconds. */
    $rlKey = 'qr_scan_rl';
    $now   = microtime(true);
    $bucket = $_SESSION[$rlKey] ?? ['first' => $now, 'count' => 0, 'last' => 0];
    if ($now - $bucket['first'] > 30) { $bucket = ['first' => $now, 'count' => 0, 'last' => 0]; }
    $tooFast   = ($now - $bucket['last']) < 1.0;               // absolute floor 1s
    $burstOver = ($bucket['count'] >= 6) && ($now - $bucket['last']) < 3.0;
    if ($tooFast || $burstOver) {
        $result = ['success' => false, 'message' => 'Please slow down and try again in a few seconds.'];
        $resultType = 'warning';
    } else {
        $bucket['count']++;
        $bucket['last'] = $now;
        $_SESSION[$rlKey] = $bucket;

        $code   = (string)($_POST['token'] ?? '');
        $result = processAttendanceCode($code, $uid, $db);
        $resultType = $result['success'] ? 'success' : 'danger';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
  <div class="col-xl-7 col-lg-8">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
      <div>
        <h3 class="mb-1"><i class="fas fa-qrcode me-2 text-primary"></i><?= h(t('scan_attendance')) ?></h3>
        <p class="mb-0 text-muted"><?= h(t('qr_scan_intro')) ?></p>
      </div>
      <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle">
        <i class="fas fa-shield-halved me-1"></i>Present / Absent only
      </span>
    </div>

    <?php if ($result): ?>
      <div class="alert alert-<?= h($resultType) ?> shadow-sm mb-4 text-center py-4">
        <i class="fas fa-<?= $result['success'] ? 'check-circle text-success' : 'times-circle text-danger' ?> fs-1 mb-2 d-block"></i>
        <h4 class="mb-1"><?= h($result['message']) ?></h4>
        <?php if ($result['success']): ?>
          <p class="mb-1"><strong><?= h($result['class'] ?? '') ?></strong> · <?= h($result['subject'] ?? '') ?></p>
          <?php if (!empty($result['credit'])): ?>
            <div class="small text-muted"><?= h($result['credit']) ?></div>
          <?php endif; ?>
          <a href="<?= BASE_URL ?>/student/scan.php" class="btn btn-success mt-3">
            <i class="fas fa-rotate-right me-2"></i><?= h(t('qr_scan_another')) ?>
          </a>
        <?php else: ?>
          <a href="<?= BASE_URL ?>/student/scan.php" class="btn btn-outline-danger mt-3">
            <i class="fas fa-reply me-2"></i><?= h(t('qr_try_again')) ?>
          </a>
        <?php endif; ?>
      </div>
    <?php else: ?>

      <form method="POST" id="scanSubmitForm" class="d-none">
        <?php csrfFieldScan(); ?>
        <input type="hidden" name="token" id="qrTokenField">
      </form>

      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span><i class="fas fa-camera me-2 text-primary"></i>Camera Scanner</span>
          <span class="badge rounded-pill bg-info-subtle text-info border border-info-subtle">Fast check-in</span>
        </div>
        <div class="card-body text-center">
          <video id="qrVideo" autoplay muted playsinline class="mb-3 w-100 rounded" style="display:none;max-height:360px;background:#000"></video>
          <canvas id="qrCanvas" style="display:none"></canvas>
          <div id="cameraPlaceholder" class="py-4">
            <i class="fas fa-camera-retro fa-3x text-primary mb-3 d-block"></i>
            <button class="btn btn-primary btn-lg" type="button" id="startCamBtn">
              <i class="fas fa-camera me-2"></i><?= h(t('qr_camera_start')) ?>
            </button>
          </div>
          <div id="scanStatus" class="small text-muted mt-3">Ready.</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><i class="fas fa-keyboard me-2 text-primary"></i><?= h(t('qr_manual_entry_label')) ?></div>
        <div class="card-body">
          <div class="input-group input-group-lg">
            <input type="text" id="manualToken" class="form-control" placeholder="123456" autocomplete="off"
                   value="<?= h($prefilled) ?>">
            <button class="btn btn-primary" type="button" id="submitManualBtn">
              <i class="fas fa-paper-plane me-2"></i><?= h(t('qr_submit')) ?>
            </button>
          </div>
          <p class="small text-muted mt-2 mb-0">
            <?= h(t('qr_scan_intro')) ?>
          </p>
        </div>
      </div>

    <?php endif; ?>
  </div>
</div>

<?php if (!$result): ?>
<!-- jsQR library (client-side QR decoder). Local first, CDN fallback. -->
<script src="<?= BASE_URL ?>/assets/js/jsQR.min.js"
        onerror="var s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';document.head.appendChild(s);"></script>
<script>
(function(){
  const videoEl   = document.getElementById('qrVideo');
  const canvasEl  = document.getElementById('qrCanvas');
  const ctx       = canvasEl.getContext('2d');
  const statusEl  = document.getElementById('scanStatus');
  const tokenIn   = document.getElementById('qrTokenField');
  const form      = document.getElementById('scanSubmitForm');
  const startBtn  = document.getElementById('startCamBtn');
  const placeholder = document.getElementById('cameraPlaceholder');
  const submitBtn = document.getElementById('submitManualBtn');
  const manualIn  = document.getElementById('manualToken');

  let stream    = null;
  let scanning  = false;
  let submitted = false;

  function haptic(){
    // Short vibration feedback on successful decode (mobile only; safe no-op elsewhere)
    try { if (navigator.vibrate) navigator.vibrate([80, 40, 80]); } catch(_){}
  }

  function beep(){
    // Optional short beep via WebAudio; silently no-op if blocked / unsupported
    try {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      const ac = new AC();
      const o = ac.createOscillator();
      const g = ac.createGain();
      o.type='sine'; o.frequency.value=880;
      g.gain.value=0.05;
      o.connect(g); g.connect(ac.destination);
      o.start();
      setTimeout(function(){ o.stop(); ac.close && ac.close(); }, 140);
    } catch(_){}
  }

  function submitToken(t){
    if (submitted) return;
    t = (t || '').trim();
    if (!t) return;
    submitted = true;
    haptic(); beep();
    if (statusEl) statusEl.textContent = <?= json_encode(t('qr_submitting')) ?>;
    stopCamera();
    tokenIn.value = t;
    form.submit();
  }

  function stopCamera(){
    scanning = false;
    if (stream){
      stream.getTracks().forEach(function(tr){ try { tr.stop(); } catch(_){} });
      stream = null;
    }
    videoEl.style.display = 'none';
  }

  function extractTokenFromUrl(text){
    // Accept either bare token or a scan URL like ".../scan.php?token=XYZ"
    if (!text) return '';
    try {
      const u = new URL(text);
      const q = u.searchParams.get('token');
      if (q) return q;
    } catch(_) { /* not a URL, treat as bare code */ }
    return text.trim();
  }

  function loop(){
    if (!scanning) return;
    if (videoEl.readyState === videoEl.HAVE_ENOUGH_DATA){
      canvasEl.width  = videoEl.videoWidth;
      canvasEl.height = videoEl.videoHeight;
      ctx.drawImage(videoEl, 0, 0, canvasEl.width, canvasEl.height);
      const img = ctx.getImageData(0, 0, canvasEl.width, canvasEl.height);
      if (typeof jsQR === 'function'){
        const code = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
        if (code && code.data){
          submitToken(extractTokenFromUrl(code.data));
          return;
        }
      }
    }
    requestAnimationFrame(loop);
  }

  startBtn.addEventListener('click', function(){
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
      statusEl.textContent = 'Camera not supported on this device. Use manual code entry below.';
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
      .then(function(s){
        stream = s;
        videoEl.srcObject = s;
        videoEl.setAttribute('playsinline', true);
        placeholder.style.display = 'none';
        videoEl.style.display = 'block';
        videoEl.play();
        statusEl.textContent = <?= json_encode(t('qr_camera_scanning')) ?>;
        scanning = true;
        requestAnimationFrame(loop);
      })
      .catch(function(err){
        statusEl.textContent = 'Camera error: ' + (err.message || err) + '. Use manual entry.';
      });
  });

  submitBtn.addEventListener('click', function(){ submitToken(manualIn.value); });
  manualIn.addEventListener('keydown', function(e){ if (e.key === 'Enter'){ e.preventDefault(); submitToken(manualIn.value); }});

  <?php if ($prefilled !== ''): ?>
  document.addEventListener('DOMContentLoaded', function(){ submitToken(<?= json_encode($prefilled) ?>); });
  <?php endif; ?>
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
