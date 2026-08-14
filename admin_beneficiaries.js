// ================= SIDEBAR =================
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

if (menuToggle) menuToggle.addEventListener("click", openSidebar);
if (sideClose) sideClose.addEventListener("click", closeSidebar);
if (sidebarOverlay) sidebarOverlay.addEventListener("click", closeSidebar);


// ================= FILTER AUTO SUBMIT =================
document.querySelectorAll(".auto-submit").forEach(el => {
    el.addEventListener("change", () => {
        document.getElementById("filterForm").submit();
    });
});

// ================= SEARCH DEBOUNCE =================
let searchTimer;
const searchInp = document.querySelector('input[name="search"]');

if (searchInp) {
    searchInp.addEventListener("input", () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            document.getElementById("filterForm").submit();
        }, 700);
    });
}


// ================= DELETE MODAL =================
function confirmDelete(id) {
    document.getElementById("delete_id").value = id;
    document.getElementById("deleteModal").classList.add("show");
}
function closeModal() {
    document.getElementById("deleteModal").classList.remove("show");
}


// ================= ADMIN MODAL =================
const adminModal = document.getElementById("adminAddBeneficiaryModal");
const adminBtn = document.getElementById("adminOpenAddBeneficiaryModal");

// OPEN ADD MODAL
if (adminBtn) {
    adminBtn.addEventListener("click", () => {
        openAddModal();
    });
}

// CLOSE MODAL
document.querySelectorAll("[data-close-admin-modal]").forEach(btn => {
    btn.addEventListener("click", closeAdminModal);
});

function closeAdminModal() {
    adminModal.classList.remove("show");
    document.body.style.overflow = "";
}

// ================= ADD MODE =================
function openAddModal() {
    // reset form
    document.getElementById("modalAction").value = "admin_add_beneficiary";
    document.getElementById("edit_id").value = "";

    document.getElementById("modalTitle").innerText = "Add Beneficiary";

    document.querySelectorAll("#adminAddBeneficiaryModal input, #adminAddBeneficiaryModal textarea").forEach(el => {
        if (el.type !== "hidden") el.value = "";
    });

    document.querySelectorAll("#adminAddBeneficiaryModal select").forEach(el => {
        el.selectedIndex = 0;
    });

    adminModal.classList.add("show");
    document.body.style.overflow = "hidden";
}


// ================= EDIT MODE =================
function openEditModal(data) {
    // change mode
    document.getElementById("modalAction").value = "admin_update_beneficiary";
    document.getElementById("edit_id").value = data.beneficiary_id;

    document.getElementById("modalTitle").innerText = "Edit Beneficiary";

    // fill fields
    document.getElementById("full_name").value = data.full_name ?? "";
    document.getElementById("email").value = data.email ?? "";
    document.getElementById("contact_no").value = data.contact_no ?? "";
    document.getElementById("barangay").value = data.barangay ?? "";
    document.getElementById("municipality").value = data.municipality ?? "Vinzons";
    document.getElementById("status").value = data.status ?? "Pending";
    document.getElementById("availment_status").value = data.availment_status ?? "Not Yet Availed";
    document.getElementById("date_availed").value = data.date_availed ?? "";
    document.getElementById("date_completed").value = data.date_completed ?? "";
    document.getElementById("address").value = data.address ?? "";

    adminModal.classList.add("show");
    document.body.style.overflow = "hidden";
}


// ================= ESC KEY =================
document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
        closeSidebar();
        closeModal();
        closeAdminModal();
    }
});