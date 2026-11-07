/* Resolve Live-page selection without silently replacing an unavailable deep-linked trip. */
(function (global) {
    'use strict';

    function chooseSchedule(trips, requestedId, currentId, manualChoice, preferredId) {
        const availableTrips = Array.isArray(trips) ? trips : [];
        const isAvailable = id => availableTrips.some(trip => String(trip.schedule_id) === String(id));
        const requested = String(requestedId || '');
        const current = String(currentId || '');

        if (requested && !manualChoice) {
            return isAvailable(requested)
                ? { scheduleId: requested, unavailableId: '' }
                : { scheduleId: '', unavailableId: requested };
        }

        if (current && isAvailable(current)) return { scheduleId: current, unavailableId: '' };
        if (current && manualChoice) return { scheduleId: '', unavailableId: current };
        if (manualChoice) return { scheduleId: '', unavailableId: '' };

        const preferred = String(preferredId || '');
        if (preferred && isAvailable(preferred)) return { scheduleId: preferred, unavailableId: '' };
        const first = availableTrips[0];
        return { scheduleId: first ? String(first.schedule_id) : '', unavailableId: '' };
    }

    global.MBUSLiveSelection = { chooseSchedule };
})(window);
