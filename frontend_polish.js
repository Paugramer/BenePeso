(function () {
  'use strict';

  const dialogSelector = '.modal, .modal-overlay, .modal-bg';
  const closeSelector = '.modal-close, .modal-close-btn, .modal-close-icon, [data-close-modal], [data-close-profile], [data-close-report], [data-close-bulk], [data-close-quick], [data-close-success], [data-close-error], [data-close-bulk-status]';
  const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  let lastTrigger = null;

  function isVisible(element) {
    if (!element) return false;
    const style = window.getComputedStyle(element);
    return style.display !== 'none' && style.visibility !== 'hidden' &&
      (element.classList.contains('show') || style.opacity !== '0');
  }

  function prepareDialogs() {
    document.querySelectorAll(dialogSelector).forEach((container) => {
      const dialog = container.matches('[role="dialog"]')
        ? container
        : container.querySelector('.modal-dialog, .modal-content, .modal-box, .modal-landscape');
      if (!dialog) return;
      dialog.setAttribute('role', dialog.getAttribute('role') || 'dialog');
      dialog.setAttribute('aria-modal', 'true');
      if (!dialog.hasAttribute('tabindex')) dialog.setAttribute('tabindex', '-1');

      const title = dialog.querySelector('.modal-title, h1, h2, h3');
      if (title) {
        if (!title.id) title.id = 'bp-dialog-title-' + Math.random().toString(36).slice(2, 9);
        if (!dialog.hasAttribute('aria-labelledby')) dialog.setAttribute('aria-labelledby', title.id);
      }
    });
  }

  function labelIconButtons() {
    document.querySelectorAll('button').forEach((button) => {
      if (button.hasAttribute('aria-label')) return;
      const visibleText = (button.textContent || '').replace(/[×✕]/g, '').trim();
      if (button.matches('.modal-close, .modal-close-btn, .modal-close-icon')) {
        button.setAttribute('aria-label', 'Close dialog');
      } else if (!visibleText && button.querySelector('i, svg')) {
        const title = button.getAttribute('title');
        if (title) button.setAttribute('aria-label', title);
      }
    });
  }

  function improveImages() {
    document.querySelectorAll('img:not([alt])').forEach((image) => image.setAttribute('alt', ''));
    document.querySelectorAll('img').forEach((image) => {
      if (!image.hasAttribute('decoding')) image.setAttribute('decoding', 'async');
    });
  }

  function improveTables() {
    document.querySelectorAll('table').forEach((table) => {
      if (!table.querySelector('caption')) {
        const caption = document.createElement('caption');
        caption.className = 'bp-sr-only';
        caption.textContent = table.getAttribute('aria-label') || 'Data table';
        table.prepend(caption);
      }
      table.querySelectorAll('thead th').forEach((header) => {
        if (!header.hasAttribute('scope')) header.setAttribute('scope', 'col');
      });
    });
  }

  function improveMessages() {
    document.querySelectorAll('.flash, .alert, .error-message, .success-message').forEach((message) => {
      if (!message.hasAttribute('role')) message.setAttribute('role', 'status');
      if (!message.hasAttribute('aria-live')) message.setAttribute('aria-live', 'polite');
    });
  }

  document.addEventListener('pointerdown', (event) => {
    const candidate = event.target.closest('button, a, [role="button"]');
    if (candidate && !candidate.matches(closeSelector)) lastTrigger = candidate;
  });

  document.addEventListener('click', (event) => {
    if (event.target.closest(closeSelector) && lastTrigger && document.contains(lastTrigger)) {
      window.setTimeout(() => lastTrigger.focus(), 0);
    }
  });

  document.addEventListener('keydown', (event) => {
    const openDialogs = Array.from(document.querySelectorAll(dialogSelector)).filter(isVisible);
    const container = openDialogs[openDialogs.length - 1];
    if (!container) return;
    const dialog = container.matches('[role="dialog"]') ? container : container.querySelector('[role="dialog"]');

    if (event.key === 'Escape') {
      const close = container.querySelector(closeSelector);
      if (close) {
        event.preventDefault();
        close.click();
      }
      return;
    }

    if (event.key === 'Tab' && dialog) {
      const items = Array.from(dialog.querySelectorAll(focusableSelector)).filter(isVisible);
      if (!items.length) {
        event.preventDefault();
        dialog.focus();
      } else if (event.shiftKey && document.activeElement === items[0]) {
        event.preventDefault();
        items[items.length - 1].focus();
      } else if (!event.shiftKey && document.activeElement === items[items.length - 1]) {
        event.preventDefault();
        items[0].focus();
      }
    }
  });

  function initialize() {
    prepareDialogs();
    labelIconButtons();
    improveImages();
    improveTables();
    improveMessages();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
})();
