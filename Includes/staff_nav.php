<?php
/* Navigation for the staff pages in /Operator/ (Driver and Conductor), kept in one place so every page shows the same menu.
 * All links are relative to /Operator/.
 *
 *   Driver:    Home, My Bus, Scan, Riders, Waiting   (Scan sits in the middle, Riders second to last, like the Conductor)
 *   Conductor: Home, Trips, Scan, Riders, Waiting
 *
 * Roles: the Driver owns the bus and its GCash account setup; the Conductor checks and approves the GCash payments.
 */

/** 'driver', 'conductor', or '' for anyone else. */
function mbus_staff_role(): string
{
    $role = strtolower((string)($_SESSION['user_type'] ?? ''));
    return in_array($role, ['driver', 'conductor'], true) ? $role : '';
}

/** key => [href, icon, short label (bottom bar), long label (sidebar)] */
function mbus_staff_nav_items(string $role): array
{
    if (strtolower($role) === 'conductor') {
        return [
            'home'    => ['../Conductor/conductor_dashboard.php', 'fa-home', 'Home', 'Dashboard'],
            'trips'   => ['manage_trips.php', 'fa-bus', 'Trips', 'Manage Trips'],
            'scan'    => ['scan.php', 'fa-qrcode', 'Scan', 'Scan Ticket'],
            'riders'  => ['passengers.php', 'fa-users', 'Riders', 'Riders'],
            'waiting' => ['waiting.php', 'fa-hourglass-half', 'Waiting', 'Waiting'],
        ];
    }
    return [
        'home'    => ['operator_dashboard.php', 'fa-home', 'Home', 'Dashboard'],
        'mybus'   => ['my_bus.php', 'fa-bus-simple', 'My Bus', 'My Bus'],
        'scan'    => ['scan.php', 'fa-qrcode', 'Scan', 'Scan Ticket'],
        'riders'  => ['passengers.php', 'fa-users', 'Riders', 'Riders'],
        'waiting' => ['waiting.php', 'fa-hourglass-half', 'Waiting', 'Waiting'],
    ];
}

/** The bottom bar. $active is a key from mbus_staff_nav_items(), or '' when no item matches the page. */
function mbus_staff_bottom_nav(string $role, string $active = ''): string
{
    $out = '<nav class="bottom-nav" role="navigation" aria-label="Main navigation">';
    foreach (mbus_staff_nav_items($role) as $key => $item) {
        $on = $key === $active;
        $out .= '<a href="' . $item[0] . '" class="bottom-nav-item' . ($on ? ' active' : '') . '"' . ($on ? ' aria-current="page"' : '') . '>'
            . '<i class="fa ' . $item[1] . '" aria-hidden="true"></i><span>' . $item[2] . '</span></a>';
    }
    return $out . '</nav>';
}

/** The sidebar links (desktop). */
function mbus_staff_sidebar_nav(string $role, string $active = ''): string
{
    $label = strtolower($role) === 'conductor' ? 'Conductor' : 'Driver';
    $out = '<nav class="menu-top" aria-label="' . $label . ' menu">';
    foreach (mbus_staff_nav_items($role) as $key => $item) {
        $out .= '<a href="' . $item[0] . '"' . ($key === $active ? ' aria-current="page"' : '') . '>'
            . '<i class="fa ' . $item[1] . '" aria-hidden="true"></i> ' . $item[3] . '</a>';
    }
    return $out . '</nav>';
}

/**
 * The links in the profile dropdown, between the name header and Log out.
 * Driver: My Bus, GCash Account (setup), Analytics, My Profile.
 * Conductor: GCash Payments (check and approve), Analytics, My Profile.
 */
function mbus_staff_profile_links(string $role, string $active = '', string $badge = ''): string
{
    $links = strtolower($role) === 'conductor'
        ? [
            'payments' => ['payments.php', 'fa-wallet', 'GCash Payments'],
        ]
        : [
            'mybus'  => ['my_bus.php', 'fa-bus-simple', 'My Bus'],
            'gcash'  => ['gcash_account.php', 'fa-mobile-screen', 'GCash Account'],
        ];
    $links['reports'] = ['reports.php', 'fa-chart-line', 'Analytics'];
    $links['profile'] = ['profile.php', 'fa-user', 'My Profile'];

    $out = '';
    foreach ($links as $key => $item) {
        $on = $key === $active;
        $out .= '<a href="' . $item[0] . '" role="menuitem"' . ($on ? ' aria-current="page"' : '') . '>'
            . '<i class="fa ' . $item[1] . '" aria-hidden="true"></i> ' . $item[2]
            . ($key === 'payments' ? $badge : '') . '</a>';
    }
    return $out;
}
