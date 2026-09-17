(function () {
  'use strict';

  const dialogSelector = '.modal, .modal-overlay, .modal-bg';
  const closeSelector = '.modal-close, .modal-close-btn, .modal-close-icon, [data-close-modal], [data-close-profile], [data-close-report], [data-close-bulk], [data-close-quick], [data-close-success], [data-close-error], [data-close-bulk-status], [data-close-contact]';
  const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  let lastTrigger = null;

  function showBrandedPageLoader() {
    if (!document.body) return null;

    const existingLoader = document.querySelector('.bp-page-loader');
    if (existingLoader) return existingLoader;

    const loader = document.createElement('div');
    loader.className = 'bp-page-loader';
    loader.setAttribute('role', 'status');
    loader.setAttribute('aria-live', 'polite');
    loader.setAttribute('aria-label', 'Preparing BenePeso services');
    loader.innerHTML =
      '<div class="bp-loader-visual" aria-hidden="true">' +
        '<span class="bp-loader-glow"></span>' +
        '<svg class="bp-loader-card" viewBox="0 0 220 170" focusable="false">' +
          '<rect class="bp-loader-card-bg" x="22" y="20" width="176" height="126" rx="19"/>' +
          '<rect class="bp-loader-card-line" x="22" y="20" width="176" height="126" rx="19"/>' +
          '<circle class="bp-loader-profile-head" cx="75" cy="70" r="17"/>' +
          '<path class="bp-loader-profile-body" d="M47 120c3-22 14-32 28-32s25 10 28 32"/>' +
          '<path class="bp-loader-info bp-loader-info-one" d="M121 63h48"/>' +
          '<path class="bp-loader-info bp-loader-info-two" d="M121 84h36"/>' +
          '<path class="bp-loader-info bp-loader-info-three" d="M121 105h45"/>' +
          '<path class="bp-loader-scan" d="M37 92h146"/>' +
          '<circle class="bp-loader-approval-ring" cx="172" cy="124" r="20"/>' +
          '<path class="bp-loader-approval-check" d="m162 124 7 7 13-15"/>' +
        '</svg>' +
      '</div>' +
      '<div class="bp-loader-brand"><strong>BENEPESO</strong><span>Profiling &bull; Eligibility &bull; Verification</span></div>' +
      '<p>Preparing your services</p>' +
      '<div class="bp-loader-progress" aria-hidden="true"><span></span></div>' +
      '<span class="bp-loader-sr">Please wait while BenePeso finishes loading.</span>';

    document.body.prepend(loader);
    document.documentElement.classList.add('bp-page-loading');
    return loader;
  }

  /* Branded page loader. Kept self-contained so existing page behavior is unchanged. */
  function initializePageLoader() {
    if (!document.body) return;

    let skipAfterPreparedNavigation = false;
    try {
      skipAfterPreparedNavigation = sessionStorage.getItem('bp-skip-next-page-loader') === '1';
      if (skipAfterPreparedNavigation) sessionStorage.removeItem('bp-skip-next-page-loader');
    } catch (error) {
      /* Storage can be unavailable in privacy modes; body-level disabling remains effective. */
    }
    if (document.body.hasAttribute('data-disable-page-loader') || document.querySelector('.bp-page-loader') || skipAfterPreparedNavigation) return;

    const navigation = typeof performance.getEntriesByType === 'function'
      ? performance.getEntriesByType('navigation')[0]
      : null;
    let hasBeenSeen = false;

    try {
      hasBeenSeen = sessionStorage.getItem('bp-page-loader-seen') === '1';
      sessionStorage.setItem('bp-page-loader-seen', '1');
    } catch (error) {
      /* Storage can be unavailable in privacy modes; the loader still works safely. */
    }

    const forceOnEntry = document.body.hasAttribute('data-force-page-loader');
    const shouldShowImmediately = forceOnEntry || !hasBeenSeen || (navigation && navigation.type === 'reload');
    const showDelay = shouldShowImmediately ? 0 : 160;
    const minimumVisibleMs = shouldShowImmediately ? 550 : 240;
    const safetyTimeoutMs = 3500;
    let loader = null;
    let shownAt = 0;
    let pageReady = document.readyState === 'complete';
    let removed = false;

    function buildLoader() {
      if (removed || pageReady && !shouldShowImmediately) return;
      loader = showBrandedPageLoader();
      if (!loader) return;
      shownAt = performance.now();
    }

    function removeLoader() {
      if (removed) return;
      pageReady = true;

      if (!loader) {
        removed = true;
        return;
      }

      const remaining = Math.max(0, minimumVisibleMs - (performance.now() - shownAt));
      window.setTimeout(() => {
        if (removed || !loader) return;
        removed = true;
        document.documentElement.classList.remove('bp-page-loading');
        loader.classList.add('is-leaving');
        loader.setAttribute('aria-hidden', 'true');
        window.setTimeout(() => loader && loader.remove(), 360);
      }, remaining);
    }

    window.setTimeout(buildLoader, showDelay);

    if (pageReady) {
      window.setTimeout(removeLoader, showDelay + minimumVisibleMs);
    } else {
      window.addEventListener('load', removeLoader, { once: true });
    }

    window.addEventListener('pageshow', (event) => {
      if (event.persisted) removeLoader();
    }, { once: true });

    window.setTimeout(removeLoader, safetyTimeoutMs);
  }

  function initializeFooterContactModal() {
    const contactLinks = Array.from(document.querySelectorAll('.footer-contact-link[data-contact-kind]'));
    if (!contactLinks.length || document.getElementById('bpContactModal')) return;

    let copyValue = '+639479971186';
    const modal = document.createElement('div');
    modal.className = 'bp-contact-modal';
    modal.id = 'bpContactModal';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML =
      '<button class="bp-contact-backdrop" type="button" data-close-contact aria-label="Close contact details"></button>' +
      '<section class="bp-contact-dialog" role="dialog" aria-modal="true" aria-labelledby="bpContactTitle" tabindex="-1">' +
        '<button class="bp-contact-close" type="button" data-close-contact aria-label="Close contact details">&times;</button>' +
        '<span class="bp-contact-kicker">PESO Vinzons</span>' +
        '<h2 id="bpContactTitle">Contact the PESO office</h2>' +
        '<p class="bp-contact-copy">Use the official mobile number below for inquiries and assistance.</p>' +
        '<div class="bp-contact-number"><span aria-hidden="true">&#9742;</span><strong></strong></div>' +
        '<button class="bp-contact-copy-button" type="button"><span aria-hidden="true">&#128203;</span> Copy number</button>' +
        '<p class="bp-contact-status" role="status" aria-live="polite"></p>' +
      '</section>';
    document.body.appendChild(modal);

    const close = () => {
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('bp-contact-open');
    };

    contactLinks.forEach(link => {
      link.addEventListener('click', event => {
        event.preventDefault();
        const isEmail = link.dataset.contactKind === 'email';
        const displayValue = link.textContent.trim();
        copyValue = isEmail ? 'lguvinzonspeso@gmail.com' : '+639479971186';
        modal.querySelector('#bpContactTitle').textContent = isEmail ? 'Email the PESO office' : 'Contact the PESO office';
        modal.querySelector('.bp-contact-copy').textContent = isEmail
          ? 'Copy the official email address below for inquiries and assistance.'
          : 'Use the official mobile number below for inquiries and assistance.';
        modal.querySelector('.bp-contact-number > span').textContent = isEmail ? '\u2709' : '\u260E';
        modal.querySelector('.bp-contact-number strong').textContent = displayValue;
        modal.querySelector('.bp-contact-copy-button').lastChild.textContent = isEmail ? ' Copy email' : ' Copy number';
        modal.querySelector('.bp-contact-status').textContent = '';
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('bp-contact-open');
        window.setTimeout(() => modal.querySelector('.bp-contact-copy-button').focus(), 20);
      });
    });

    modal.querySelectorAll('[data-close-contact]').forEach(button => button.addEventListener('click', close));
    modal.querySelector('.bp-contact-copy-button').addEventListener('click', async () => {
      const status = modal.querySelector('.bp-contact-status');
      try {
        await navigator.clipboard.writeText(copyValue);
        status.textContent = 'Contact detail copied to your clipboard.';
      } catch (error) {
        status.textContent = 'Copy this contact detail: ' + copyValue;
      }
    });
  }

  function initializeBeneficiaryUpdates() {
    const accountArea = document.querySelector('.topbar .account-area');
    const accountButton = document.getElementById('accountButton');
    const snapshot = document.getElementById('beneficiaryServiceSnapshot');
    if (!accountArea || !accountButton) return;

    const updateCenter = document.createElement('div');
    updateCenter.className = 'bp-update-center';
    updateCenter.innerHTML =
      '<button class="bp-update-button" type="button" aria-label="Open service updates" aria-expanded="false" aria-controls="bpUpdatePanel">' +
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z"></path><path d="M10 21h4"></path></svg>' +
        '<span class="bp-update-badge" hidden>0</span>' +
      '</button>' +
      '<section class="bp-update-panel" id="bpUpdatePanel" aria-label="Your BENEPESO service updates" hidden>' +
        '<header><div><span>BENEPESO Updates</span><strong>Applications &amp; new programs</strong></div><button type="button" class="bp-update-read">Mark all read</button></header>' +
        '<div class="bp-update-list"><div class="bp-update-empty">Checking your latest application updates...</div></div>' +
        '<a class="bp-update-footer" href="profile.php#my-programs">View complete program progress <span aria-hidden="true">&rarr;</span></a>' +
      '</section>';
    accountArea.parentNode.insertBefore(updateCenter, accountArea);

    const button = updateCenter.querySelector('.bp-update-button');
    const badge = updateCenter.querySelector('.bp-update-badge');
    const panel = updateCenter.querySelector('.bp-update-panel');
    const list = updateCenter.querySelector('.bp-update-list');
    const markRead = updateCenter.querySelector('.bp-update-read');
    const storageKey = 'benepeso-read-service-updates';
    let items = [];
    let readIds = [];

    try {
      const saved = JSON.parse(localStorage.getItem(storageKey) || '[]');
      readIds = Array.isArray(saved) ? saved.slice(0, 80) : [];
    } catch (error) {
      readIds = [];
    }

    const saveReadState = () => {
      try { localStorage.setItem(storageKey, JSON.stringify(readIds.slice(-80))); } catch (error) { /* Storage is optional. */ }
    };

    const updateBadge = () => {
      const unread = items.filter(item => !readIds.includes(item.id)).length;
      badge.textContent = unread > 9 ? '9+' : String(unread);
      badge.hidden = unread === 0;
      button.setAttribute('aria-label', unread ? `Open service updates, ${unread} unread` : 'Open service updates');
      markRead.hidden = unread === 0;
    };

    const readableDate = value => {
      const parsed = new Date(String(value || '').replace(' ', 'T'));
      return Number.isNaN(parsed.getTime()) ? '' : parsed.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const renderUpdates = () => {
      list.replaceChildren();
      if (!items.length) {
        const empty = document.createElement('div');
        empty.className = 'bp-update-empty';
        empty.innerHTML = '<strong>No application updates yet</strong><span>Your approval and program notices will appear here.</span>';
        list.appendChild(empty);
        updateBadge();
        return;
      }

      items.forEach(item => {
        const row = document.createElement('a');
        row.className = `bp-update-item is-${item.tone || 'info'}` + (readIds.includes(item.id) ? '' : ' is-unread');
        row.href = item.href || 'profile.php#my-programs';
        row.innerHTML = '<span class="bp-update-dot" aria-hidden="true"></span><span class="bp-update-item-copy"><small></small><strong></strong><span></span><time></time></span>';
        row.querySelector('small').textContent = [item.program, item.batch].filter(Boolean).join(' / ');
        row.querySelector('strong').textContent = item.headline || 'Service update';
        row.querySelector('.bp-update-item-copy > span').textContent = item.message || '';
        row.querySelector('time').textContent = readableDate(item.updated_at);
        row.addEventListener('click', () => {
          if (!readIds.includes(item.id)) readIds.push(item.id);
          saveReadState();
        });
        list.appendChild(row);
      });
      updateBadge();
    };

    const renderSnapshot = summary => {
      if (!snapshot) return;
      snapshot.hidden = false;
      const title = snapshot.querySelector('h2');
      const copy = snapshot.querySelector('p');
      const action = snapshot.querySelector('.bp-service-snapshot-action');
      const status = snapshot.querySelector('.bp-service-snapshot-status span');
      if (!summary) {
        const profileReady = snapshot.dataset.profileReady === 'true';
        title.textContent = profileReady ? 'You have no program application yet' : 'Complete your profile before applying';
        copy.textContent = profileReady
          ? 'Browse current PESO opportunities and choose the program that fits your needs.'
          : 'Add the missing required information so eligibility checks can use an accurate profile.';
        if (status) status.textContent = profileReady ? 'Ready to apply' : 'Profile incomplete';
        action.href = profileReady ? 'programs.php' : 'profile.php#personal-info';
        action.firstChild.textContent = profileReady ? 'Explore programs ' : 'Complete profile ';
        return;
      }
      snapshot.dataset.tone = summary.tone || 'info';
      title.textContent = `${summary.program}: ${summary.headline}`;
      copy.textContent = summary.next_action || summary.message || 'Open your program progress for complete details.';
      if (status) status.textContent = summary.availment || summary.approval || 'Current update';
    };

    button.addEventListener('click', () => {
      const opening = panel.hidden;
      panel.hidden = !opening;
      button.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) window.setTimeout(() => panel.querySelector('a, button')?.focus(), 10);
    });

    markRead.addEventListener('click', () => {
      readIds = Array.from(new Set(readIds.concat(items.map(item => item.id))));
      saveReadState();
      renderUpdates();
    });

    document.addEventListener('click', event => {
      if (!updateCenter.contains(event.target)) {
        panel.hidden = true;
        button.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !panel.hidden) {
        panel.hidden = true;
        button.setAttribute('aria-expanded', 'false');
        button.focus();
      }
    });

    fetch('beneficiary_updates_api.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(response => response.ok ? response.json() : Promise.reject(new Error('Updates unavailable')))
      .then(data => {
        items = Array.isArray(data.items) ? data.items : [];
        renderUpdates();
        renderSnapshot(data.summary || null);
      })
      .catch(() => {
        list.innerHTML = '<div class="bp-update-empty"><strong>Updates are temporarily unavailable</strong><span>You can still review applications from My Profile.</span></div>';
        renderSnapshot(null);
      });
  }

  function initializeProgramSuggestions() {
    const input = document.querySelector('#programGrid') ? document.getElementById('searchInput') : null;
    const searchContainer = input?.closest('.search-container');
    if (!input || !searchContainer) return;

    const suggestions = Array.from(document.querySelectorAll('#programGrid .program-card:not(.program-batch-duplicate)'))
      .flatMap(card => [card.dataset.title, card.dataset.category && card.dataset.category !== 'regular tupad' ? card.dataset.category : ''])
      .map(value => String(value || '').trim())
      .filter(Boolean)
      .filter((value, index, values) => values.findIndex(candidate => candidate.toLowerCase() === value.toLowerCase()) === index);
    if (!suggestions.length) return;

    const panel = document.createElement('div');
    panel.className = 'bp-program-suggestions';
    panel.id = 'bpProgramSuggestions';
    panel.setAttribute('role', 'listbox');
    panel.hidden = true;
    searchContainer.appendChild(panel);
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', panel.id);
    input.setAttribute('aria-expanded', 'false');

    const close = () => {
      panel.hidden = true;
      input.setAttribute('aria-expanded', 'false');
    };
    const render = () => {
      const query = input.value.trim().toLowerCase();
      const matches = suggestions.filter(value => !query || value.toLowerCase().includes(query)).slice(0, 5);
      panel.replaceChildren();
      matches.forEach(value => {
        const option = document.createElement('button');
        option.type = 'button';
        option.setAttribute('role', 'option');
        option.innerHTML = '<span aria-hidden="true">&#128269;</span><strong></strong><small>Show matching open batches</small>';
        option.querySelector('strong').textContent = value;
        option.addEventListener('click', () => {
          input.value = value;
          close();
          if (typeof window.filterPrograms === 'function') window.filterPrograms();
          input.focus();
        });
        option.addEventListener('keydown', event => {
          const options = Array.from(panel.querySelectorAll('button'));
          const index = options.indexOf(option);
          if (event.key === 'ArrowDown' && options[index + 1]) { event.preventDefault(); options[index + 1].focus(); }
          if (event.key === 'ArrowUp') { event.preventDefault(); (options[index - 1] || input).focus(); }
          if (event.key === 'Escape') { close(); input.focus(); }
        });
        panel.appendChild(option);
      });
      panel.hidden = !matches.length;
      input.setAttribute('aria-expanded', matches.length ? 'true' : 'false');
    };
    input.addEventListener('focus', render);
    input.addEventListener('input', render);
    input.addEventListener('keydown', event => {
      if (event.key === 'Escape') close();
      if (event.key === 'ArrowDown' && !panel.hidden) {
        event.preventDefault();
        panel.querySelector('button')?.focus();
      }
    });
    document.addEventListener('click', event => { if (!searchContainer.contains(event.target)) close(); });
  }

  function initializeBackToTop() {
    if (document.getElementById('bpBackToTop')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.id = 'bpBackToTop';
    button.className = 'bp-back-to-top';
    button.setAttribute('aria-label', 'Back to top');
    button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 14 6-6 6 6"></path></svg><span>Top</span>';
    document.body.appendChild(button);
    const update = () => button.classList.toggle('show', window.scrollY > 650);
    button.addEventListener('click', () => window.scrollTo({
      top: 0,
      behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
    }));
    window.addEventListener('scroll', update, { passive: true });
    update();
  }

  /* Contextual feedback for actions that submit data or change authentication state. */
  function initializeActionLoaders() {
    let activeLoader = null;
    let safetyTimer = null;
    let authNavigationPending = false;
    const submissionDelayMs = 120;

    function iconFor(variant) {
      if (variant === 'login') {
        return '<svg viewBox="0 0 160 160" focusable="false"><circle class="bp-action-orbit" cx="80" cy="80" r="62"/><path class="bp-action-shield" d="M80 25 125 42v34c0 31-18 49-45 61-27-12-45-30-45-61V42l45-17Z"/><rect class="bp-action-lock" x="61" y="72" width="38" height="31" rx="7"/><path class="bp-action-lock" d="M68 72v-9a12 12 0 0 1 24 0v9M80 84v8"/></svg>';
      }
      if (variant === 'logout') {
        return '<svg viewBox="0 0 160 160" focusable="false"><path class="bp-action-door" d="M32 134V29h66v105M47 43h35v91H47V43Z"/><circle class="bp-action-knob" cx="73" cy="89" r="3"/><path class="bp-action-arrow" d="M75 81h52m-17-17 17 17-17 17"/></svg>';
      }
      if (variant === 'approve' || variant === 'reject') {
        const mark = variant === 'approve' ? '<path d="m102 109 9 9 19-22"/>' : '<path d="m101 99 25 25m0-25-25 25"/>';
        return '<svg viewBox="0 0 160 160" focusable="false"><path class="bp-action-document" d="M38 20h59l25 25v90H38V20Zm59 0v26h25"/><path class="bp-action-document-lines" d="M57 66h45M57 83h35"/><circle class="bp-action-stamp" cx="114" cy="111" r="29"/>' + mark + '</svg>';
      }
      if (variant === 'status') {
        return '<svg viewBox="0 0 160 160" focusable="false"><rect class="bp-action-status-card" x="42" y="38" width="76" height="84" rx="13"/><path class="bp-action-status-lines" d="M60 62h40M60 80h29M60 98h35"/><path class="bp-action-status-ring" d="M31 61a55 55 0 0 1 91-22l7 8m0 0-2-18m2 18-18-1M129 99a55 55 0 0 1-91 22l-7-8m0 0 2 18m-2-18 18 1"/></svg>';
      }
      return '<svg viewBox="0 0 160 160" focusable="false"><path class="bp-action-save-tray" d="M31 101v28h98v-28M80 25v76m-22-22 22 22 22-22"/><path class="bp-action-save-pulse" d="M48 123h64"/></svg>';
    }

    function hideActionLoader() {
      if (!activeLoader) return;
      window.clearTimeout(safetyTimer);
      const loaderToRemove = activeLoader;
      activeLoader = null;
      document.documentElement.classList.remove('bp-action-loading');
      loaderToRemove.classList.add('is-leaving');
      loaderToRemove.setAttribute('aria-hidden', 'true');
      window.setTimeout(() => loaderToRemove.remove(), 260);
    }

    function showActionLoader(options) {
      const settings = Object.assign({
        variant: 'save',
        title: 'Saving changes',
        message: 'Please wait while BenePeso completes this request.'
      }, options || {});

      if (activeLoader) {
        activeLoader.remove();
        activeLoader = null;
      }

      const pageLoader = document.querySelector('.bp-page-loader');
      if (pageLoader) pageLoader.remove();
      document.documentElement.classList.remove('bp-page-loading');

      activeLoader = document.createElement('div');
      activeLoader.className = 'bp-action-loader bp-action-loader--' + settings.variant;
      activeLoader.setAttribute('role', 'status');
      activeLoader.setAttribute('aria-live', 'polite');
      activeLoader.setAttribute('aria-label', settings.title);
      activeLoader.innerHTML =
        '<div class="bp-action-visual" aria-hidden="true">' + iconFor(settings.variant) + '</div>' +
        '<div class="bp-action-copy"><strong></strong><span></span></div>' +
        '<div class="bp-action-dots" aria-hidden="true"><i></i><i></i><i></i></div>';
      activeLoader.querySelector('.bp-action-copy strong').textContent = settings.title;
      activeLoader.querySelector('.bp-action-copy span').textContent = settings.message;
      document.body.appendChild(activeLoader);
      document.documentElement.classList.add('bp-action-loading');
      safetyTimer = window.setTimeout(hideActionLoader, 30000);
    }

    function submissionDescription(form, submitter) {
      const endpoint = (() => {
        try { return new URL(form.action || location.href, location.href).pathname.split('/').pop().toLowerCase(); }
        catch (error) { return ''; }
      })();
      const actionField = form.elements.namedItem('action');
      const action = String(
        (submitter && submitter.name === 'action' ? submitter.value : '') ||
        (actionField && 'value' in actionField ? actionField.value : '')
      ).toLowerCase();

      if (endpoint === 'logout.php') {
        return { variant: 'logout', title: 'Signing you out', message: 'Closing your BenePeso session securely...' };
      }
      if (endpoint === 'process_login.php') {
        return { variant: 'login', title: 'Signing you in', message: 'Checking your credentials securely...' };
      }
      if (endpoint.startsWith('forgot_') || endpoint === 'update_password_process.php') {
        return { variant: 'login', title: 'Securing your account', message: 'Verifying and updating your account securely...' };
      }
      if (endpoint === 'process_signup.php') {
        return { variant: 'save', title: 'Creating your account', message: 'Saving your beneficiary profile securely...' };
      }
      if (action === 'approve_beneficiary') {
        return { variant: 'approve', title: 'Accepting beneficiary', message: 'Recording the approval and preparing the notification...' };
      }
      if (action === 'reject_beneficiary') {
        return { variant: 'reject', title: 'Rejecting application', message: 'Recording the decision and preparing the notification...' };
      }
      if (action.includes('status') || form.id === 'bulkStatusForm') {
        return { variant: 'status', title: 'Updating beneficiary status', message: 'Saving progress and preparing the notification...' };
      }
      if (endpoint === 'programs.php' && form.id === 'multiStepForm') {
        return { variant: 'save', title: 'Submitting your application', message: 'Saving your information securely...' };
      }
      if (['update_avatar.php', 'update_profile_process.php'].includes(endpoint)) {
        return { variant: 'save', title: 'Updating your profile', message: 'Saving your changes securely...' };
      }
      if (action.includes('beneficiary')) {
        return { variant: 'save', title: 'Saving beneficiary record', message: 'Validating and saving the beneficiary information...' };
      }
      return { variant: 'save', title: 'Saving changes', message: 'Please wait while BenePeso completes this request...' };
    }

    document.addEventListener('submit', (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
      if ((form.method || 'get').toLowerCase() !== 'post') return;
      if (form.target && form.target.toLowerCase() === '_blank') return;
      if (form.hasAttribute('data-no-action-loader')) return;

      event.preventDefault();
      const isAuthenticationForm = document.body.classList.contains('auth-page');
      if (isAuthenticationForm) {
        showBrandedPageLoader();
      } else {
        showActionLoader(submissionDescription(form, event.submitter));
      }

      const submitter = event.submitter;
      window.setTimeout(() => {
        if (!document.contains(form)) return;

        if (submitter && submitter.name) {
          const submittedValue = document.createElement('input');
          submittedValue.type = 'hidden';
          submittedValue.name = submitter.name;
          submittedValue.value = submitter.value;
          form.appendChild(submittedValue);
        }

        HTMLFormElement.prototype.submit.call(form);
      }, submissionDelayMs);
    });

    document.addEventListener('click', (event) => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

      const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
      if (!link || link.hasAttribute('download') || (link.target && link.target.toLowerCase() === '_blank')) return;

      let destination;
      try {
        destination = new URL(link.href, window.location.href);
      } catch (error) {
        return;
      }

      if (destination.origin !== window.location.origin || destination.href === window.location.href) return;

      const pageName = (url) => {
        const name = url.pathname.split('/').pop().toLowerCase();
        return name || 'index.php';
      };
      const currentPage = pageName(window.location);
      const destinationPage = pageName(destination);
      const authFlowPages = ['index.php', 'login.php', 'signup.php'];

      if (!authFlowPages.includes(currentPage) || !authFlowPages.includes(destinationPage) || authNavigationPending) return;

      event.preventDefault();
      authNavigationPending = true;

      showBrandedPageLoader();
      try {
        sessionStorage.setItem('bp-skip-next-page-loader', '1');
      } catch (error) {
        /* Navigation remains safe when storage is unavailable. */
      }

      window.setTimeout(() => window.location.assign(destination.href), 180);
    });

    window.BenePesoLoading = Object.freeze({ show: showActionLoader, hide: hideActionLoader });
    window.addEventListener('pageshow', (event) => { if (event.persisted) hideActionLoader(); });
  }

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

  function improveDisabledLinks() {
    document.querySelectorAll('a.disabled').forEach((link) => {
      link.setAttribute('aria-disabled', 'true');
      link.setAttribute('tabindex', '-1');
      link.addEventListener('click', (event) => event.preventDefault());
    });
  }

  function enhanceMotion() {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const saveData = navigator.connection && navigator.connection.saveData;
    if (reducedMotion || saveData) return;

    document.documentElement.classList.add('bp-motion-enabled');
    document.querySelectorAll('.stats-grid, .program-grid, table tbody').forEach((group) => {
      Array.from(group.children).slice(0, 16).forEach((item, index) => {
        item.style.setProperty('--bp-order', String(index));
        item.classList.add('bp-stagger-item');
      });
    });
  }

  function syncDialogState(container) {
    const visible = isVisible(container);
    container.setAttribute('aria-hidden', visible ? 'false' : 'true');
    if (visible) {
      const dialog = container.matches('[role="dialog"]') ? container : container.querySelector('[role="dialog"]');
      window.setTimeout(() => {
        if (dialog && !dialog.contains(document.activeElement)) {
          const first = Array.from(dialog.querySelectorAll(focusableSelector)).find(isVisible);
          (first || dialog).focus();
        }
      }, 0);
    }
  }

  function observeDialogs() {
    document.querySelectorAll(dialogSelector).forEach((container) => {
      syncDialogState(container);
      new MutationObserver(() => syncDialogState(container)).observe(container, {
        attributes: true,
        attributeFilter: ['class', 'style']
      });
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
    document.documentElement.classList.add('bp-ui-ready');
    initializeFooterContactModal();
    initializeBeneficiaryUpdates();
    initializeProgramSuggestions();
    initializeBackToTop();
    prepareDialogs();
    labelIconButtons();
    improveImages();
    improveTables();
    improveMessages();
    improveDisabledLinks();
    enhanceMotion();
    observeDialogs();
  }

  initializePageLoader();
  initializeActionLoaders();

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
})();
