<?php
/**
 * Canonical public endpoints for MBUS's Morong–SBMA route.
 * Harbor Point uses the mapped National Book Store frontage pin; it is not a surveyed curb coordinate.
 */
function mbus_route_known_terminal(?string $name): ?array
{
    $key = strtolower(trim((string)$name));
    $key = preg_replace('/[^a-z0-9]+/', ' ', $key) ?? '';
    $key = trim(preg_replace('/\s+/', ' ', $key) ?? '');

    if (strpos($key, 'morong') !== false && (strpos($key, 'terminal') !== false || strpos($key, 'bataan') !== false)) {
        return [
            'latitude' => 14.6798552,
            'longitude' => 120.2728757,
            'label' => 'Morong Bus Terminal (Morong, Bataan)',
        ];
    }

    if ($key === 'sbma' || $key === 'sbma terminal' || strpos($key, 'harbor point') !== false || strpos($key, 'national bookstore') !== false || strpos($key, 'sbma e bus stop') !== false) {
        return [
            'latitude' => 14.8243652,
            'longitude' => 120.2801434,
            'label' => 'SBMA E-bus stop — in front of National Bookstore, Harbor Point',
        ];
    }

    return null;
}

/** Apply canonical pins for known public terminals and expose safe UI labels. */
function mbus_route_apply_terminal_defaults(array $route): array
{
    foreach (['origin' => 'origin', 'destination' => 'destination'] as $field => $prefix) {
        $name = isset($route[$field]) && is_scalar($route[$field]) ? (string)$route[$field] : '';
        $known = mbus_route_known_terminal($name);
        $route[$prefix . '_label'] = $known['label'] ?? $name;
        if ($known) {
            // The named public endpoints are canonical; this also repairs stale manual pins.
            $route[$prefix . '_lat'] = $known['latitude'];
            $route[$prefix . '_lng'] = $known['longitude'];
        }
    }
    return $route;
}
