// Split from original designScript.js

// ====== Sidebar (hamburger) ======
const burger = document.getElementById('Humberger');
const sidebar = document.querySelector('.navLinksContainer');
const overlay = document.querySelector('.overlay');

function setSidebarOpen(open) {
    sidebar?.classList.toggle('active', open);
    document.querySelector('.fa-bars')?.classList.toggle('active', open);
    overlay?.classList.toggle('active', open);
    document.body.classList.toggle('sidebar-open', open);
    burger?.setAttribute('aria-expanded', open ? 'true' : 'false');
}

burger?.addEventListener('click', () => {
    setSidebarOpen(!sidebar?.classList.contains('active'));
});

overlay?.addEventListener('click', () => {
    setSidebarOpen(false);
});

sidebar?.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', () => setSidebarOpen(false));
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && sidebar?.classList.contains('active')) {
        setSidebarOpen(false);
        burger?.focus();
    }
});
