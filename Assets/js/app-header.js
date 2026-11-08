document.addEventListener('DOMContentLoaded', function () {
    // Use the photo saved on the profile page (stored in the database) in the header avatar.
    document.querySelectorAll('.profile-avatar img').forEach(function (img) {
        const probe = new Image();
        probe.onload = function () { img.src = probe.src; };
        probe.src = '../avatar.php';
    });

    const trigger = document.querySelector('.profile-trigger');
    const menu = document.getElementById('profileMenu');

    if (trigger && menu) {
        function closeMenu() {
            menu.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
        }

        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            const open = menu.classList.toggle('open');
            trigger.setAttribute('aria-expanded', String(open));
        });

        menu.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        document.addEventListener('click', closeMenu);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeMenu();
                trigger.focus();
            }
        });
    }

    // Keep both the selected date range and selected weekday visible on narrow screens.
    window.requestAnimationFrame(function () {
        document.querySelectorAll('.date-filter-btn.active, .day-tab.day-active').forEach(function (activeItem) {
            activeItem.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' });
        });
    });
});
