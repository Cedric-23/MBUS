<?php
session_start();
include "../config/db_connect.php";
include "../Includes/activity_log.php";
include "../Includes/email_helper.php";
include "../Includes/csrf.php";
include "../Includes/fare.php";

if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* GET USER DATA */
$user_sql = "SELECT user_id, full_name, email, phone_number, user_type, (profile_picture_b64 IS NOT NULL AND profile_picture_b64 <> '') AS has_pic, LEFT(MD5(COALESCE(profile_picture_b64,'')),8) AS pic_v FROM users WHERE user_id = ?";
$user_query = mbus_db_prepare($conn, $user_sql, [$user_id]);
$user_data = $user_query === false ? false : mbus_db_fetch_assoc($user_query);
if (!$user_data) {
    $user_data = ['user_id'=>$user_id,'full_name'=>'','email'=>'','phone_number'=>'','user_type'=>'Regular','has_pic'=>false,'pic_v'=>''];
}

$passenger_type = mbus_passenger_type($conn, (int)$user_id);
$vq = mbus_db_prepare($conn, "SELECT verification_status, verification_type, rejection_reason FROM commuter_verification WHERE user_id = ? ORDER BY verification_id DESC LIMIT 1", [$user_id]);
$latest_v = $vq === false ? false : mbus_db_fetch_assoc($vq);

/* HANDLE POSTS */
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!csrf_validate_token()){
        $error = "Security validation failed. Please try again.";
    } elseif(isset($_POST['update_profile'])){
        $full_name = trim($_POST['full_name'] ?? '');
        $phone_number = preg_replace('/\D+/', '', (string)($_POST['phone_number'] ?? ''));
        if(strlen($phone_number) === 12 && strpos($phone_number, '63') === 0) $phone_number = '0' . substr($phone_number, 2);
        elseif(strlen($phone_number) === 10 && $phone_number[0] === '9') $phone_number = '0' . $phone_number;

        if($full_name === ''){
            $error = "Name can't be empty.";
        } elseif ($phone_number !== '' && !preg_match('/^09\d{9}$/', $phone_number)) {
            $error = "Phone number must be 11 digits starting with 09 (e.g., 09123456789).";
        } else {
            mbus_db_prepare($conn,"UPDATE users SET full_name = ?, phone_number = ? WHERE user_id = ?", [$full_name, $phone_number, $user_id]);
            log_activity($conn, $user_id, ACTION_PROFILE_UPDATE, "Profile information updated");
            $_SESSION['full_name'] = $full_name;
            $success = "Changes saved";
        }
    } elseif(isset($_POST['avatar_data'])){
        $raw = (string)$_POST['avatar_data'];
        if($raw === 'remove'){
            $ok = mbus_db_prepare($conn,"UPDATE users SET profile_picture_b64 = NULL, profile_picture_mime = NULL WHERE user_id = ?", [$user_id]);
            if($ok === false) $error = "Couldn't remove the photo. Please try again."; else $success = "Photo removed";
        } elseif(preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', $raw, $m) && strlen($m[2]) <= 800000){
            $bin = base64_decode($m[2], true);
            $info = $bin === false ? false : @getimagesizefromstring($bin);
            if($info && in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true) && $info[0] <= 1024 && $info[1] <= 1024){
                $ok = mbus_db_prepare($conn,"UPDATE users SET profile_picture_b64 = ?, profile_picture_mime = ? WHERE user_id = ?", [base64_encode($bin), $info['mime'], $user_id]);
                if($ok === false) $error = "Couldn't save the photo. Please try again."; else $success = "Photo updated";
            } else { $error = "That image couldn't be used. Try a JPG or PNG."; }
        } else { $error = "That image couldn't be used. Try a JPG or PNG."; }
    }
    $user_query = mbus_db_prepare($conn, $user_sql, [$user_id]);
    $fresh = $user_query === false ? false : mbus_db_fetch_assoc($user_query);
    if($fresh) $user_data = $fresh;
}
$has_pic = !empty($user_data['has_pic']) && $user_data['has_pic'] !== 'f';
$initial = strtoupper(substr(trim($user_data['full_name'] ?? '') ?: 'U', 0, 1));
$ph = $user_data['phone_number'] ?? '';
if(isset($error) && isset($_POST['phone_number'])) $ph = $_POST['phone_number'];
$ph = strlen(preg_replace('/\D+/', '', (string)$ph)) < 2 ? '' : $ph;
$phone_locked = ($ph !== '' && !isset($error));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - MBUS</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../Assets/css/responsive.css">
    <link rel="stylesheet" href="../Assets/css/commuter_sidebar.css">
    <link rel="stylesheet" href="../Assets/css/profile.css">
    <link rel="stylesheet" href="../Assets/css/mobile-optimized.css">
    <link rel="stylesheet" href="../Assets/css/bottom-nav.css">
    <link rel="stylesheet" href="../Assets/css/app-header.css">
    <link rel="stylesheet" href="../Assets/css/sidebar-footer-nav.css">
    <link rel="stylesheet" href="../Assets/css/minimal-ui.css">
    <link rel="stylesheet" href="../Assets/css/commuter_verification.css">
    <link rel="stylesheet" href="../Assets/css/commuter_profile.css">
    <link rel="stylesheet" href="../Assets/css/ui2026.css?v=<?php echo filemtime(__DIR__ . '/../Assets/css/ui2026.css'); ?>">
