<?php
/* Driver ID / licence renewal: a driver whose ID is expiring or expired sends a new expiry date and photo,
 * the admin approves it, and the stored licence expiry is updated. */

function mbus_renewal_ensure_table($conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    mbus_db_prepare($conn, "CREATE TABLE IF NOT EXISTS id_renewal (
        renewal_id SERIAL PRIMARY KEY,
        user_id INT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
        new_expiry DATE NOT NULL,
        document_b64 TEXT,
        document_mime VARCHAR(50),
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        submitted_at TIMESTAMP NOT NULL DEFAULT NOW(),
        reviewed_at TIMESTAMP,
        reviewed_by INT,
        rejection_reason TEXT)", []);
    mbus_db_prepare($conn, "CREATE INDEX IF NOT EXISTS id_renewal_user_idx ON id_renewal (user_id, renewal_id DESC)", []);
}

/** The driver's approved licence expiry: ['expiry' => 'Y-m-d'|null, 'days' => int|null, 'state' => none|ok|soon|expired]. */
function mbus_driver_id_status($conn, int $user_id, int $warn_days = 14): array
{
    $q = mbus_db_prepare($conn, "SELECT license_expiry FROM operator_application
        WHERE user_id = ? AND application_status::text = 'Approved' ORDER BY application_id DESC LIMIT 1", [$user_id]);
    $r = $q === false ? false : mbus_db_fetch_assoc($q);
    if (!$r || empty($r['license_expiry'])) return ['expiry' => null, 'days' => null, 'state' => 'none'];
    $days = (int)floor((strtotime((string)$r['license_expiry']) - strtotime(date('Y-m-d'))) / 86400);
    return ['expiry' => (string)$r['license_expiry'], 'days' => $days,
        'state' => $days < 0 ? 'expired' : ($days <= $warn_days ? 'soon' : 'ok')];
}

function mbus_renewal_latest($conn, int $user_id)
{
    mbus_renewal_ensure_table($conn);
    $q = mbus_db_prepare($conn, "SELECT renewal_id, new_expiry, status, submitted_at, rejection_reason
        FROM id_renewal WHERE user_id = ? ORDER BY renewal_id DESC LIMIT 1", [$user_id]);
    return $q === false ? false : mbus_db_fetch_assoc($q);
}

/** Banner for the driver's home page: only shown when the ID is expiring, expired, or a renewal needs attention. */
function mbus_id_expiry_banner($conn, int $user_id): string
{
    $s = mbus_driver_id_status($conn, $user_id);
    $latest = mbus_renewal_latest($conn, $user_id);
    $pending = $latest && $latest['status'] === 'Pending';
    $rejected = $latest && $latest['status'] === 'Rejected';
    if ($pending) { $msg = 'Your renewed ID is waiting for admin approval.'; $tone = 'info'; }
    elseif ($s['state'] === 'expired') { $msg = 'Your ID expired on ' . date('M j, Y', strtotime($s['expiry'])) . '. Send a renewed ID.'; $tone = 'bad'; }
    elseif ($s['state'] === 'soon') { $msg = 'Your ID expires ' . ($s['days'] === 0 ? 'today' : 'in ' . $s['days'] . ' day' . ($s['days'] === 1 ? '' : 's')) . ' (' . date('M j, Y', strtotime($s['expiry'])) . '). Renew it now.'; $tone = 'warn'; }
    elseif ($rejected && $s['state'] !== 'ok') { $msg = 'Your renewed ID was rejected. Send a clearer one.'; $tone = 'bad'; }
    else return '';
    $c = ['info' => ['#eaf2ff', '#1d4fb4', '#cfe0fb'], 'warn' => ['#fff4e0', '#8a5a00', '#ffd99a'], 'bad' => ['#fdecec', '#b42318', '#f5c2c0']][$tone];
    return '<a href="profile.php#id-renewal" role="status" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px;padding:12px 14px;border-radius:16px;background:' . $c[0] . ';color:' . $c[1] . ';border:1px solid ' . $c[2] . ';text-decoration:none;font-weight:600;font-size:.9rem">'
        . '<span><i class="fa fa-id-card" aria-hidden="true"></i> ' . htmlspecialchars($msg) . '</span>'
        . '<span style="white-space:nowrap">' . ($pending ? 'View' : 'Renew') . ' &rsaquo;</span></a>';
}
