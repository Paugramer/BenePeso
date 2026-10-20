(() => {
  if (typeof window.showBeneficiaryNotice !== 'function') {
    window.showBeneficiaryNotice = (title,message) => window.alert(title+'\n'+message);
  }
  const program = document.getElementById('requirementsProgram');
  program?.addEventListener('change', () => {
    document.getElementById('requirementsBatch').value = '0';
    program.form.requestSubmit();
  });
  const filters = document.getElementById('requirementsFilters');
  document.getElementById('submissionFilterToggle')?.addEventListener('click', event => {
    const fields = document.getElementById('submissionFilterFields');
    fields.hidden = !fields.hidden;
    filters.classList.toggle('filters-open', !fields.hidden);
    event.currentTarget.setAttribute('aria-expanded', String(!fields.hidden));
  });
  filters?.querySelectorAll('select:not(#requirementsProgram)').forEach(select => {
    select.addEventListener('change', () => filters.requestSubmit());
  });
  document.querySelectorAll('[data-requirements-format]').forEach(link => {
    link.addEventListener('click', () => {
      const query = new URLSearchParams(new FormData(filters));
      query.set('format', link.dataset.requirementsFormat);
      link.href = 'requirements_report.php?' + query;
    });
  });
  if (document.body.classList.contains('requirements-print')) {
    const header = document.getElementById('requirementsLetterhead');
    const print = () => { window.focus(); window.print(); };
    if (header.complete) print();
    else { header.addEventListener('load', print, {once:true}); header.addEventListener('error', print, {once:true}); }
  }
})();
