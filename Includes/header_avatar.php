<?php
/* One avatar for the profile icon on every page and every role.
 * Shows the saved photo (served from the database, so it survives Render redeploys),
 * or the user's first initial when there is no photo. Cached in the session;
 * call mbus_avatar_forget() after a photo is changed or removed. */
function mbus_avatar_forget(): void { unset($_SESSION['avatar_cache']); }

function mbus_header_avatar(string $base = '../'): string
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $name = trim((string)($_SESSION['full_name'] ?? ''));
    $initial = strtoupper(function_exists('mb_substr') ? mb_substr($name !== '' ? $name : 'U', 0, 1) : substr($name !== '' ? $name : 'U', 0, 1));

    if ($uid > 0 && (!isset($_SESSION['avatar_cache']) || ($_SESSION['avatar_cache']['uid'] ?? 0) !== $uid)) {
        $cache = ['uid' => $uid, 'has' => false, 'v' => ''];
        $conn = $GLOBALS['conn'] ?? null;
        if ($conn && function_exists('mbus_db_prepare')) {
            try {
                $q = mbus_db_prepare($conn, "SELECT (profile_picture_b64 IS NOT NULL AND profile_picture_b64 <> '') AS has_pic, LEFT(MD5(COALESCE(profile_picture_b64,'')),8) AS v FROM users WHERE user_id = ?", [$uid]);
                $r = $q === false ? false : mbus_db_fetch_assoc($q);
                if ($r) { $cache['has'] = !empty($r['has_pic']) && $r['has_pic'] !== 'f'; $cache['v'] = (string)$r['v']; }
            } catch (Throwable $e) { error_log('avatar lookup failed: ' . $e->getMessage()); }
        }
        $_SESSION['avatar_cache'] = $cache;
    }

    $c = $_SESSION['avatar_cache'] ?? ['has' => false, 'v' => ''];
    $initial_html = htmlspecialchars($initial, ENT_QUOTES, 'UTF-8');
    if (!empty($c['has'])) {
        return '<img src="' . htmlspecialchars($base, ENT_QUOTES) . 'avatar.php?v=' . htmlspecialchars((string)$c['v'], ENT_QUOTES)
            . '" alt="" data-fallback="' . $initial_html . '" onerror="this.parentElement.textContent=this.dataset.fallback;">';
    }
    return $initial_html;
}