</head>
<body class="has-bottom-nav verify-page profile-page">

<main class="main" role="main">

<header class="page-header">
    <div class="page-header-left">
        <a href="commuter_dashboard.php" class="back-btn" aria-label="Back to home"><i class="fa fa-arrow-left" aria-hidden="true"></i></a>
        <h1>My Profile</h1>
    </div>

            <div class="page-header-right">
                <div class="profile-menu-wrap">
                    <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profileMenu" aria-label="Open account menu">
                        <span class="profile-avatar" aria-hidden="true"><?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></span>
                    </button>
                    <div class="profile-menu" id="profileMenu" role="menu">
                        <div class="profile-menu-header">
                            <span class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Account'); ?></span>
                            <span class="profile-menu-role">Commuter account</span>
                        </div>
                        <a href="profile.php" role="menuitem"><i class="fa fa-user" aria-hidden="true"></i> My Profile</a>
                        <a href="../logout.php" class="logout-link" role="menuitem"><i class="fa fa-sign-out" aria-hidden="true"></i> Log out</a>
                    </div>
                </div>
            </div>
</header>

<?php foreach([['success','success'],['error','error']] as [$var,$type]){ if(isset($$var)){ ?>
<div class="alert alert-<?php echo $type; ?>" role="<?php echo $type === 'error' ? 'alert' : 'status'; ?>"><?php echo htmlspecialchars($$var); ?></div>
<?php } } ?>

<section class="card pf-hero">
    <div class="pf-photo">
        <?php if($has_pic){ ?>
            <img id="pfImg" src="../avatar.php?v=<?php echo htmlspecialchars($user_data['pic_v']); ?>" alt="Your photo" width="96" height="96">
        <?php } else { ?>
            <span id="pfInitial" class="pf-initial" aria-hidden="true"><?php echo htmlspecialchars($initial); ?></span>
            <img id="pfImg" alt="Your photo" width="96" height="96" hidden>
        <?php } ?>
        <label class="pf-cam" for="pfFile" title="Change photo"><i class="fa fa-camera" aria-hidden="true"></i><span class="sr-only">Change photo</span></label>
    </div>
    <h2 class="pf-name"><?php echo htmlspecialchars($user_data['full_name'] ?: 'Your name'); ?></h2>
    <p class="pf-email"><?php echo htmlspecialchars($user_data['email'] ?? ''); ?></p>
    <span class="ptype-tag"><?php echo htmlspecialchars($passenger_type); ?> passenger</span>
    <?php if($has_pic){ ?><button type="button" class="pf-link pf-remove" id="pfRemove">Remove photo</button><?php } ?>
    <input type="file" id="pfFile" accept="image/jpeg,image/png,image/webp" class="sr-only">
    <form method="POST" id="pfPhotoForm">
        <?php csrf_field(); ?>
        <input type="hidden" name="avatar_data" id="pfData">
    </form>
</section>

