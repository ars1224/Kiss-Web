document.addEventListener('DOMContentLoaded', () => {
    const toolbars = [...document.querySelectorAll('[data-mobile-fixed-toolbar]')];
    const nav = document.querySelector('body.app-shell > nav');

    const updateNavHeight = () => {
        if (!nav) return;
        const height = Math.ceil(nav.getBoundingClientRect().height);
        document.documentElement.style.setProperty('--app-nav-height', `${height}px`);
    };

    const updateToolbarOffset = toolbar => {
        const page = toolbar.closest('[data-mobile-fixed-page]');
        if (!page) return;

        const height = Math.ceil(toolbar.getBoundingClientRect().height);
        page.style.setProperty('--mobile-fixed-toolbar-height', `${height}px`);
    };

    const updateAll = () => {
        updateNavHeight();
        toolbars.forEach(updateToolbarOffset);
    };
    const observer = new ResizeObserver(entries => {
        entries.forEach(entry => {
            if (entry.target === nav) {
                updateNavHeight();
            } else {
                updateToolbarOffset(entry.target);
            }
        });
    });

    toolbars.forEach(toolbar => observer.observe(toolbar));
    if (nav) observer.observe(nav);
    window.addEventListener('resize', updateAll, { passive: true });

    updateAll();
});
