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

/* =========================
   ADD MODAL
========================= */
const addModal = document.getElementById("addModal");
const addTriggers = [
  document.getElementById("openAddModal"),
  document.getElementById("openAddModal2")
].filter(Boolean);

function openAddModal() {
  if (!addModal) return;
  addModal.classList.add("show");
  document.body.style.overflow = "hidden";
}

function closeAddModal() {
  if (!addModal) return;
  addModal.classList.remove("show");
  document.body.style.overflow = "";
}

addTriggers.forEach(btn => {
  btn.addEventListener("click", openAddModal);
});

document.querySelectorAll("[data-close-modal]").forEach(btn => {
  btn.addEventListener("click", closeAddModal);
});

/* =========================
   EDIT MODAL
========================= */
const editModal = document.getElementById("editModal");

const editProgramId = document.getElementById("edit_program_id");
const editProgramCode = document.getElementById("edit_program_code");
const editProgramName = document.getElementById("edit_program_name");
const editDescription = document.getElementById("edit_description");
const editEligibility = document.getElementById("edit_eligibility");
const editRequirements = document.getElementById("edit_requirements");
const editStartDate = document.getElementById("edit_start_date");
const editEndDate = document.getElementById("edit_end_date");
const editVenue = document.getElementById("edit_venue");
const editSlots = document.getElementById("edit_slots");
const editStatus = document.getElementById("edit_status");
const editCurrentImage = document.getElementById("edit_current_image");

function openEditModal() {
  if (!editModal) return;
  editModal.classList.add("show");
  document.body.style.overflow = "hidden";
}

function closeEditModal() {
  if (!editModal) return;
  editModal.classList.remove("show");
  document.body.style.overflow = "";
}

document.querySelectorAll("[data-close-edit]").forEach(btn => {
  btn.addEventListener("click", closeEditModal);
});

document.querySelectorAll(".open-edit-modal").forEach(button => {
  button.addEventListener("click", function () {
    editProgramId.value = this.dataset.program_id || "";
    editProgramCode.value = this.dataset.program_code || "";
    editProgramName.value = this.dataset.program_name || "";
    editDescription.value = this.dataset.description || "";
    editEligibility.value = this.dataset.eligibility || "";
    editRequirements.value = this.dataset.requirements || "";
    editStartDate.value = this.dataset.start_date || "";
    editEndDate.value = this.dataset.end_date || "";
    editVenue.value = this.dataset.venue || "";
    editSlots.value = this.dataset.slots || 0;
    editStatus.value = this.dataset.status || "Upcoming";
    editCurrentImage.value = this.dataset.image_path || "";

    openEditModal();
  });
});

/* =========================
   SUCCESS MODAL
========================= */
const successModal = document.getElementById("successModal");

function closeSuccessModal() {
  if (!successModal) return;
  successModal.classList.remove("show");
  document.body.style.overflow = "";
}

document.querySelectorAll("[data-close-success]").forEach(btn => {
  btn.addEventListener("click", closeSuccessModal);
});

/* =========================
   GLOBAL CLOSE
========================= */
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    closeSidebar();
    closeAddModal();
    closeEditModal();
    closeSuccessModal();
  }
});

document.addEventListener("click", function (e) {
  if (e.target.classList.contains("modal-backdrop")) {
    closeAddModal();
    closeEditModal();
    closeSuccessModal();
  }
});