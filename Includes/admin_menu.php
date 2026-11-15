<?php
/* Account-menu entry for ID approvals, with a pending count badge. */
function mbus_admin_approvals_link($conn): string {
    $q = mbus_db_prepare($conn, "SELECT COUNT(*) AS c FROM commuter_verification WHERE verification_status = 'Pending'", []);
    $row = $q === false ? false : mbus_db_fetch_assoc($q);
    $n = $row ? (int)$row['c'] : 0;
    $badge = $n > 0 ? ' <span class="menu-badge">' . $n . '</span>' : '';
    $oq = mbus_db_prepare($conn, "SELECT COUNT(*) AS c FROM operator_application WHERE application_status::text = 'Pending'", []);
    $orow = $oq === false ? false : mbus_db_fetch_assoc($oq);
    $on = $orow ? (int)$orow['c'] : 0;
    $obadge = $on > 0 ? ' <span class="menu-badge">' . $on . '</span>' : '';
    $gq = mbus_db_prepare($conn, "SELECT COUNT(*) AS c FROM paymongo_checkout WHERE status = 'Submitted'", []);
    $grow = $gq === false ? false : mbus_db_fetch_assoc($gq);
    $gn = $grow ? (int)$grow['c'] : 0;
    $gbadge = $gn > 0 ? ' <span class="menu-badge">' . $gn . '</span>' : '';
    return '<a href="payment_verifications.php" role="menuitem"><i class="fa fa-receipt" aria-hidden="true"></i> GCash payments' . $gbadge . '</a>'
        . '<a href="verifications.php" role="menuitem"><i class="fa fa-id-card" aria-hidden="true"></i> ID approvals' . $badge . '</a>'
        . '<a href="operator_applications.php" role="menuitem"><i class="fa fa-user-check" aria-hidden="true"></i> Operator applications' . $obadge . '</a>';
}
