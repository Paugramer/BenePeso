function openModal(id){
  const el = document.getElementById(id);
  el.style.display = "flex";
  el.setAttribute("aria-hidden", "false");
  document.body.style.overflow = "hidden";
}
function closeModal(id){
  const el = document.getElementById(id);
  el.style.display = "none";
  el.setAttribute("aria-hidden", "true");
  document.body.style.overflow = "";
}

const sideArea = document.getElementById("sideArea");
const menuToggle = document.getElementById("menuToggle");
const sideClose = document.getElementById("sideClose");

if(menuToggle){
  menuToggle.addEventListener("click", ()=> sideArea.classList.add("open"));
}
if(sideClose){
  sideClose.addEventListener("click", ()=> sideArea.classList.remove("open"));
}

// close sidebar when clicking outside on mobile
document.addEventListener("click", (e)=>{
  if(window.innerWidth <= 920){
    if(sideArea.classList.contains("open")){
      const clickedInside = sideArea.contains(e.target) || menuToggle.contains(e.target);
      if(!clickedInside) sideArea.classList.remove("open");
    }
  }
});

// modal controls
const openAdd = document.getElementById("openAdd");
const closeForm = document.getElementById("closeForm");
const cancelForm = document.getElementById("cancelForm");
const formBg = document.getElementById("formBg");

if(openAdd){
  openAdd.addEventListener("click", ()=>{
    resetFormToAdd();
    openModal("formBg");
  });
}
if(closeForm) closeForm.addEventListener("click", ()=> closeModal("formBg"));
if(cancelForm) cancelForm.addEventListener("click", ()=> closeModal("formBg"));

// close modal on outside click
if(formBg){
  formBg.addEventListener("click", (e)=>{
    if(e.target === formBg) closeModal("formBg");
  });
}

// close on ESC
document.addEventListener("keydown", (e)=>{
  if(e.key === "Escape"){
    if(formBg && formBg.style.display === "flex") closeModal("formBg");
  }
});

// form set
function resetFormToAdd(){
  document.getElementById("formTitle").textContent = "Add Program";
  document.getElementById("formSub").textContent = "Fill out the program information.";
  document.getElementById("formAction").value = "add";
  document.getElementById("programId").value = "0";

  ["code","title","venue","description","eligibility","requirements"].forEach(id=>{
    document.getElementById(id).value = "";
  });

  document.getElementById("status").value = "Upcoming";
  document.getElementById("slots").value = "0";
  document.getElementById("start_date").value = "";
  document.getElementById("end_date").value = "";

  // reset image
  const image = document.getElementById("image");
  image.value = "";
  hidePreview();
}

window.openEdit = function(program_id, code, title, status, slots, start_date, end_date, venue, description, eligibility, requirements, image_path){
  document.getElementById("formTitle").textContent = "Edit Program";
  document.getElementById("formSub").textContent = "Update the program details.";
  document.getElementById("formAction").value = "edit";
  document.getElementById("programId").value = program_id;

  document.getElementById("code").value = code || "";
  document.getElementById("title").value = title || "";
  document.getElementById("status").value = status || "Upcoming";
  document.getElementById("slots").value = slots || 0;
  document.getElementById("start_date").value = start_date || "";
  document.getElementById("end_date").value = end_date || "";
  document.getElementById("venue").value = venue || "";
  document.getElementById("description").value = description || "";
  document.getElementById("eligibility").value = eligibility || "";
  document.getElementById("requirements").value = requirements || "";

  // reset file input (only upload if changing)
  document.getElementById("image").value = "";

  // show old preview if exists
  if(image_path){
    showPreview(image_path, "Current image");
  } else {
    hidePreview();
  }

  openModal("formBg");
};

// image preview
const imageInput = document.getElementById("image");
const previewArea = document.getElementById("previewArea");
const previewImg = document.getElementById("previewImg");
const previewName = document.getElementById("previewName");

function showPreview(src, name){
  previewImg.src = src;
  previewName.textContent = name || "";
  previewArea.style.display = "block";
}
function hidePreview(){
  previewImg.src = "";
  previewName.textContent = "";
  previewArea.style.display = "none";
}

if(imageInput){
  imageInput.addEventListener("change", ()=>{
    const file = imageInput.files && imageInput.files[0];
    if(!file){ return; }
    const url = URL.createObjectURL(file);
    showPreview(url, file.name);
  });
}
const notice = document.querySelector('.notice-box');
if(notice){
    setTimeout(() => {
        notice.classList.remove('show');
        notice.classList.add('hide');
    }, 3000);
}
// Notification auto hide FIX
window.addEventListener("load", function(){
    const notice = document.querySelector('.notice-box');
    if(notice){
        notice.classList.add("show");

        setTimeout(() => {
            notice.classList.remove("show");
            notice.classList.add("hide");
        }, 3000);
    }
});
let deleteForm = null;

document.querySelectorAll('.btn-delete').forEach(btn=>{
    btn.addEventListener('click', function(){
        deleteForm = this.closest("form");
        openModal("deleteModal");
    });
});

document.getElementById("closeDelete").onclick = () => closeModal("deleteModal");
document.getElementById("cancelDelete").onclick = () => closeModal("deleteModal");

document.getElementById("confirmDelete").onclick = function(){
    if(deleteForm){
        deleteForm.submit();
    }
};