(() => {
  const sidebar = document.getElementById('sideArea');
  const overlay = document.getElementById('sidebarOverlay');
  const toggle = document.getElementById('menuToggle');
  const close = document.getElementById('sideClose');
  const setMenu = open => {
    sidebar?.classList.toggle('show', open);
    sidebar?.classList.toggle('open', open);
    overlay?.classList.toggle('show', open);
    document.body.classList.toggle('sidebar-open', open);
    toggle?.setAttribute('aria-expanded', String(open));
    if (open) close?.focus(); else toggle?.focus();
  };
  toggle?.addEventListener('click', () => setMenu(true));
  close?.addEventListener('click', () => setMenu(false));
  overlay?.addEventListener('click', () => setMenu(false));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && toggle?.getAttribute('aria-expanded') === 'true') setMenu(false); });
  document.querySelector('[data-print-report]')?.addEventListener('click', () => window.print());
})();
