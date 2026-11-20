(() => {
  document.querySelectorAll('[data-date-range]').forEach(range => {
    if (range.dataset.dateReady) return;
    range.dataset.dateReady = '1';
    const from = range.querySelector('[name="date_from"]');
    const to = range.querySelector('[name="date_to"]');
    const validate = () => {
      to.setCustomValidity(from.value && to.value && from.value > to.value
        ? 'To date must be on or after From date.' : '');
    };
    [from, to].forEach(input => input.addEventListener('input', validate));
    range.querySelector('[data-clear-report-dates]').addEventListener('click', () => {
      from.value = ''; to.value = ''; validate();
      if (range.closest('.report-form')) to.dispatchEvent(new Event('change', {bubbles:true}));
      else range.closest('form').requestSubmit();
    });
    validate();
  });
})();
