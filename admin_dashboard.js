const menuToggle = document.getElementById("menuToggle");
const sideArea = document.getElementById("sideArea");
const sideClose = document.getElementById("sideClose");

if (menuToggle && sideArea) {
  menuToggle.addEventListener("click", () => {
    sideArea.classList.add("open");
  });
}
if (sideClose && sideArea) {
  sideClose.addEventListener("click", () => {
    sideArea.classList.remove("open");
  });
}

// close sidebar if click outside (mobile)
document.addEventListener("click", (e) => {
  if (!sideArea) return;
  if (!sideArea.classList.contains("open")) return;

  const clickedInside = sideArea.contains(e.target) || (menuToggle && menuToggle.contains(e.target));
  if (!clickedInside) sideArea.classList.remove("open");
});

// beneficiaries search (sample)
const searchInput = document.getElementById("searchInput");
const benefTable = document.getElementById("benefTable");

if (searchInput && benefTable) {
  searchInput.addEventListener("input", () => {
    const term = searchInput.value.toLowerCase().trim();
    const rows = benefTable.querySelectorAll("tbody tr");

    rows.forEach((row) => {
      const text = row.innerText.toLowerCase();
      row.style.display = text.includes(term) ? "" : "none";
    });
  });
}

// mini modal
const miniBg = document.getElementById("miniBg");
const miniText = document.getElementById("miniText");

function openMiniModal(text){
  if (!miniBg || !miniText) return;
  miniText.textContent = text;
  miniBg.style.display = "flex";
}
function closeMiniModal(){
  if (!miniBg) return;
  miniBg.style.display = "none";
}

window.openMiniModal = openMiniModal;
window.closeMiniModal = closeMiniModal;

if (miniBg) {
  miniBg.addEventListener("click", (e) => {
    if (e.target === miniBg) closeMiniModal();
  });
}
