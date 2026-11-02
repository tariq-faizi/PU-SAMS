<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/lang.php';
requireLogin();
$db=getDB(); $uid=$_SESSION['user_id']; $pageTitle='Edit Profile';

/* FIX #2 (v23.1) — د post_max_size تپ تجربوي.
 * که PHP تر post_max_size تپ دیټا ولیږي، دواړه $_POST او $_FILES خالي
 * راځي (سربیره CONTENT_LENGTH > 0). ددې حالت تشخیص ترسره کوو او روښانه
 * پیغام ښوو.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $__cl = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($__cl > 0 && empty($_POST) && empty($_FILES)) {
        flashSet('danger', '❗ Upload exceeds server limit. Please use an image under 2MB.');
        redirect(BASE_URL.'/profile/edit.php');
    }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verifyCsrf();
    $act=$_POST['act']??'';
    
    if($act==='photo'){
        $__hasFile = isset($_FILES['photo']) && is_array($_FILES['photo'])
                     && (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if($__hasFile){
            $old=$db->prepare("SELECT photo FROM users WHERE id=?"); $old->execute([$uid]); $old=$old->fetch();
            $uploadErr='';
            $fn=uploadPhoto($_FILES['photo'],$old['photo']??'default.png',$uploadErr);
            if($fn){
                $db->prepare("UPDATE users SET photo=?, updated_at=NOW() WHERE id=?")->execute([$fn,$uid]);
                // Update session immediately so header shows new photo
                $_SESSION['photo']   = $fn;
                $_SESSION['photo_v'] = time();
                // Also refresh from DB to be sure
                $freshRow = $db->prepare("SELECT photo FROM users WHERE id=? LIMIT 1");
                $freshRow->execute([$uid]);
                $freshPhoto = $freshRow->fetchColumn();
                if ($freshPhoto) $_SESSION['photo'] = $freshPhoto;
                if (function_exists('auditLog')) auditLog('profile_photo_update','users',$uid,'User updated own profile photo');
                flashSet('success','✅ Photo updated successfully.');
            } else {
                flashSet('danger', $uploadErr ?: '⚠ ناسم فایل. JPG/PNG/WEBP تر 2MB وکاروئ.');
            }
        } else {
            $__err = (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($__err === UPLOAD_ERR_INI_SIZE || $__err === UPLOAD_ERR_FORM_SIZE) {
                flashSet('danger', '❗ Image exceeds 2MB. Please reduce and try again.');
            } else {
                flashSet('warning','⚠ د اپلوډ لپاره پخوا د انځور فایل وټاکئ.');
            }
        }
        redirect(BASE_URL.'/profile/edit.php');
    }
    
    if($act==='edit_request'){
        $newName=trim($_POST['full_name']??''); $newEmail=trim($_POST['email']??''); $newPhone=trim($_POST['phone']??'');
        $reason=trim($_POST['reason']??'');
        if(!$reason){flashSet('danger',t('reason_required'));redirect(BASE_URL.'/profile/edit.php');}
        $old=$db->prepare("SELECT full_name,email,phone FROM users WHERE id=?"); $old->execute([$uid]); $old=$old->fetch();
        $db->prepare("INSERT INTO edit_requests(request_type,requested_by,target_id,old_value,new_value,reason)
            VALUES('profile',?,?,?,?,?)")
           ->execute([$uid,$uid,json_encode($old),json_encode(['full_name'=>$newName,'email'=>$newEmail,'phone'=>$newPhone]),$reason]);
        auditLog('profile_edit_request','profile',$uid,'Requested profile edit');
        flashSet('info','Profile edit request submitted for approval.');
        redirect(BASE_URL.'/profile/');
    }
}

$user=$db->prepare("SELECT * FROM users WHERE id=?"); $user->execute([$uid]); $user=$user->fetch();
$pendingReq=$db->prepare("SELECT id,created_at FROM edit_requests WHERE requested_by=? AND request_type='profile' AND status='pending' LIMIT 1");
$pendingReq->execute([$uid]); $pendingReq=$pendingReq->fetch();
require_once __DIR__.'/../includes/header.php';
?>
<div class="row justify-content-center"><div class="col-lg-7">
<h5 class="mb-3"><i class="fas fa-user-edit me-2"></i>Edit Profile</h5>
<?php if($pendingReq):?>
<div class="alert alert-warning"><i class="fas fa-clock me-2"></i>
  You have a pending profile edit request (submitted <?=date('d M H:i',strtotime($pendingReq['created_at']))?>) waiting for approval.
</div>
<?php endif;?>

<!-- Photo Update (immediate) -->
<div class="card mb-4">
  <div class="card-header"><i class="fas fa-camera me-2"></i>Update Photo (immediate)</div>
  <div class="card-body">
    <div class="d-flex align-items-center gap-3 mb-3">
      <?php
        // FIX #2 (v23.1) — cache-buster ترڅو تازه انځور سمدستي وښی.
        $__photoFile = $user['photo'] ?? 'default.png';
        $__photoVer  = $_SESSION['photo_v'] ?? '';
        if ($__photoVer === '' && $__photoFile !== 'default.png') {
            $__photoPath = (defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__).'/uploads/')) . $__photoFile;
            $__photoVer  = is_file($__photoPath) ? (int)@filemtime($__photoPath) : '';
        }
        $__photoQS = $__photoVer !== '' ? ('?v='.urlencode((string)$__photoVer)) : '';
      ?>
      <img src="<?=BASE_URL?>/uploads/<?=h($__photoFile)?><?=h($__photoQS)?>"
           onerror="this.src='<?=BASE_URL?>/assets/images/default.png'"
           width="70" height="70" class="rounded-circle object-fit-cover"/>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <?php csrfField();?><input type="hidden" name="act" value="photo"/>
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)uploadPhotoMaxBytes() ?>"/>
      <div class="input-group">
        <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required/>
        <button class="btn btn-primary">Upload</button>
      </div>
      
    </form>
  </div>
</div>

<!-- Profile Info (needs approval) -->
<div class="card">
  <div class="card-header"><i class="fas fa-info-circle me-2"></i>Edit Personal Info <span class="badge bg-warning ms-2">Requires Approval</span></div>
  <div class="card-body">
    <?php if($pendingReq):?><div class="alert alert-warning py-2 small">Cannot submit another request while one is pending.</div><?php else:?>
    <form method="POST">
      <?php csrfField();?><input type="hidden" name="act" value="edit_request"/>
      <div class="mb-2"><label class="form-label"><?=t('full_name')?></label>
        <input name="full_name" class="form-control" required value="<?=h($user['full_name']??'')?>"/></div>
      <div class="mb-2"><label class="form-label"><?=t('email')?></label>
        <input name="email" type="email" class="form-control" value="<?=h($user['email']??'')?>"/></div>
      <div class="mb-2"><label class="form-label"><?=t('phone')?></label>
        <input name="phone" class="form-control" value="<?=h($user['phone']??'')?>"/></div>
      <div class="mb-3"><label class="form-label"><?=t('reason_required')?> *</label>
        <textarea name="reason" class="form-control" rows="2" required placeholder="Reason for change..."></textarea></div>
      <button class="btn btn-warning">Submit for Approval</button>
      <a href="<?=BASE_URL?>/profile/" class="btn btn-secondary ms-2"><?=t('cancel')?></a>
    </form>
    <?php endif;?>
  </div>
</div>
</div></div>
<?php
function csrfField(){ echo '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.generateCsrfToken().'"/>';}
require_once __DIR__.'/../includes/footer.php'; ?>
