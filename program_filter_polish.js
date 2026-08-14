(function () {
  'use strict';

  function initializeSortMenu(menu) {
    const form = menu.closest('form');
    const input = form ? form.querySelector('[data-sort-input]') : null;
    const trigger = menu.querySelector('.program-sort-trigger');
    const options = menu.querySelector('.program-sort-options');
    const buttons = Array.from(menu.querySelectorAll('[data-sort-value]'));
    if (!form || !input || !trigger || !options || !buttons.length) return;

    const close = (restoreFocus) => {
      options.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      menu.classList.remove('is-open');
      if (restoreFocus) trigger.focus();
    };

    const open = () => {
      options.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      menu.classList.add('is-open');
      (buttons.find((button) => button.getAttribute('aria-selected') === 'true') || buttons[0]).focus();
    };

    trigger.addEventListener('click', () => options.hidden ? open() : close(true));

    buttons.forEach((button) => {
      button.addEventListener('click', () => {
        const value = button.dataset.sortValue;
        if (!value) return;
        input.value = value;
        buttons.forEach((item) => item.setAttribute('aria-selected', item === button ? 'true' : 'false'));
        close(false);
        form.submit();
      });
    });

    options.addEventListener('keydown', (event) => {
      const currentIndex = buttons.indexOf(document.activeElement);
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const direction = event.key === 'ArrowDown' ? 1 : -1;
        buttons[(currentIndex + direction + buttons.length) % buttons.length].focus();
      } else if (event.key === 'Escape') {
        event.preventDefault();
        close(true);
      } else if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        buttons[event.key === 'Home' ? 0 : buttons.length - 1].focus();
      }
    });

    document.addEventListener('click', (event) => {
      if (!menu.contains(event.target)) close(false);
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-sort-menu]').forEach(initializeSortMenu);
  });
})();
