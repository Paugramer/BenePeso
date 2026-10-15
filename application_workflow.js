(() => {
 const init=()=>{
  const today=()=>{const parts=new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Manila',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());const value=type=>parts.find(p=>p.type===type).value;return `${value('year')}-${value('month')}-${value('day')}`;};
  const label=s=>({'Not Yet Availed':'Waiting for Requirements','Requirements Received':'Documents Submitted','Exam Failed':'Exam Not Passed'})[s]||s;
  const current=d=>d.availment_status||d.availment||'Not Yet Availed';
  const flow=d=>{const program=String(d.program||window.currentProgramName||'').toUpperCase(),returning=Number(d.spes_completed_count||0)>0;if(program.includes('SPES'))return returning?['Not Yet Availed','Requirements Received','Orientation','Ongoing','Completed']:['Not Yet Availed','Requirements Received','Examination','Exam Passed','Orientation','Ongoing','Completed'];return program.includes('TUPAD')?['Not Yet Availed','Requirements Received','Orientation','Ongoing','Salary Distribution','Completed']:['Not Yet Availed','Requirements Received','Orientation','Ongoing','Completed'];};
  const legal=d=>{const stage=current(d);if(['Completed','Not Qualified','Cancelled','Exam Failed'].includes(stage)||d.approval!=='Approved')return [];const steps=flow(d),index=steps.indexOf(stage),next=steps[index+1];const values=next?[next]:[];if(steps.indexOf('Examination')===-1&&['Examination','Exam Passed'].includes(stage))values.push('Orientation');if(stage==='Examination'&&steps.includes('Examination'))values.push('Exam Failed');return [...new Set([...values,'Not Qualified','Cancelled'])];};
  const scopeData=id=>{for(const row of document.querySelectorAll('[data-profile]')){try{const data=JSON.parse(row.dataset.profile);if(Number(data.id)===Number(id))return data;}catch{}}return Number(window.currentProfileData?.id)===Number(id)?window.currentProfileData:null;};
  const configure=(select,data,edit)=>{if(!select||!data)return;const stage=current(data),values=legal(data),previous=select.value;select.replaceChildren();if(edit){const option=new Option(label(stage)+' (Current)',stage);select.append(option);}else if(!values.length){select.append(new Option(label(stage)+' (Current)',stage));}else if(stage==='Requirements Received'){select.append(new Option('Documents Submitted - request resubmission',stage));}
   values.forEach(s=>select.append(new Option(label(s),s)));select.value=edit?stage:(values.includes(previous)?previous:values[0]||stage);select.dataset.workflowCurrent=stage;select.dataset.workflowDate=data.date_availed||'';select.dispatchEvent(new Event('change',{bubbles:true}));};
  const dateDefault=select=>{const quick=select.id==='quick_availment_status',field=document.getElementById(quick?'quick_date_availed':'date_availed');if(!field)return;field.readOnly=false;if(select.value!=='Requirements Received')return;field.value=!quick&&select.dataset.workflowCurrent==='Requirements Received'&&select.dataset.workflowDate?select.dataset.workflowDate:today();field.readOnly=true;field.required=true;field.disabled=false;const group=quick?document.getElementById('quick_date_1_container'):field.closest('.form-group');const wrapper=quick?document.getElementById('quick_date_fields_wrapper'):field.closest('.date-fields-wrapper');if(group)group.style.display='block';if(wrapper)wrapper.style.display='block';if(quick&&group)group.parentElement.style.gridTemplateColumns='minmax(0, 1fr)';const labelNode=document.getElementById(quick?'quick_date_1_label':'date_1_label');if(labelNode)labelNode.textContent='Documents Submitted Date';};
  for(const id of ['availment_status_input','quick_availment_status']){const select=document.getElementById(id);select?.addEventListener('change',()=>dateDefault(select));if(id==='availment_status_input'&&select){const initialOptions=select.innerHTML;select.closest('form')?.addEventListener('reset',()=>{select.innerHTML=initialOptions;delete select.dataset.workflowCurrent;delete select.dataset.workflowDate;const field=document.getElementById('date_availed');if(field)field.readOnly=false;});}}
  if(window.openEditModal){const original=window.openEditModal;window.openEditModal=function(data){const result=original.apply(this,arguments);if(typeof data==='string'){try{data=JSON.parse(data);}catch{return result;}}configure(document.getElementById('availment_status_input'),data,true);return result;};}
  if(window.openQuickStatusModal){const original=window.openQuickStatusModal;window.openQuickStatusModal=function(id){const result=original.apply(this,arguments);configure(document.getElementById('quick_availment_status'),scopeData(id),false);return result;};}
  const input=document.getElementById('bulkFileInput'),error=document.getElementById('bulkFileError'),form=input?.closest('form'),submit=form?.querySelector('button[type="submit"]');
  let fileState='empty',validationSequence=0,validationController=null;
  const showFileState=state=>{
   fileState=state;const invalid=state==='invalid',checking=state==='checking';
   if(error){error.hidden=!invalid&&!checking;error.textContent=checking?'Checking file...':invalid?'Invalid file or format. Must follow the template.':'';error.classList.toggle('is-checking',checking);}
   input?.setCustomValidity(invalid?'Import Invalid':checking?'Please wait while the file is checked.':'');
   input?.setAttribute('aria-invalid',String(invalid));input?.closest('label')?.classList.toggle('has-import-error',invalid);
   if(submit)submit.disabled=invalid||checking;
  };
  const validateFile=async()=>{
   const sequence=++validationSequence;validationController?.abort();
   const file=input?.files?.[0];
   if(!file){showFileState('empty');return;}
   if(!/\.(xlsx|csv)$/i.test(file.name)){showFileState('invalid');return;}
   showFileState('checking');validationController=new AbortController();
   const data=new FormData();data.append('csv_file',file);data.append('program_id',form.querySelector('[name="program_id"]')?.value||'');data.append('program_name',form.querySelector('[name="program_name"]')?.value||'');data.append('csrf_token',form.querySelector('[name="csrf_token"]')?.value||'');
   try{
    const response=await fetch('validate_import_template.php',{method:'POST',body:data,credentials:'same-origin',signal:validationController.signal});
    const result=await response.json();if(sequence!==validationSequence)return;
    showFileState(response.ok&&result.valid===true?'valid':'invalid');
   }catch(error){if(sequence===validationSequence&&error.name!=='AbortError')showFileState('invalid');}
  };
  input?.addEventListener('change',validateFile);form?.querySelector('[name="program_id"]')?.addEventListener('change',validateFile);
  form?.addEventListener('submit',event=>{if(input?.files?.length&&fileState!=='valid'){event.preventDefault();event.stopImmediatePropagation();if(fileState!=='checking')validateFile();}},true);
  form?.addEventListener('reset',()=>{++validationSequence;validationController?.abort();showFileState('empty');});
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
