function switchTab(evt, tabName) {
    const tabContents = document.querySelectorAll(".tab-content");
    tabContents.forEach(content => content.classList.remove("active"));

    const tabLinks = document.querySelectorAll(".tab-link");
    tabLinks.forEach(link => link.classList.remove("active"));

    document.getElementById(tabName).classList.add("active");
    evt.currentTarget.classList.add("active");
}

// Password Form Logic
document.getElementById('passwordForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const pass = document.getElementById('new_pass').value;
    const confirm = document.getElementById('confirm_pass').value;

    if (pass !== confirm) {
        alert("Passwords do not match!");
        return;
    }

    // In a real scenario, use fetch() here to call an update_profile.php script
    alert("Password update requested. (Back-end processing needed)");
});