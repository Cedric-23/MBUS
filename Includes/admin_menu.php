<?php
/* Account-menu entries for ID approvals, staff applications and the GCash account,
 * plus the red dot on the profile icon when any of them is waiting for the admin. */

/* How many items are waiting in each approval queue. Counted once per request. */
function mbus_admin_pending_counts($conn): array {
    static $counts = null;
    if ($counts !== null) return $counts;
    $q = mbus_db_prepare($conn, "SELECT COUNT(*) AS c FROM commuter_verification WHERE verification_status = 'Pending'", []);
    $row = $q === false ? false : mbus_db_fetch_assoc($q);
    $oq = mbus_db_prepare($conn, "SELECT
            (SELECT COUNT(*) FROM operator_application WHERE application_status::text = 'Pending' AND email_verified = TRUE)
          + (SELECT COUNT(*) FROM conductor_application WHERE application_status::text = 'Pending' AND email_verified = TRUE) AS c", []);
    $orow = $oq === false ? false : mbus_db_fetch_assoc($oq);
    $rq = mbus_db_prepare($conn, "SELECT COUNT(*) AS c FROM id_renewal WHERE status = 'Pending'", []);
    if ($rq === false) {   // first run: the renewals table does not exist yet
        require_once dirname(__DIR__) . '/Includes/id_renewal.php';
        mbus_renewal_ensure_table($conn);
        $rq = mbus_db_prepare($conn, "SELECT COUNT(*) AS c FROM id_renewal WHERE status = 'Pending'", []);
    }
    $rrow = $rq === false ? false : mbus_db_fetch_assoc($rq);
    require_once dirname(__DIR__) . '/Includes/gcash_manual.php';
    $counts = [
        'id'    => $row ? (int)$row['c'] : 0,
        'op'    => $orow ? (int)$orow['c'] : 0,
        'renew' => $rrow ? (int)$rrow['c'] : 0,
        'gcash' => mbus_gcash_pending($conn) ? 1 : 0,
    ];
    return $counts;
}

function mbus_admin_approvals_link($conn): string {
    $c = mbus_admin_pending_counts($conn);
    $badge  = $c['id']    > 0 ? ' <span class="menu-badge">' . $c['id'] . '</span>' : '';
    $obadge = $c['op']    > 0 ? ' <span class="menu-badge">' . $c['op'] . '</span>' : '';
    $rbadge = $c['renew'] > 0 ? ' <span class="menu-badge">' . $c['renew'] . '</span>' : '';
    $gbadge = $c['gcash'] > 0 ? ' <span class="menu-badge">' . $c['gcash'] . '</span>' : '';
    return '<a href="reports.php" role="menuitem"><i class="fa fa-file-lines" aria-hidden="true"></i> Reports</a>'
        . '<a href="announcements.php" role="menuitem"><i class="fa fa-bullhorn" aria-hidden="true"></i> Announcements</a>'
        . '<a href="verifications.php" role="menuitem"><i class="fa fa-id-card" aria-hidden="true"></i> ID approvals' . $badge . '</a>'
        . '<a href="conductor_applications.php" role="menuitem"><i class="fa fa-user-check" aria-hidden="true"></i> Staff applications' . $obadge . '</a>'
        . '<a href="id_renewals.php" role="menuitem"><i class="fa fa-id-badge" aria-hidden="true"></i> Driver ID renewals' . $rbadge . '</a>'
        . '<a href="gcash_approval.php" role="menuitem"><i class="fa fa-mobile-screen" aria-hidden="true"></i> GCash account' . $gbadge . '</a>';
}

/* Red dot for the corner of the profile icon (empty string when nothing is waiting). */
function mbus_admin_dot($conn): string {
    $n = array_sum(mbus_admin_pending_counts($conn));
    if ($n <= 0) return '';
    return '<span class="pay-alert-dot"><span class="pay-alert-vh">' . $n . ' item' . ($n === 1 ? '' : 's') . ' to review</span></span>';
}
