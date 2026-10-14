(() => {
 const search=document.getElementById('reportSearch');let searchTimer;search?.addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>search.form.requestSubmit(),600);});document.querySelectorAll('.report-filter-fields select').forEach(select=>select.addEventListener('change',()=>select.form.requestSubmit()));
 const toggle=document.getElementById('reportFilterToggle');const fields=document.getElementById('reportFilterFields');
 toggle?.addEventListener('click',()=>{fields.hidden=!fields.hidden;toggle.setAttribute('aria-expanded',String(!fields.hidden));});
})();
