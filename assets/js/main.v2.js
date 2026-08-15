// AttendTrack — Main JS

// Close modals on overlay click
document.addEventListener('click', function (e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.style.display = 'none';
    }
});

// Close modals on ESC
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(function (m) {
            m.style.display = 'none';
        });
    }
});

// Auto-hide alerts after 4s
document.querySelectorAll('.alert').forEach(function (el) {
    setTimeout(function () {
        el.style.transition = 'opacity 0.4s';
        el.style.opacity = '0';
        setTimeout(function () { el.remove(); }, 400);
    }, 4000);
});

// Mobile sidebar hamburger toggle
(function () {
    var hamburger = document.getElementById('hamburgerBtn');
    var sidebar = document.getElementById('sidebar');
    if (!hamburger || !sidebar) return;

    hamburger.addEventListener('click', function () {
        sidebar.classList.toggle('open');
        document.body.classList.toggle('sidebar-open');
    });

    document.addEventListener('click', function (e) {
        if (sidebar.classList.contains('open') &&
            !sidebar.contains(e.target) &&
            !hamburger.contains(e.target)) {
            sidebar.classList.remove('open');
            document.body.classList.remove('sidebar-open');
        }
    });
})();

// Desktop sidebar collapse toggle
(function () {
    var sidebar = document.getElementById('sidebar');
    var btn = document.getElementById('sidebarCollapseBtn');
    if (!sidebar || !btn) return;

    function applyCollapse(collapsed) {
        sidebar.classList.toggle('collapsed', collapsed);
        var chevron = btn.querySelector('svg');
        if (chevron) chevron.style.transform = collapsed ? 'rotate(180deg)' : '';
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var next = !sidebar.classList.contains('collapsed');
        applyCollapse(next);
        localStorage.setItem('sidebarCollapsed', next ? '1' : '0');
    });

    // Initial state already applied inline in layout_top.php to avoid flash
})();

// Generic helper to open/close a modal by id
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
