<?php
/* Account-menu entries for ID approvals and operator applications. */
function mbus_admin_approvals_link($conn): string {
    $q = mbus_db_prepare($conn, "SELECT COUNT(*) AS c FROM commuter_verification WHERE verification_status = 'Pending'", []);
    $row = $q === false ? false : mbus_db_fetch_assoc($q);
    $n = $row ? (int)$row['c'] : 0;
    $badge = $n > 0 ? ' <span class="menu-badge">' . $n . '</span>' : '';
    $oq = mbus_db_prepare($conn, "SELECT COUNT(*) AS c FROM operator_application WHERE application_status::text = 'Pending'", []);
    $orow = $oq === false ? false : mbus_db_fetch_assoc($oq);
    $on = $orow ? (int)$orow['c'] : 0;
    $obadge = $on > 0 ? ' <span class="menu-badge">' . $on . '</span>' : '';
    require_once dirname(__DIR__) . '/Includes/gcash_manual.php';
    $gn = mbus_gcash_pending($conn) ? 1 : 0;
    $gbadge = $gn ? ' <span class="menu-badge">1</span>' : '';
    return '<a href="verifications.php" role="menuitem"><i class="fa fa-id-card" aria-hidden="true"></i> ID approvals' . $badge . '</a>'
        . '<a href="operator_applications.php" role="menuitem"><i class="fa fa-user-check" aria-hidden="true"></i> Operator applications' . $obadge . '</a>'
        . '<a href="gcash_approval.php" role="menuitem"><i class="fa fa-mobile-screen" aria-hidden="true"></i> GCash account' . $gbadge . '</a>';
}
