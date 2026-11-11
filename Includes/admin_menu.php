<?php
/* Account-menu entry for ID approvals, with a pending count badge. */
function mbus_admin_approvals_link($conn): string {
    $q = mbus_db_prepare($conn, "SELECT COUNT(*) AS c FROM commuter_verification WHERE verification_status = 'Pending'", []);
    $row = $q === false ? false : mbus_db_fetch_assoc($q);
    $n = $row ? (int)$row['c'] : 0;
    $q2 = mbus_db_prepare($conn, "SELECT COUNT(*) AS c FROM reservation WHERE discount_status = 'Pending' AND id_image_b64 IS NOT NULL", []);
    $row2 = $q2 === false ? false : mbus_db_fetch_assoc($q2);
    $n += $row2 ? (int)$row2['c'] : 0;
    $badge = $n > 0 ? ' <span class="menu-badge">' . $n . '</span>' : '';
    return '<a href="verifications.php" role="menuitem"><i class="fa fa-id-card" aria-hidden="true"></i> ID approvals' . $badge . '</a>';
}
