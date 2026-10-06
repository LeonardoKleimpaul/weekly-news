(() => {
    const root = document.documentElement;
    const storageKey = 'weekly-news-theme';

    // Apply the saved theme before styles load to avoid a flash on navigation.
    try {
        const savedTheme = localStorage.getItem(storageKey);
        if (savedTheme === 'light' || savedTheme === 'dark') {
            root.dataset.theme = savedTheme;
        }
    } catch {
        // The toggle still works when the browser blocks storage.
    }

    document.addEventListener('DOMContentLoaded', () => {
        const button = document.querySelector('[data-toggle-theme]');
        if (!button) return;

        const updateLabel = () => {
            const label = root.dataset.theme === 'dark' ? 'Ativar modo claro' : 'Ativar modo escuro';
            button.setAttribute('aria-label', label);
            button.title = label;
        };

        button.addEventListener('click', () => {
            root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
            updateLabel();
            try {
                localStorage.setItem(storageKey, root.dataset.theme);
            } catch {
                // Keep the theme active for this page even without persistence.
            }
        });

        updateLabel();
        button.hidden = false;
    });
})();
