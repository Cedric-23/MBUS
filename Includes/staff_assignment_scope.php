<?php
/** Shared bus-ownership predicates for staff operations. The role is always
 * taken from the authenticated server session; callers bind the returned '?'
 * to that staff member's user_id. */
function mbus_staff_assignment_condition(string $role, string $busExpression = 's.bus_id'): string
{
    // All callers pass a fixed SQL alias expression; reject anything dynamic.
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.bus_id$/', $busExpression)) {
        throw new InvalidArgumentException('Invalid bus expression.');
    }

    return match (strtolower($role)) {
        'operator' => "EXISTS (SELECT 1 FROM public.operator_bus_assignments staff_assignment WHERE staff_assignment.bus_id = {$busExpression} AND staff_assignment.operator_id = ?)",
        'conductor' => "EXISTS (SELECT 1 FROM public.conductor_bus_assignments staff_assignment WHERE staff_assignment.bus_id = {$busExpression} AND staff_assignment.conductor_id = ?)",
        default => 'FALSE',
    };
}
