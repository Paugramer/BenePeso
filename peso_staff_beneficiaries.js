const menuToggle = document.getElementById("menuToggle");
const sideArea = document.getElementById("sideArea");
const sideClose = document.getElementById("sideClose");
const sidebarOverlay = document.getElementById("sidebarOverlay");

function openSidebar() {
  if (!sideArea) return;
  sideArea.classList.add("open");
  if (sidebarOverlay) sidebarOverlay.classList.add("show");
  document.body.style.overflow = "hidden";
}

function closeSidebar() {
  if (!sideArea) return;
  sideArea.classList.remove("open");
  if (sidebarOverlay) sidebarOverlay.classList.remove("show");
  document.body.style.overflow = "";
}

if (menuToggle) {
  menuToggle.addEventListener("click", openSidebar);
}

if (sideClose) {
  sideClose.addEventListener("click", closeSidebar);
}

if (sidebarOverlay) {
  sidebarOverlay.addEventListener("click", closeSidebar);
}

window.addEventListener("resize", () => {
  if (window.innerWidth > 860) {
    closeSidebar();
  }
});

/* add beneficiary modal */
const addBeneficiaryModal = document.getElementById("addBeneficiaryModal");
const addBeneficiaryTriggers = [
  document.getElementById("openAddBeneficiaryModal"),
  document.getElementById("openAddBeneficiaryModal2")
].filter(Boolean);

function openAddBeneficiaryModal() {
  if (!addBeneficiaryModal) return;
  addBeneficiaryModal.classList.add("show");
  document.body.style.overflow = "hidden";
}

function closeAddBeneficiaryModal() {
  if (!addBeneficiaryModal) return;
  addBeneficiaryModal.classList.remove("show");
  document.body.style.overflow = "";
}

addBeneficiaryTriggers.forEach(btn => {
  btn.addEventListener("click", openAddBeneficiaryModal);
});

document.querySelectorAll("[data-close-modal]").forEach(btn => {
  btn.addEventListener("click", closeAddBeneficiaryModal);
});

/* success modal */
const successModal = document.getElementById("successModal");

function closeSuccessModal() {
  if (!successModal) return;
  successModal.classList.remove("show");
  document.body.style.overflow = "";
}

document.querySelectorAll("[data-close-success]").forEach(btn => {
  btn.addEventListener("click", closeSuccessModal);
});

/* auto submit filters */
const filterForm = document.getElementById("filterForm");
const autoSubmitFields = document.querySelectorAll(".auto-submit");

autoSubmitFields.forEach(field => {
  field.addEventListener("change", () => {
    if (filterForm) filterForm.submit();
  });
});

const searchInput = document.querySelector('input[name="search"]');
let searchTimer = null;

if (searchInput && filterForm) {
  searchInput.addEventListener("input", () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      filterForm.submit();
    }, 500);
  });
}

/* global close */
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    closeSidebar();
    closeAddBeneficiaryModal();
    closeSuccessModal();
  }
});

document.addEventListener("click", function (e) {
  if (e.target.classList.contains("modal-backdrop")) {
    closeAddBeneficiaryModal();
    closeSuccessModal();
  }
});