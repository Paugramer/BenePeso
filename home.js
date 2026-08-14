const menuButton = document.getElementById("menuButton");
const menuArea = document.getElementById("menuArea");

if (menuButton && menuArea) {
  menuButton.addEventListener("click", () => {
    menuArea.classList.toggle("open");
  });
}

const accountButton = document.getElementById("accountButton");
const accountDropdown = document.getElementById("accountDropdown");
const accountWrap = document.getElementById("accountWrap");

function closeAccount() {
  if (accountDropdown) accountDropdown.style.display = "none";
}

if (accountButton && accountDropdown) {
  accountButton.addEventListener("click", (e) => {
    e.stopPropagation();
    const open = accountDropdown.style.display === "block";
    accountDropdown.style.display = open ? "none" : "block";
  });
}

document.addEventListener("click", (e) => {
  if (accountWrap && !accountWrap.contains(e.target)) {
    closeAccount();
  }
});
