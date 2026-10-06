const sidebar = document.querySelector('#site-sidebar');
const menuButton = document.querySelector('[data-open-menu]');

if (sidebar && menuButton) {
    const mobile = window.matchMedia('(max-width: 860px)');

    const syncSidebar = () => {
        if (sidebar.matches(':modal')) sidebar.close();
        sidebar.setAttribute('role', mobile.matches ? 'dialog' : 'complementary');
        // A desktop sidebar is static: show() would move focus to its first link.
        sidebar.toggleAttribute('open', !mobile.matches);
        menuButton.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('menu-open');
    };

    const closeMenu = () => {
        if (mobile.matches && sidebar.open) sidebar.close();
    };

    menuButton.addEventListener('click', () => {
        if (!mobile.matches || sidebar.open) return;
        sidebar.showModal();
        sidebar.querySelector('[data-close-menu]').focus({ preventScroll: true });
        menuButton.setAttribute('aria-expanded', 'true');
        document.body.classList.add('menu-open');
    });

    sidebar.querySelector('[data-close-menu]').addEventListener('click', closeMenu);
    sidebar.addEventListener('click', (event) => {
        const bounds = sidebar.getBoundingClientRect();
        const outside = event.clientX < bounds.left || event.clientX > bounds.right
            || event.clientY < bounds.top || event.clientY > bounds.bottom;
        if (event.target === sidebar && outside) closeMenu();
    });
    sidebar.addEventListener('close', () => {
        menuButton.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('menu-open');
    });
    sidebar.querySelectorAll('nav a').forEach((link) => link.addEventListener('click', closeMenu));
    mobile.addEventListener('change', syncSidebar);
    syncSidebar();
}
