(function () {
  'use strict';

  function setup(menu) {
    const form = menu.closest('form');
    const key = menu.dataset.filterMenu;
    const input = form && form.querySelector(`[data-filter-input="${key}"]`);
    const trigger = menu.querySelector('.activity-filter-trigger');
    const panel = menu.querySelector('.activity-filter-options');
    const options = Array.from(menu.querySelectorAll('[data-filter-value]'));
    if (!form || !input || !trigger || !panel || !options.length) return;

    const close = (focus) => {
      panel.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      menu.classList.remove('is-open');
      if (focus) trigger.focus();
    };

    trigger.addEventListener('click', () => {
      const open = panel.hidden;
      document.querySelectorAll('.activity-filter-menu.is-open').forEach((other) => {
        if (other !== menu) other.querySelector('.activity-filter-trigger').click();
      });
      panel.hidden = !open;
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      menu.classList.toggle('is-open', open);
      if (open) (options.find((option) => option.getAttribute('aria-selected') === 'true') || options[0]).focus();
    });

    options.forEach((option) => option.addEventListener('click', () => {
      input.value = option.dataset.filterValue || '';
      close(false);
      form.submit();
    }));

    panel.addEventListener('keydown', (event) => {
      const index = options.indexOf(document.activeElement);
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const step = event.key === 'ArrowDown' ? 1 : -1;
        options[(index + step + options.length) % options.length].focus();
      } else if (event.key === 'Escape') {
        event.preventDefault(); close(true);
      }
    });

    document.addEventListener('click', (event) => { if (!menu.contains(event.target)) close(false); });
  }

  document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-filter-menu]').forEach(setup));
})();
