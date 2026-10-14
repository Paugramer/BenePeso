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
  const stage = data.availment_status || data.availment;
  if (progress && data.approval === 'Approved') {
    const flowNote = document.createElement('p');flowNote.className='application-flow-note';
    flowNote.textContent = stage === 'Requirements Received' ? 'Documents Submitted. Requirements are under review.' : ['Orientation','Ongoing','Salary Distribution','Completed'].includes(stage) ? 'Orientation confirms that the requirements review is complete.' : stage === 'Not Yet Availed' ? 'Waiting for the required documents.' : '';
    if(flowNote.textContent)progress.append(flowNote);
  }
  const id = Number(data.id);
  if (Number.isSafeInteger(id) && id > 0 && data.approval === 'Approved' && (data.availment_status || data.availment) === 'Requirements Received') window.mountApplicationRequirements?.(modal, id);
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
  window.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : event.target.parentElement;
    const dialog = target?.closest('.application-extra-dialog');
    if (dialog && target.closest('.application-extra-header button')) {
      event.preventDefault(); event.stopImmediatePropagation(); dialog.close(); return;
    }
    if (target?.closest('[data-close-profile]')) {
      event.preventDefault(); event.stopImmediatePropagation(); parentState = null;
      profile()?.classList.remove('show');profile()?.setAttribute('aria-hidden','true');
      document.body.style.overflow = '';return;
    }
  }, true);
  const makeDialog = title => {
    const dialog = document.createElement('dialog');dialog.className = 'application-extra-dialog';
    const header = document.createElement('header');header.className = 'application-extra-header';
    const heading = document.createElement('h2');heading.textContent = title;
    const close = document.createElement('button');close.type = 'button';close.innerHTML = '<i class="ph-bold ph-x" aria-hidden="true"></i>';close.setAttribute('aria-label', 'Close and return');
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
  window.openRequirementsDialog = id => {
    rememberProfile();profile()?.classList.remove('show');profile()?.setAttribute('aria-hidden','true');
    const dialog=makeDialog('Review Requirements');const body=document.createElement('div');body.className='application-review-body';dialog.append(body);dialog.showModal();
    window.loadRequirementChecklist(body,id);
  };
  window.continueRequirementSchedule=(id,stage,dialog)=>{
    dialog.addEventListener('close',()=>setTimeout(()=>{rememberProfile();window.openQuickStatusModal?.(id,stage,'','');},0),{once:true});dialog.close();
  };
  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('openBulkResubmission')?.addEventListener('click', event => {
      const ids = [...document.querySelectorAll('.beneficiary-select:checked')].map(box => Number(box.value));
      if (!ids.length) return;
      const button=event.currentTarget;const role=button.dataset.role;const program=button.dataset.program;
      const dialog=makeDialog('Request Resubmission');const form=document.createElement('form');form.className='application-bulk-remarks-form';
      const description=document.createElement('p');description.textContent=`Request corrected documents from ${ids.length} selected beneficiaries. Each beneficiary will receive the reason, instructions, and deadline by email.`;
      const reasonLabel=document.createElement('label');reasonLabel.textContent='Resubmission Reason';reasonLabel.htmlFor='bulkResubmissionReason';
      const reason=document.createElement('select');reason.id=reasonLabel.htmlFor;reason.required=true;
      ['','Missing Requirement','Invalid Document','Other'].forEach(value=>{const option=document.createElement('option');option.value=value;option.textContent=value||'Select a reason';reason.append(option);});
      const label=document.createElement('label');label.textContent='Documents to Resubmit / Instructions';label.htmlFor='bulkResubmissionInstructions';
      const input=document.createElement('textarea');input.id=label.htmlFor;input.maxLength=2500;input.rows=4;input.required=true;input.placeholder='Example: Submit a clear copy of the front and back of your National ID.';
      const dueLabel=document.createElement('label');dueLabel.textContent='Resubmission Deadline';dueLabel.htmlFor='bulkResubmissionDeadline';
      const due=document.createElement('input');due.type='date';due.id=dueLabel.htmlFor;due.required=true;due.min=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Manila',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
      const status=document.createElement('p');status.setAttribute('role','status');
      const submit=document.createElement('button');submit.type='submit';submit.className='btn-main';submit.textContent='Save & Notify';
      const actions=document.createElement('div');actions.className='application-dialog-actions';actions.append(submit);form.append(description,reasonLabel,reason,label,input,dueLabel,due,status,actions);dialog.append(form);dialog.showModal();reason.focus();
      form.addEventListener('submit',async event=>{
        event.preventDefault();submit.disabled=true;status.textContent='Saving requests and sending emails...';
        try{
          const response=await fetch('application_bulk_resubmission.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({ids,reason:reason.value,instructions:input.value,deadline:due.value,role,program,csrf_token:document.querySelector('input[name="csrf_token"]')?.value})});
          const result=await response.json();if(!response.ok)throw new Error(result.error||'Resubmission requests could not be saved.');
          status.textContent=`Requests saved for ${result.count} beneficiaries. Emails sent: ${result.emails_sent}.`+(result.email_failures?` ${result.email_failures} emails could not be sent; contact those beneficiaries.`:'');submit.textContent='Saved';
          document.querySelectorAll('tr[data-profile]').forEach(row=>{const data=JSON.parse(row.dataset.profile);if(ids.includes(Number(data.id))){data.document_submission={requested_at:true,resubmission_reason:result.reason,resubmission_deadline:result.deadline,resubmission_note:result.instructions};row.dataset.profile=JSON.stringify(data);window.renderRequirementSummary(row,data.requirement_items);}});
        }catch(error){status.textContent=error.message;submit.disabled=false;}
      });
    });
  });
})();

