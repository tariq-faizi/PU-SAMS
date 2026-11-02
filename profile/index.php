<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();

$db = getDB();
$uid = (int)($_SESSION['user_id'] ?? 0);
$pageTitle = t('profile');

$stmt = $db->prepare("SELECT u.*, r.label as role_label, d.name as dept_name, f.name as faculty_name,
                             c.name as class_name, c.section as class_section, sem.name as semester_name
    FROM users u
    JOIN roles r ON r.id=u.role_id
    LEFT JOIN departments d ON d.id=u.department_id
    LEFT JOIN faculties f ON f.id=u.faculty_id
    LEFT JOIN classes c ON c.id=u.class_id
    LEFT JOIN semesters sem ON sem.id=u.semester_id
    WHERE u.id=? LIMIT 1");
$stmt->execute([$uid]);
$user = $stmt->fetch();

require_once __DIR__.'/../includes/header.php';
?>
<div class="v6-page-title">
  <div><h4><i class="fas fa-user-circle me-2 text-primary"></i><?=t('profile')?></h4></div>
  <div>
    <a href="<?=BASE_URL?>/profile/edit.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit me-1"></i><?=t('edit_profile')?></a>
    <a href="<?=BASE_URL?>/profile/password.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-key me-1"></i><?=t('change_password')?></a>
  </div>
</div>

<!-- ===== د پروفایل پاک کارت - یوازې اساسي شخصي معلومات ===== -->
<div class="row g-4 justify-content-center">
  <div class="col-lg-9">
    <div class="card profile-card mb-4 shadow-sm">
      <div class="card-body p-4">
        <div class="row align-items-center g-4">
          <div class="col-auto text-center">
            <?php
              // FIX #2 (v23.1) — cache-busting د تازه انځور پروفایل لپاره.
              // پخوانۍ browser zaRرe انځور د cache ددې امله پخپل cache کې ساتله
              // او تازه انځور به سمدستي په پروفایل کې نه ژلوله. اوس له ?v=mtime
              // چخه تیروو ترڅو ېج انځور تازه بیرته لود شي.
              $__photoFile = $user['photo'] ?? 'default.png';
              $__photoVer  = $_SESSION['photo_v'] ?? '';
              if ($__photoVer === '' && $__photoFile !== 'default.png') {
                  $__photoPath = (defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__).'/uploads/')) . $__photoFile;
                  $__photoVer  = is_file($__photoPath) ? (int)@filemtime($__photoPath) : '';
              }
              $__photoQS = $__photoVer !== '' ? ('?v='.urlencode((string)$__photoVer)) : '';
            ?>
            <!-- د Users عکس -->
            <img src="<?= BASE_URL ?>/uploads/<?= h($__photoFile) ?><?= h($__photoQS) ?>"
                 onerror="this.src='<?= BASE_URL ?>/assets/images/default.png'"
                 width="140" height="140"
                 class="rounded-circle object-fit-cover border shadow-sm"
                 style="border-width:4px!important;border-color:#1d4ed8!important"/>
          </div>
          <div class="col">
            <h4 class="mb-2 fw-bold"><?= h($user['full_name']) ?></h4>
            <!-- د رول، پوهنځي او څانګې بیجونه -->
            <div class="d-flex flex-wrap gap-2 mb-3">
              <span class="badge bg-primary fs-6"><?= h(t('role_'.(int)$user['role_id'], [], roleDisplayName((int)$user['role_id']))) ?></span>
              <?php if (!empty($user['faculty_name'])): ?>
                <span class="badge bg-secondary"><i class="fas fa-building-columns me-1"></i><?= h($user['faculty_name']) ?></span>
              <?php endif; ?>
              <?php if (!empty($user['dept_name'])): ?>
                <span class="badge bg-info text-dark"><i class="fas fa-building me-1"></i><?= h($user['dept_name']) ?></span>
              <?php endif; ?>
              <?php if (!empty($user['class_name']) && (int)$user['role_id'] === ROLE_STUDENT): ?>
                <span class="badge bg-light text-dark border"><i class="fas fa-chalkboard me-1"></i><?= h(trim($user['class_name'].' '.($user['class_section'] ?? ''))) ?></span>
              <?php endif; ?>
              <?php if (!empty($user['semester_name'])): ?>
                <span class="badge bg-warning text-dark"><?= h($user['semester_name']) ?></span>
              <?php endif; ?>
            </div>

            <!-- د Users تماس او پیژندنې معلومات -->
            <div class="row g-3 small">
              <div class="col-md-6">
                <div class="info-item"><i class="fas fa-at text-muted me-2"></i><span class="text-muted"><?=t('username')?>:</span> <strong>@<?= h($user['username']) ?></strong></div>
              </div>
              <?php if (!empty($user['email'])): ?>
              <div class="col-md-6">
                <div class="info-item"><i class="fas fa-envelope text-muted me-2"></i><span class="text-muted">Email:</span> <strong><?= h($user['email']) ?></strong></div>
              </div>
              <?php endif; ?>
              <?php if (!empty($user['phone'])): ?>
              <div class="col-md-6">
                <div class="info-item"><i class="fas fa-phone text-muted me-2"></i><span class="text-muted">Phone:</span> <strong><?= h($user['phone']) ?></strong></div>
              </div>
              <?php endif; ?>
              <?php if (!empty($user['student_id'])): ?>
              <div class="col-md-6">
                <div class="info-item"><i class="fas fa-id-card text-muted me-2"></i><span class="text-muted"><?=t('role_4')?> ID:</span> <strong><?= h($user['student_id']) ?></strong></div>
              </div>
              <?php endif; ?>
              <?php if (!empty($user['employee_id'])): ?>
              <div class="col-md-6">
                <div class="info-item"><i class="fas fa-id-badge text-muted me-2"></i><span class="text-muted">Employee ID:</span> <strong><?= h($user['employee_id']) ?></strong></div>
              </div>
              <?php endif; ?>
              <div class="col-md-6">
                <div class="info-item"><i class="fas fa-calendar-plus text-muted me-2"></i><span class="text-muted"><?=t('created_at')?>:</span> <strong><?= h(date('d M Y', strtotime($user['created_at']))) ?></strong></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- د حضور په اړه ډاټا له پروفایل لرې شوې - د Studentsو لپاره د ډشبورډ یا حضور پاڼې ته ولاړ شي -->
  </div>
</div>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
