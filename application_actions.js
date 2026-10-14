// Keep each action beside the application information it changes.
window.organizeApplicationActions = (data, role) => {
  const modal = document.getElementById('profileModal');
  const footer = document.getElementById('pm_footer_actions');
  if (!modal || !footer) return;
  const sections = [...modal.querySelectorAll('.resume-section')];
  const findSection = title => sections.find(section => section.querySelector('h4 span')?.textContent === title);
  const personal = findSection('Personal Information');
  const progress = findSection('Program & Status');
  const addTools = section => {
    if (!section) return null;
    const tools = document.createElement('div');
    tools.className = 'application-section-actions';
    section.appendChild(tools);
    return tools;
  };
  const infoTools = addTools(personal || sections[0]);
  const progressTools = addTools(progress);
  const edit = [...footer.querySelectorAll('button')].find(button => button.getAttribute('onclick')?.includes('openEditModal'));
  if (edit && infoTools) {
    edit.innerHTML = '<i class="ph ph-pencil-simple" aria-hidden="true"></i><span>Edit Information</span>';
    infoTools.appendChild(edit);
  } else if (infoTools && ['Pending', 'Approved'].includes(data.approval)) {
    const button = document.createElement('button');
    button.type = 'button'; button.className = 'btn-light';
    button.innerHTML = '<i class="ph ph-pencil-simple" aria-hidden="true"></i><span>Edit Information</span>';
    button.addEventListener('click', () => window.openEditModal(window.currentProfileData));
    infoTools.appendChild(button);
  }
  if (progressTools) {
    [...footer.children].forEach(element => {
      if (element.tagName === 'FORM' || element.classList.contains('btn-quick-status') || element.classList.contains('btn-danger')) progressTools.appendChild(element);
    });
  }
  // Printable application forms belong with the applicant's information.
  if (infoTools) [...footer.querySelectorAll('button')].forEach(button => {
    if (/openSpesForm|openMsmeForm/.test(button.getAttribute('onclick') || '')) infoTools.appendChild(button);
  });
  const id = Number(data.id);
  if (Number.isSafeInteger(id) && id > 0) window.mountApplicationRequirements?.(modal, id);
  if (data.approval === 'Approved' && progress && Number.isSafeInteger(id) && id > 0) {
    const program = String(data.program || '').toUpperCase();
    const label = program.includes('SPES') ? 'Work Assignment' : program.includes('MSME') ? 'Night Market Earnings' : 'Remarks';
    const details = document.createElement('details');
    details.className = 'application-optional-details';
    const summary = document.createElement('summary'); summary.textContent = 'Additional Information';
    const link = document.createElement('a');
    link.href = `application_reporting.php?id=${id}&role=${role === 'admin' ? 'admin' : 'peso_staff'}&embedded=1`;
    link.textContent = `Edit ${label.toLowerCase()}`;
    link.addEventListener('click', event => {
      event.preventDefault();
      window.openApplicationExtra(link.href, label);
    });
    details.append(summary, link); progress.appendChild(details);
  }
};