window.mountApplicationRequirements = (modal,id) => {
  modal.querySelector('.application-review-button')?.remove();
  const progress=[...modal.querySelectorAll('.resume-section')].find(section=>section.querySelector('h4 span')?.textContent==='Program & Status');
  if(!progress)return;const button=document.createElement('button');button.type='button';button.className='btn-light application-review-button';button.textContent='Review Requirements';button.addEventListener('click',()=>window.openRequirementsDialog(id));
  (progress.querySelector('.application-section-actions') || progress).append(button);
};
window.loadRequirementChecklist = async (modal, id) => {
  modal.querySelector('.application-requirements')?.remove();
  const section = document.createElement('section'); section.className = 'application-requirements';

  const help = document.createElement('p');help.textContent = 'Documents Submitted means under review. Record any missing or incorrect documents here. Move to Orientation after the requirements are cleared.';
  const feedback = document.createElement('p');feedback.setAttribute('role','status');feedback.textContent='Loading requirements...';
  section.append(help,feedback);
  modal.append(section);
  try {
    const cached=window.currentProfileData;
    let result;
    const csrf=document.querySelector('input[name="csrf_token"]')?.value;
    if(Number(cached?.id)===id && cached.requirement_items && csrf){ result={items:cached.requirement_items,editable:cached.approval==='Approved' && (cached.availment_status || cached.availment)==='Requirements Received',csrf_token:csrf}; }
    else {const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),15000);try{const response=await fetch(`application_requirements.php?id=${id}`,{credentials:'same-origin',signal:controller.signal});result=await response.json();if(!response.ok)throw new Error(result.error);}finally{clearTimeout(timeout);}}
    if(!section.isConnected)return;
    const form = document.createElement('form');form.id=`applicationRequirementForm-${id}`;form.noValidate=true;form.setAttribute('data-no-action-loader','');
    const controls = {};
    Object.entries(result.items).forEach(([key,item],index)=>{
      const row=document.createElement('div');row.className='requirement-review-row';
      const label=document.createElement('label');label.textContent=item.name;label.htmlFor=`requirement-status-${index}`;
      const select=document.createElement('select');select.id=label.htmlFor;select.disabled=!result.editable;
      const statuses=['Not Reviewed','Missing','Received','Verified','Invalid Document'];
      if(item.status==='Not Applicable')statuses.push('Not Applicable');
      const currentStatus=item.status==='Needs Resubmission'?'Invalid Document':item.status;
      statuses.forEach(status=>{const option=document.createElement('option');option.value=status;option.textContent=status==='Not Applicable'?'Previously Exempted':status==='Missing'?'Missing Requirement':status;option.selected=status===currentStatus;select.append(option);});
      const note=document.createElement('input');note.value=item.note;note.maxLength=500;note.placeholder='What is missing or needs correction?';note.setAttribute('aria-label',`Review note: ${item.name}`);note.disabled=!result.editable;
      const updateNote=()=>{const needsNote=['Missing','Invalid Document'].includes(select.value);note.hidden=!needsNote;note.required=needsNote;note.disabled=!result.editable || !needsNote;};
      select.addEventListener('change',updateNote);updateNote();
      row.append(label,select,note);form.append(row);controls[key]={select,note};
    });
    const count=Object.values(result.items).filter(item=>['Missing','Needs Resubmission','Invalid Document'].includes(item.status)).length;
    feedback.textContent=Object.keys(result.items).length?count ? `${count} requirement${count===1?'':'s'} need attention.` : Object.values(result.items).some(item=>['Not Reviewed','Received'].includes(item.status)) ? 'Review is not yet complete.' : 'Requirements reviewed.':'No requirements configured for this batch. Set the requirements in Programs first.';
    if(result.editable&&Object.keys(controls).length){
      const save=document.createElement('button');save.type='button';save.addEventListener('click',()=>form.requestSubmit());save.className='btn-main';save.textContent='Save Checklist';save.setAttribute('form',form.id);const actions=document.createElement('div');actions.className='application-dialog-actions application-review-footer';feedback.className='application-checklist-feedback';actions.append(feedback,save);
      const dialog=modal.closest('dialog');if(dialog)dialog.append(actions);else form.append(actions);
      const ready=()=>Object.values(controls).length>0 && Object.values(controls).every(({select})=>['Verified','Not Applicable'].includes(select.value));
      const updateContinue=()=>{save.textContent=ready()?'Save & Continue':'Save Checklist';};Object.values(controls).forEach(({select})=>select.addEventListener('change',updateContinue));updateContinue();
      form.addEventListener('submit',async event=>{
        event.preventDefault();if(save.disabled)return;const items={};
        for(const [key,{select,note}] of Object.entries(controls)){
          if(['Missing','Invalid Document'].includes(select.value)&&!note.value.trim()){feedback.textContent='Specify what is missing or why the document is invalid.';note.focus();return;}
          items[key]={status:select.value,note:note.hidden?(select.value==='Not Applicable'?note.value:''):note.value};
        }
        save.disabled=true;feedback.textContent='Saving...';
        try{const r=await fetch(`application_requirements.php?id=${id}`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({items,csrf_token:result.csrf_token})});let saved;try{saved=await r.json();}catch{throw new Error('The server did not return a valid save response. Please try again.');}if(!r.ok)throw new Error(saved.error || 'Checklist could not be saved.');feedback.textContent=saved.next_step?'Requirements verified. Continue to schedule '+saved.next_step+'.':'Checklist saved. No email sent.';
          const tableRow=[...document.querySelectorAll('tr[data-profile]')].find(row=>{try{return Number(JSON.parse(row.dataset.profile).id)===id||Number(JSON.parse(row.dataset.profile).beneficiary_id)===id;}catch{return false;}});
          if(tableRow){const data=JSON.parse(tableRow.dataset.profile);data.requirement_items=saved.items;if(Number(window.currentProfileData?.id)===id)window.currentProfileData.requirement_items=saved.items;tableRow.dataset.profile=JSON.stringify(data);window.renderRequirementSummary?.(tableRow,saved.items);}
          if(saved.next_step && ready() && dialog)window.continueRequirementSchedule(id,saved.next_step,dialog);
        }catch(error){feedback.textContent=error.message;}finally{save.disabled=false;}
      });
    }
    section.append(form);
  }catch(error){feedback.textContent=error.message||'Requirements could not be loaded.';}
};
window.renderRequirementSummary=(row,items)=>{
 row.querySelector('.requirement-summary')?.remove();const cell=row.cells?.[4];if(!cell)return;
 const data=JSON.parse(row.dataset.profile);if(data.approval!=='Approved'||(data.availment_status || data.availment) !== 'Requirements Received')return;
 const request=data.document_submission;
 if(request?.requested_at){const summary=document.createElement('small');summary.className='requirement-summary';summary.textContent=`Needs Resubmission ? ${['Incomplete Document','Unreadable Document','Incorrect Information','Expired Document'].includes(request.resubmission_reason)?'Invalid Document':(request.resubmission_reason || 'See instructions')}${request.resubmission_deadline ? ' ? Due '+request.resubmission_deadline : ''}`;summary.title=request.resubmission_note || '';cell.append(summary);return;}
 const issues=Object.values(items||{}).filter(item=>['Missing','Needs Resubmission','Invalid Document'].includes(item.status)).length;
 if(!issues)return;
 const summary=document.createElement('small');summary.className='requirement-summary';summary.textContent=`${issues} requirement${issues===1?'':'s'} need attention`;cell.append(summary);
};
document.addEventListener('DOMContentLoaded',()=>{document.querySelectorAll('tr[data-profile]').forEach(row=>{try{window.renderRequirementSummary(row,JSON.parse(row.dataset.profile).requirement_items);}catch{}});});
