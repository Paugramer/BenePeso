document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('searchForm');
    const input = document.getElementById('searchInput');
    const program = document.getElementById('programFilter');
    const button = form?.querySelector('button[type="submit"]');
    if (!form || !input || !program || !button) return;

    form.addEventListener('submit', event => {
        if (input.value.trim().length < 5 || !program.value) {
            event.preventDefault();
            form.reportValidity();
            return;
        }

        button.querySelector('span')?.replaceChildren('Verifying...');
        button.style.opacity = '0.7';
        button.disabled = true;
    });
});