(() => {
  let parentState = null;
  const profile = () => document.getElementById('profileModal');
  const rememberProfile = () => {
    const modal = profile();
    if (!modal?.classList.contains('show')) return;
    parentState = { focus: document.activeElement, scroll: [modal, ...modal.querySelectorAll('*')].filter(el => el.scrollTop).map(el => [el, el.scrollTop]) };
  };
  const restoreProfile = () => {
    const state = parentState; parentState = null;
    if (!state || !profile()) return;
    profile().classList.add('show');profile().setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => {
      state.scroll.forEach(([element, top]) => { element.scrollTop = top; });
      state.focus?.focus({ preventScroll: true });
    });
  };
  document.addEventListener('click', event => {
    const button = event.target.closest('button');
    if (button?.closest('#profileModal .application-section-actions') &&
        (button.classList.contains('btn-quick-status') || button.textContent.includes('Edit Information'))) rememberProfile();
    if (parentState && event.target.closest('[data-close-modal], [data-close-quick]')) setTimeout(restoreProfile, 0);
  }, true);
  const makeDialog = title => {
    const dialog = document.createElement('dialog');dialog.className = 'application-extra-dialog';
    const header = document.createElement('header');header.className = 'application-extra-header';
    const heading = document.createElement('h2');heading.textContent = title;
    const close = document.createElement('button');close.type = 'button';close.textContent = '×';close.setAttribute('aria-label', 'Close and return');
    close.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); dialog.close(); }, true);header.append(heading, close);dialog.appendChild(header);
    document.body.appendChild(dialog);
    dialog.addEventListener('close', () => { dialog.remove();restoreProfile(); });
    return dialog;
  };
  const closeDialogOnHeader = dialog => {
    dialog.addEventListener('click', event => {
      if (event.target.closest('.application-extra-header button')) {
        event.preventDefault(); event.stopImmediatePropagation(); dialog.close();
      }
    }, true);
  };
  window.openApplicationExtra = (url, label) => {
    rememberProfile();profile()?.classList.remove('show');profile()?.setAttribute('aria-hidden', 'true');
    const dialog = makeDialog(label);
    const frame = document.createElement('iframe');frame.title = label;frame.src = url;
    dialog.appendChild(frame);dialog.showModal();closeDialogOnHeader(dialog);
  };
  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('openBulkRemarks')?.addEventListener('click', event => {
      const ids = [...document.querySelectorAll('.beneficiary-select:checked')].map(box => Number(box.value));
      if (!ids.length) return;
      const role = event.currentTarget.dataset.role;
      const dialog = makeDialog('Edit Remarks');
      const form = document.createElement('form');form.className = 'application-bulk-remarks-form';
      const description = document.createElement('p');description.textContent = `Apply the same remarks to ${ids.length} selected applications. This replaces their current remarks.`;
      const label = document.createElement('label');label.textContent = 'Remarks';label.htmlFor = 'bulkRemarksText';
      const input = document.createElement('textarea');input.id = 'bulkRemarksText';input.maxLength = 3000;input.rows = 6;
      const status = document.createElement('p');status.setAttribute('role', 'status');
      const submit = document.createElement('button');submit.type = 'submit';submit.className = 'btn-main';submit.textContent = 'Apply Remarks';
      form.append(description, label, input, status, submit);dialog.appendChild(form);dialog.showModal();input.focus();
      form.addEventListener('submit', async event => {
        event.preventDefault();submit.disabled = true;status.textContent = 'Saving remarks…';
        try {
          const csrf = document.querySelector('input[name="csrf_token"]')?.value;
          const response = await fetch('application_bulk_remarks.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids, remarks: input.value, role, csrf_token: csrf }) });
          const result = await response.json();
          if (!response.ok) throw new Error(result.error || 'Remarks could not be saved.');
          status.textContent = `Remarks saved for ${result.count} applications.`;submit.textContent = 'Saved';
        } catch (error) { status.textContent = error.message;submit.disabled = false; }
      });
    });
  });
})();

