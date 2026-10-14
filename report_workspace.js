(() => {
 const toggle=document.getElementById('reportFilterToggle');const fields=document.getElementById('reportFilterFields');
 toggle?.addEventListener('click',()=>{fields.hidden=!fields.hidden;toggle.setAttribute('aria-expanded',String(!fields.hidden));});
 const dialog=document.getElementById('reportDetailDialog');const body=document.getElementById('reportDetailBody');let trigger;
 document.querySelectorAll('[data-view-report]').forEach(button=>button.addEventListener('click',()=>{const template=document.getElementById('reportRecord-'+button.dataset.viewReport);if(!template)return;trigger=button;body.replaceChildren(template.content.cloneNode(true));dialog.showModal();}));
 document.querySelectorAll('[data-close-report]').forEach(button=>button.addEventListener('click',()=>dialog.close()));dialog?.addEventListener('close',()=>trigger?.focus());
})();
