<?php
// Shared "profile photo" card for admin/operator account pages (photo is stored in the database).
// Usage: include once, call mbus_photo_handle($conn,$uid) before output, then mbus_photo_card($conn,$uid,$name,$role).
require_once __DIR__ . '/csrf.php';

function mbus_photo_handle($conn, int $uid): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['avatar_data'])) return [null, null];
    if (!csrf_validate_token()) return [null, 'Security validation failed. Please try again.'];
    $raw = (string)$_POST['avatar_data'];
    if ($raw === 'remove') {
        $ok = mbus_db_prepare($conn, "UPDATE users SET profile_picture_b64 = NULL, profile_picture_mime = NULL WHERE user_id = ?", [$uid]);
        return $ok === false ? [null, "Couldn't remove the photo."] : ['Photo removed', null];
    }
    if (preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', $raw, $m) && strlen($m[2]) <= 800000) {
        $bin = base64_decode($m[2], true);
        $info = $bin === false ? false : @getimagesizefromstring($bin);
        if ($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) && $info[0] <= 1024 && $info[1] <= 1024) {
            $ok = mbus_db_prepare($conn, "UPDATE users SET profile_picture_b64 = ?, profile_picture_mime = ? WHERE user_id = ?", [base64_encode($bin), $info['mime'], $uid]);
            return $ok === false ? [null, "Couldn't save the photo."] : ['Photo updated', null];
        }
    }
    return [null, "That image couldn't be used. Try a JPG or PNG."];
}

function mbus_photo_card($conn, int $uid, string $name, string $roleLabel, ?string $ok, ?string $err): void {
    $q = mbus_db_prepare($conn, "SELECT (profile_picture_b64 IS NOT NULL AND profile_picture_b64 <> '') AS has_pic, LEFT(MD5(COALESCE(profile_picture_b64,'')),8) AS v FROM users WHERE user_id = ?", [$uid]);
    $r = $q === false ? false : mbus_db_fetch_assoc($q);
    $has = $r && !empty($r['has_pic']) && $r['has_pic'] !== 'f';
    $initial = strtoupper(substr(trim($name) ?: 'U', 0, 1));
    ?>
    <link rel="stylesheet" href="../Assets/css/commuter_profile.css">
    <?php if ($ok) { ?><div class="alert alert-success" role="status" style="max-width:720px;margin:0 auto 12px;"><?php echo htmlspecialchars($ok); ?></div><?php } ?>
    <?php if ($err) { ?><div class="alert alert-error" role="alert" style="max-width:720px;margin:0 auto 12px;"><?php echo htmlspecialchars($err); ?></div><?php } ?>
    <section class="card pf-hero profile-page" style="max-width:720px;margin:0 auto 12px;">
        <div class="pf-photo">
            <?php if ($has) { ?><img id="pfImg" src="../avatar.php?v=<?php echo htmlspecialchars($r['v']); ?>" alt="Your photo" width="96" height="96">
            <?php } else { ?><span class="pf-initial" aria-hidden="true"><?php echo htmlspecialchars($initial); ?></span><?php } ?>
            <label class="pf-cam" for="pfFile" title="Change photo"><i class="fa fa-camera" aria-hidden="true"></i><span class="sr-only">Change photo</span></label>
        </div>
        <h2 class="pf-name"><?php echo htmlspecialchars($name); ?></h2>
        <span class="ptype-tag"><?php echo htmlspecialchars($roleLabel); ?></span>
        <?php if ($has) { ?><button type="button" class="pf-link pf-remove" id="pfRemove">Remove photo</button><?php } ?>
        <input type="file" id="pfFile" accept="image/jpeg,image/png,image/webp" class="sr-only">
        <form method="POST" id="pfPhotoForm"><?php csrf_field(); ?><input type="hidden" name="avatar_data" id="pfData"></form>
    </section>
    <script>
    (function(){
      var file=document.getElementById('pfFile'),data=document.getElementById('pfData'),form=document.getElementById('pfPhotoForm');
      file.addEventListener('change',function(){var f=file.files&&file.files[0];if(!f)return;var img=new Image(),url=URL.createObjectURL(f);
        img.onload=function(){var n=384,s=Math.min(img.width,img.height),c=document.createElement('canvas');c.width=c.height=n;
          c.getContext('2d').drawImage(img,(img.width-s)/2,(img.height-s)/2,s,s,0,0,n,n);URL.revokeObjectURL(url);data.value=c.toDataURL('image/jpeg',0.85);form.submit();};
        img.onerror=function(){alert('That file is not a usable image.');};img.src=url;});
      var rm=document.getElementById('pfRemove');
      if(rm)rm.addEventListener('click',function(){if(confirm('Remove your photo?')){data.value='remove';form.submit();}});
    })();
    </script>
    <?php
}
