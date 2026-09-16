(function () {
  'use strict';

  const transitionLinks = document.querySelectorAll(
    '.auth-return, a[href="login.php"], a[href="signup.php"]'
  );

  function restorePage() {
    document.body.classList.remove('auth-page-leaving');
  }

  window.addEventListener('pageshow', restorePage);

  transitionLinks.forEach((link) => {
    link.addEventListener('click', (event) => {
      if (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey ||
        link.target === '_blank' ||
        link.hasAttribute('download')
      ) {
        return;
      }

      const destination = new URL(link.href, window.location.href);
      if (destination.origin !== window.location.origin || destination.href === window.location.href) {
        return;
      }

      event.preventDefault();
      document.body.classList.add('auth-page-leaving');
      const delay = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 230;
      window.setTimeout(() => window.location.assign(destination.href), delay);
    });
  });
})();
