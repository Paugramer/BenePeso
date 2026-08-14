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

document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    closeSidebar();
  }
});