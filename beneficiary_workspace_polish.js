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
});