<form method="POST" class="card pf-form" id="pfForm" novalidate>
    <?php csrf_field(); ?>
    <input type="hidden" name="update_profile" value="1">

    <div class="pf-field">
        <label for="full_name">Full name</label>
        <input type="text" name="full_name" id="full_name" value="<?php echo htmlspecialchars($user_data['full_name'] ?? ''); ?>" class="form-control" autocomplete="name" required>
    </div>

    <div class="pf-field">
        <label for="phone_number">Phone number</label>
        <div class="pf-lock">
            <input type="tel" name="phone_number" id="phone_number" value="<?php echo htmlspecialchars($ph); ?>" class="form-control" inputmode="tel" autocomplete="tel" maxlength="16" placeholder="09XXXXXXXXX"<?php echo $phone_locked ? ' readonly aria-readonly="true"' : ''; ?>>
            <?php if($phone_locked){ ?><button type="button" class="pf-link" id="phoneChange">Change</button><?php } ?>
        </div>
    </div>

    <div class="pf-field">
        <label for="user_type">Passenger type</label>
        <div class="pf-lock">
            <input type="text" id="user_type" value="<?php echo htmlspecialchars($passenger_type); ?>" class="form-control" disabled aria-disabled="true">
            <a class="pf-link" href="verification.php?from=profile"><?php echo $passenger_type !== 'Regular' ? 'Manage' : ($latest_v && $latest_v['verification_status'] === 'Pending' ? 'View status' : 'Verify ID'); ?></a>
        </div>
        <small class="form-hint"><?php
            if($passenger_type !== 'Regular') echo '20% fare discount is active.';
            elseif($latest_v && $latest_v['verification_status'] === 'Pending') echo 'Your ' . htmlspecialchars($latest_v['verification_type']) . ' ID is under review.';
            elseif($latest_v && $latest_v['verification_status'] === 'Rejected') echo 'Your last ID was rejected. You can send a new one.';
            else echo 'Student, senior or PWD? Verify your ID for 20% off.';
        ?></small>
    </div>

    <button type="submit" class="btn btn-primary btn-block" id="pfSave" disabled>Save changes</button>
</form>

</main>

<script>
(function(){
  var form=document.getElementById('pfForm'), save=document.getElementById('pfSave');
  var start=new FormData(form);
  function dirty(){var f=new FormData(form);return f.get('full_name')!==start.get('full_name')||f.get('phone_number')!==start.get('phone_number');}
  form.addEventListener('input',function(){save.disabled=!dirty();});
  <?php if(isset($error)){ ?>save.disabled=false;<?php } ?>
  var pc=document.getElementById('phoneChange');
  if(pc){pc.addEventListener('click',function(){var i=document.getElementById('phone_number');i.removeAttribute('readonly');pc.remove();i.focus();i.select();});}

  var file=document.getElementById('pfFile'), data=document.getElementById('pfData'), pform=document.getElementById('pfPhotoForm');
  file.addEventListener('change',function(){
    var f=file.files&&file.files[0]; if(!f) return;
    var img=new Image(), url=URL.createObjectURL(f);
    img.onload=function(){
      var n=384, side=Math.min(img.width,img.height), c=document.createElement('canvas');
      c.width=c.height=n;
      c.getContext('2d').drawImage(img,(img.width-side)/2,(img.height-side)/2,side,side,0,0,n,n);
      URL.revokeObjectURL(url);
      data.value=c.toDataURL('image/jpeg',0.85);
      pform.submit();
    };
    img.onerror=function(){alert('That file is not a usable image.');};
    img.src=url;
  });
  var rm=document.getElementById('pfRemove');
  if(rm) rm.addEventListener('click',function(){ if(confirm('Remove your photo?')){data.value='remove';pform.submit();}});
})();
</script>

<!-- Bottom Navigation -->
<nav class="bottom-nav" role="navigation" aria-label="Main navigation">
    <a href="commuter_dashboard.php" class="bottom-nav-item">
        <i class="fa fa-home" aria-hidden="true"></i>
        <span>Home</span>
    </a>
    <a href="schedule.php" class="bottom-nav-item">
        <i class="fa fa-calendar" aria-hidden="true"></i>
        <span>Schedule</span>
    </a>
    <a href="live.php" class="bottom-nav-item">
        <i class="fa fa-location-dot" aria-hidden="true"></i>
        <span>Live</span>
    </a>
    <a href="my_reservations.php" class="bottom-nav-item">
        <i class="fa fa-ticket" aria-hidden="true"></i>
        <span>Trips</span>
    </a>
    <a href="waiting.php" class="bottom-nav-item">
        <i class="fa fa-hourglass-half" aria-hidden="true"></i><span>Waiting</span>
    </a>
</nav>

<script src="../Assets/js/app-header.js"></script>
</body>
</html>