window.mountApplicationRequirements = async (modal, id) => {
  modal.querySelector('.application-requirements')?.remove();
  const section = document.createElement('section'); section.className = 'resume-section application-requirements';
  const heading = document.createElement('h4'); heading.textContent = 'Requirements Checklist';
  const help = document.createElement('p');help.textContent = 'Review each document against the batch requirements. Received documents still need verification. Saving this checklist does not send an email.';
  const feedback = document.createElement('p');feedback.setAttribute('role','status');feedback.textContent='Loading requirements?';
  section.append(heading,help,feedback);
  const first = modal.querySelector('.resume-section');first?.after(section);
  try {
    const response = await fetch(`application_requirements.php?id=${id}`,{credentials:'same-origin'}); const result = await response.json();if(!response.ok)throw new Error(result.error);
    if(!section.isConnected)return;
    const form = document.createElement('form');
    const controls = {};
    Object.entries(result.items).forEach(([key,item],index)=>{
      const row=document.createElement('div');row.className='requirement-review-row';
      const label=document.createElement('label');label.textContent=item.name;label.htmlFor=`requirement-status-${index}`;
      const select=document.createElement('select');select.id=label.htmlFor;select.disabled=!result.editable;
      const statuses=item.source==='tupad'?['Not Reviewed','Verified','Needs Resubmission']:['Not Reviewed','Missing','Received','Verified','Needs Resubmission','Not Applicable'];
      statuses.forEach(status=>{const option=document.createElement('option');option.value=option.textContent=status;option.selected=status===item.status;select.append(option);});
      const note=document.createElement('input');note.value=item.note;note.maxLength=500;note.placeholder='What is missing or needs correction?';note.setAttribute('aria-label',`Review note: ${item.name}`);note.disabled=!result.editable;
      row.append(label,select,note);form.append(row);controls[key]={select,note};
    });
    const count=Object.values(result.items).filter(item=>['Missing','Needs Resubmission'].includes(item.status)).length;
    feedback.textContent=Object.keys(result.items).length?`${count} requirement${count===1?'':'s'} need attention.`:'No requirements configured for this batch. Set the requirements in Programs first.';
    if(result.editable&&Object.keys(controls).length){
      const save=document.createElement('button');save.type='submit';save.className='btn-main';save.textContent='Save Checklist';form.append(save);
      form.addEventListener('submit',async event=>{
        event.preventDefault();const items={};
        for(const [key,{select,note}] of Object.entries(controls)){
          if(['Missing','Needs Resubmission','Not Applicable'].includes(select.value)&&!note.value.trim()){feedback.textContent='Add a reason for missing, resubmission, or not applicable documents.';note.focus();return;}
          items[key]={status:select.value,note:note.value};
        }
        save.disabled=true;feedback.textContent='Saving?';
        try{const r=await fetch(`application_requirements.php?id=${id}`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({items,csrf_token:result.csrf_token})});const saved=await r.json();if(!r.ok)throw new Error(saved.error);feedback.textContent='Checklist saved. No email sent.';
          const tableRow=[...document.querySelectorAll('tr[data-profile]')].find(row=>{try{return Number(JSON.parse(row.dataset.profile).id)===id||Number(JSON.parse(row.dataset.profile).beneficiary_id)===id;}catch{return false;}});
          if(tableRow){const data=JSON.parse(tableRow.dataset.profile);data.requirement_items=saved.items;tableRow.dataset.profile=JSON.stringify(data);window.renderRequirementSummary?.(tableRow,saved.items);}
        }catch(error){feedback.textContent=error.message;}finally{save.disabled=false;}
      });
    }
    section.append(form);
  }catch(error){feedback.textContent=error.message||'Requirements could not be loaded.';}
};
window.renderRequirementSummary=(row,items)=>{
 row.querySelector('.requirement-summary')?.remove();const cell=row.cells?.[4];if(!cell)return;
 const values=Object.values(items||{});const issues=values.filter(item=>['Missing','Needs Resubmission'].includes(item.status)).length;
 const unreviewed=values.filter(item=>item.status==='Not Reviewed').length;
 const summary=document.createElement('small');summary.className='requirement-summary';summary.textContent=issues?`${issues} requirement${issues===1?'':'s'} need attention`:unreviewed?`${unreviewed} not reviewed`:values.length?'Requirements reviewed':'Checklist not configured';cell.append(summary);
};
document.addEventListener('DOMContentLoaded',()=>{document.querySelectorAll('tr[data-profile]').forEach(row=>{try{window.renderRequirementSummary(row,JSON.parse(row.dataset.profile).requirement_items);}catch{}});});
