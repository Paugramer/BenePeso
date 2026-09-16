(function () {
  'use strict';

  const roots = Array.from(document.querySelectorAll('[data-google-signin]'));
  if (!roots.length) return;

  function setMessage(root, message) {
    const output = root.querySelector('.google-auth-message');
    if (output) output.textContent = message || '';
  }

  function showError(root, message) {
    const safeMessage = message || 'Google sign-in could not be completed.';
    setMessage(root, '');

    if (typeof window.showAuthNotice === 'function') {
      const isRestricted = /access denied|restricted|banned/i.test(safeMessage);
      window.showAuthNotice(
        safeMessage,
        isRestricted ? 'Account access restricted' : 'Google sign-in unsuccessful',
        { type: isRestricted ? 'restricted' : 'error', actionLabel: isRestricted ? 'I understand' : 'Try again' }
      );
      return;
    }

    setMessage(root, safeMessage);
  }

  function renderGoogleButtons() {
    if (!window.google?.accounts?.id) return;

    roots.forEach((root) => {
      const button = root.querySelector('.google-button-host');
      if (!button || button.dataset.rendered === 'true') return;

      const clientId = root.dataset.clientId || '';
      const csrfToken = root.dataset.csrf || '';
      if (!clientId || !csrfToken) return;

      window.google.accounts.id.initialize({
        client_id: clientId,
        ux_mode: 'popup',
        auto_select: false,
        cancel_on_tap_outside: true,
        callback: async (response) => {
          if (!response?.credential) {
            showError(root, 'Google sign-in was not completed. Please try again.');
            return;
          }

          root.classList.add('is-processing');
          setMessage(root, 'Verifying your Google account securely...');

          try {
            const body = new URLSearchParams({
              credential: response.credential,
              csrf_token: csrfToken
            });
            const result = await fetch('google_auth_callback.php', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
              body
            });
            const payload = await result.json();
            if (!result.ok || !payload.ok || !payload.redirect) {
              throw new Error(payload.message || 'Google sign-in could not be completed.');
            }
            root.classList.remove('is-processing');
            setMessage(root, '');

            const successMessage = payload.message || (payload.flow === 'signup'
              ? 'Your Google account is verified. Complete your BENEPESO profile to finish creating your account.'
              : 'Your identity has been verified securely. Welcome back to BENEPESO.');
            const successTitle = payload.flow === 'signup'
              ? 'Google account verified'
              : 'Sign-in successful';

            if (typeof window.showAuthNotice === 'function') {
              window.showAuthNotice(successMessage, successTitle, {
                type: 'success',
                actionLabel: payload.flow === 'signup' ? 'Complete my profile' : 'Continue to BENEPESO',
                redirect: payload.redirect
              });
              return;
            }

            window.location.assign(payload.redirect);
          } catch (error) {
            root.classList.remove('is-processing');
            showError(root, error.message || 'Google sign-in could not be completed.');
          }
        }
      });

      button.dataset.rendered = 'true';
      window.google.accounts.id.renderButton(button, {
        type: 'standard',
        theme: 'outline',
        size: 'large',
        text: root.dataset.buttonText || 'continue_with',
        shape: 'rectangular',
        logo_alignment: 'left',
        locale: 'en',
        width: Math.max(240, Math.min(392, Math.floor(button.getBoundingClientRect().width)))
      });
    });
  }

  window.addEventListener('load', renderGoogleButtons);
  window.addEventListener('google-library-ready', renderGoogleButtons);
  document.addEventListener('DOMContentLoaded', renderGoogleButtons);
})();
