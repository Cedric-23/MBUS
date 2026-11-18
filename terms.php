<?php
$legal_title = 'Terms of Service';
$legal_updated = 'November 2026';
$legal_sections = [
    ['About MBUS', ['MBUS is a bus reservation and trip management system for the Morong to SBMA route. By creating an account or using the service you agree to these terms.']],
    ['Your account', ['Give accurate information when you register and keep your password private. You are responsible for activity under your account. Operators, drivers and conductors may be asked to submit ID and documents for admin approval before they can use their role.']],
    ['Reservations and seats', ['A seat is held for you for a limited time after you reserve it. If payment is not confirmed before the timer ends, the hold is released and the seat becomes available to others.', 'You may reserve for more than one seat on the same trip. Please arrive at your pickup point on time; the operator may release seats of passengers who do not show up.']],
    ['Payments and fares', ['Fares are shown before you pay. Payments are made to the operator through GCash and are confirmed using the reference number you enter after paying. Entering a false or someone else\'s reference number is not allowed.', 'Refunds and cancellations follow the operator\'s policy. A cancelled reservation releases the seat immediately.']],
    ['Discounts', ['Student, senior citizen and PWD discounts need a valid ID that an admin has approved. Using someone else\'s ID or a false ID may lead to your account being suspended.']],
    ['Acceptable use', ['Do not misuse the service: no fake reservations, no attempts to access other accounts or data, and no interfering with the system. We may suspend accounts that break these rules.']],
    ['Live location', ['Some features use your location, for example to show where waiting passengers are or where a bus is. This only happens when you allow your device to share it.']],
    ['Changes and contact', ['We may update these terms and will post the new date above. Continued use means you accept the changes. For questions, contact the MBUS team through your operator or the school project administrators.']],
];
require __DIR__ . '/Includes/legal_page.php';
