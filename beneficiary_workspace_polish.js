document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('#filterForm.records-filter-container');
  if (!form) return;

  const toggle = form.querySelector('.beneficiary-filter-toggle');
  const filters = form.querySelector('.beneficiary-secondary-filters');
  if (!toggle || !filters) return;

  const selects = Array.from(filters.querySelectorAll('select'));
  const search = form.querySelector('input[name="search"]');
  const counter = toggle.querySelector('.beneficiary-filter-count');

  const activeSelects = () => selects.filter((select) => select.selectedIndex > 0);
  const updateCounter = () => {
    const count = activeSelects().length;
    if (!counter) return;
    counter.textContent = String(count);
    counter.hidden = count === 0;
  };

  const setExpanded = (expanded) => {
    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    filters.hidden = !expanded;
  };

  const submitFilters = () => {
    const page = form.querySelector('input[name="page"]');
    if (page) page.value = '1';
    if (typeof form.requestSubmit === 'function') form.requestSubmit();
    else form.submit();
  };

  const renderActiveFilters = () => {
    const existing = form.nextElementSibling;
    if (existing && existing.classList.contains('beneficiary-active-filters')) existing.remove();

    const active = activeSelects();
    const hasSearch = Boolean(search && search.value.trim());
    if (!active.length && !hasSearch) return;

    const tray = document.createElement('div');
    tray.className = 'beneficiary-active-filters';
    tray.setAttribute('aria-label', 'Active beneficiary filters');

    if (hasSearch) {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'beneficiary-active-filter';
      chip.innerHTML = '<i class="ph ph-magnifying-glass"></i><span></span><i class="ph ph-x"></i>';
      chip.querySelector('span').textContent = `Search: ${search.value.trim()}`;
      chip.addEventListener('click', () => { search.value = ''; submitFilters(); });
      tray.appendChild(chip);
    }

    active.forEach((select) => {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'beneficiary-active-filter';
      chip.innerHTML = '<i class="ph ph-funnel"></i><span></span><i class="ph ph-x"></i>';
      chip.querySelector('span').textContent = select.options[select.selectedIndex].text;
      chip.addEventListener('click', () => { select.selectedIndex = 0; submitFilters(); });
      tray.appendChild(chip);
    });

    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'beneficiary-active-filter beneficiary-clear-filters';
    clear.innerHTML = '<i class="ph ph-eraser"></i><span>Clear all</span>';
    clear.addEventListener('click', () => {
      selects.forEach((select) => { select.selectedIndex = 0; });
      if (search) search.value = '';
      submitFilters();
    });
    tray.appendChild(clear);
    form.insertAdjacentElement('afterend', tray);
  };

  const hasActiveFilters = activeSelects().length > 0;
  setExpanded(hasActiveFilters);
  updateCounter();
  renderActiveFilters();

  toggle.addEventListener('click', () => setExpanded(toggle.getAttribute('aria-expanded') !== 'true'));
  selects.forEach((select) => select.addEventListener('change', updateCounter));

  const modalIcons = {
    bulkStatusActionModal: 'ph-users-three',
    adminAddBeneficiaryModal: 'ph-user-plus',
    quickStatusModal: 'ph-timer',
    bulkUploadModal: 'ph-upload-simple'
  };

  Object.entries(modalIcons).forEach(([modalId, iconClass]) => {
    const modal = document.getElementById(modalId);
    const head = modal?.querySelector('.modal-head-alt, .modal-head-sm');
    if (!head || head.querySelector('.beneficiary-modal-icon')) return;
    const icon = document.createElement('span');
    icon.className = 'beneficiary-modal-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = `<i class="ph ${iconClass}"></i>`;
    head.prepend(icon);
  });

  document.querySelectorAll('.modal').forEach((modal, index) => {
    const title = modal.querySelector('.modal-title');
    if (title) {
      if (!title.id) title.id = `beneficiaryModalTitle${index + 1}`;
      modal.setAttribute('aria-labelledby', title.id);
    }
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const openModals = Array.from(document.querySelectorAll('.modal.show'));
    const activeModal = openModals[openModals.length - 1];
    const closeButton = activeModal?.querySelector('.modal-close-icon, [data-close-beneficiary-notice], [data-close-success], [data-close-error]');
    closeButton?.click();
  });

  const bulkModal = document.getElementById('bulkStatusActionModal');
  const bulkPreview = document.getElementById('bulkSelectedPreview');
  const bulkPeople = document.getElementById('bulkSelectedPeople');
  const bulkGuidance = document.getElementById('bulkSelectionGuidance');
  const bulkBoxes = Array.from(document.querySelectorAll('.beneficiary-select'));

  if (bulkModal && bulkPeople && bulkBoxes.length) {
    const program = (bulkModal.dataset.program || '').toUpperCase();
    const bulkStatus = document.getElementById('bulkAvailmentStatus');
    const allowedStatuses = program.includes('SPES')
      ? ['Requirements Received', 'Examination', 'Exam Passed', 'Exam Failed', 'Orientation', 'Ongoing', 'Completed', 'Not Qualified', 'Cancelled']
      : program.includes('TUPAD')
        ? ['Requirements Received', 'Orientation', 'Ongoing', 'Salary Distribution', 'Completed', 'Not Qualified', 'Cancelled']
        : ['Requirements Received', 'Orientation', 'Ongoing', 'Completed', 'Not Qualified', 'Cancelled'];

    if (bulkStatus) {
      Array.from(bulkStatus.options).forEach((option) => {
        if (!allowedStatuses.includes(option.value)) option.remove();
      });
      bulkStatus.dispatchEvent(new Event('change'));
    }

    const renderBulkSelection = () => {
      const chosen = bulkBoxes.filter((box) => box.checked);
      const names = chosen.map((box) => box.dataset.beneficiaryName || `Beneficiary #${box.value}`);

      if (bulkPreview) {
        bulkPreview.textContent = names.length > 3
          ? `${names.slice(0, 3).join(', ')} +${names.length - 3} more`
          : names.join(', ');
      }

      bulkPeople.replaceChildren();
      chosen.forEach((box) => {
        const row = box.closest('tr');
        const name = box.dataset.beneficiaryName || `Beneficiary #${box.value}`;
        const statusLabel = box.dataset.beneficiaryStatus || 'Status unavailable';
        const person = document.createElement('div');
        person.className = 'bulk-selected-person';

        const avatar = document.createElement('span');
        avatar.className = 'bulk-selected-avatar';
        avatar.textContent = name.charAt(0).toUpperCase() || '?';

        const identity = document.createElement('span');
        identity.className = 'bulk-selected-identity';
        const strong = document.createElement('strong');
        strong.textContent = name;
        const small = document.createElement('small');
        small.textContent = statusLabel;
        identity.append(strong, small);

        const actions = document.createElement('span');
        actions.className = 'bulk-selected-actions';
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'bulk-person-action';
        edit.innerHTML = '<i class="ph ph-pencil-simple"></i><span>Edit record</span>';
        edit.addEventListener('click', () => {
          bulkModal.classList.remove('show');
          bulkModal.setAttribute('aria-hidden', 'true');
          document.body.style.overflow = '';
          const profile = row?.getAttribute('data-profile');
          if (profile && typeof window.openEditModal === 'function') window.openEditModal(profile);
        });

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'bulk-person-action bulk-person-remove';
        remove.innerHTML = '<i class="ph ph-x"></i><span>Remove</span>';
        remove.addEventListener('click', () => {
          box.checked = false;
          box.dispatchEvent(new Event('change', { bubbles: true }));
        });
        actions.append(edit, remove);
        person.append(avatar, identity, actions);
        bulkPeople.appendChild(person);
      });

      if (bulkGuidance) {
        const stages = [...new Set(chosen.map((box) => box.dataset.beneficiaryStatus || '').filter(Boolean))];
        bulkGuidance.classList.toggle('is-warning', stages.length > 1);
        bulkGuidance.innerHTML = stages.length > 1
          ? '<i class="ph ph-warning-circle"></i><span>These records are in different stages. The system will update only records eligible for the selected next stage.</span>'
          : '<i class="ph ph-shield-check"></i><span>Each record will be validated against the required program sequence before it is updated.</span>';
      }
    };

    bulkBoxes.forEach((box) => box.addEventListener('change', renderBulkSelection));
    document.getElementById('openBulkStatusModal')?.addEventListener('click', renderBulkSelection);
    document.getElementById('clearBulkSelection')?.addEventListener('click', renderBulkSelection);
    renderBulkSelection();
  }
});
