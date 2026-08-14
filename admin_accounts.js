const menuToggle = document.getElementById("menuToggle");
const sideArea = document.getElementById("sideArea");
const sideClose = document.getElementById("sideClose");
const sidebarOverlay = document.getElementById("sidebarOverlay");

function openSidebar() {
    sideArea.classList.add("open");
    sidebarOverlay.classList.add("show");
}

function closeSidebar() {
    sideArea.classList.remove("open");
    sidebarOverlay.classList.remove("show");
}

if(menuToggle) menuToggle.addEventListener("click", openSidebar);
if(sideClose) sideClose.addEventListener("click", closeSidebar);
if(sidebarOverlay) sidebarOverlay.addEventListener("click", closeSidebar);

// Modal Logic
function confirmDelete(id, type) {
    document.getElementById("delete_id").value = id;
    document.getElementById("account_type").value = type;
    document.getElementById("deleteModal").classList.add("show");
}

function closeModal() {
    document.getElementById("deleteModal").classList.remove("show");
}