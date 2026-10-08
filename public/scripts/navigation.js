const sidebar = document.querySelector('#site-sidebar');
const menuButton = document.querySelector('[data-open-menu]');
const collapseButton = document.querySelector('[data-collapse-menu]');

if (sidebar && menuButton) {
    const mobile = window.matchMedia('(max-width: 860px)');
    const storageKey = 'weekly-news-sidebar-collapsed';
    let collapsed = false;

    try {
        collapsed = localStorage.getItem(storageKey) === 'true';
    } catch {
        // The menu still works when the browser blocks storage.
    }

    const updateButtons = () => {
        const menuLabel = mobile.matches && sidebar.open ? 'Fechar menu' : 'Abrir menu';
        menuButton.setAttribute('aria-expanded', String(mobile.matches && sidebar.open));
        menuButton.setAttribute('aria-label', menuLabel);
        menuButton.title = menuLabel;
        collapseButton.setAttribute('aria-expanded', String(!collapsed));
        collapseButton.setAttribute('aria-label', collapsed ? 'Expandir menu' : 'Recolher menu');
        collapseButton.title = collapsed ? 'Expandir menu' : 'Recolher menu';
    };

    const syncSidebar = () => {
        if (sidebar.matches(':modal')) sidebar.close();
        sidebar.setAttribute('role', mobile.matches ? 'dialog' : 'complementary');
        if (mobile.matches && sidebar.contains(document.activeElement)) {
            menuButton.focus({ preventScroll: true });
        }
        // Set open directly to expand the desktop menu without moving focus.
        sidebar.toggleAttribute('open', !mobile.matches);
        document.body.classList.toggle('sidebar-collapsed', !mobile.matches && collapsed);
        document.body.classList.remove('menu-open');
        if (!mobile.matches && document.activeElement === menuButton) {
            collapseButton.focus({ preventScroll: true });
        }
        updateButtons();
    };

    const closeMenu = () => {
        if (mobile.matches && sidebar.open) sidebar.close();
    };

    collapseButton.addEventListener('click', () => {
        if (mobile.matches) return;
        collapsed = !collapsed;
        syncSidebar();
        try {
            localStorage.setItem(storageKey, String(collapsed));
        } catch {
            // Keep the current state even without persistence.
        }
    });

    menuButton.addEventListener('click', () => {
        if (!mobile.matches) return;
        if (sidebar.open) {
            closeMenu();
            return;
        }
        sidebar.showModal();
        sidebar.querySelector('[data-close-menu]').focus({ preventScroll: true });
        document.body.classList.add('menu-open');
        updateButtons();
    });

    sidebar.querySelector('[data-close-menu]').addEventListener('click', closeMenu);
    sidebar.addEventListener('click', (event) => {
        const bounds = sidebar.getBoundingClientRect();
        const outside = event.clientX < bounds.left || event.clientX > bounds.right
            || event.clientY < bounds.top || event.clientY > bounds.bottom;
        if (event.target === sidebar && outside) closeMenu();
    });
    sidebar.addEventListener('close', () => {
        if (!mobile.matches) return;
        document.body.classList.remove('menu-open');
        updateButtons();
        if (!sidebar.open) menuButton.focus({ preventScroll: true });
    });
    sidebar.querySelectorAll('nav a').forEach((link) => link.addEventListener('click', closeMenu));
    mobile.addEventListener('change', syncSidebar);
    syncSidebar();
}
