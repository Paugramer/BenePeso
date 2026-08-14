document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('.search-box');
    const button = form.querySelector('button');
    const input = form.querySelector('input');

    form.addEventListener('submit', (e) => {
        if (input.value.trim().length < 3) {
            e.preventDefault();
            alert("Please enter a valid email or contact number.");
            return;
        }

        // Show loading state
        button.innerText = "Searching...";
        button.style.opacity = "0.7";
        button.disabled = true;
    });
});