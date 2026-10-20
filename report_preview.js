(() => {
 function initialize(){
  const modal=document.getElementById('generateReportModal'),open=document.getElementById('openReportModal');if(!modal||!open)return;
  const filterToggle=modal.querySelector('.report-filter-toggle');if(filterToggle)modal.querySelector('.report-view-toolbar').prepend(filterToggle);
  const viewport=modal.querySelector('.scrollable-table-wrap'),form=modal.querySelector('.report-form'),message=document.createElement('p');
  message.className='report-preview-message';message.setAttribute('role','status');viewport.before(message);
  const sheetBar=document.createElement('div');sheetBar.className='report-sheet-tabs';modal.querySelector('.report-column-pagination').before(sheetBar);
  const worksheetHeader=modal.querySelector('.preview-header');
  const columns=[...modal.querySelectorAll('.report-column-list input')];let sheets=[],sheetIndex=0,columnPage=0,zoom=1,count=0,requestNumber=0,controller,loaded=false;
  const field=id=>document.getElementById(id);
  const filters=()=>modal.dataset.submissionReport?new URLSearchParams({format:'preview',role:form.elements.role.value,program_name:form.elements.program_name.value,program_id:field('report_batch_select')?.value||'0',barangay:field('report_brgy_select')?.value||'All',review_status:field('report_avail_select')?.value||'All',search:form.elements.search.value}):new URLSearchParams({template_preview:'1',program_name:form.elements.program_name.value,program_id:field('report_batch_select')?.value||'0',report_barangay:field('report_brgy_select')?.value||'All',report_outcome:field('report_avail_select')?.value||'All',report_spes_group:field('report_spes_group_select')?.value||'all',business_nature:field('report_nature_select')?.value||'All'});
  function draw(){
   const sheet=sheets[sheetIndex];if(!sheet)return;worksheetHeader.remove();viewport.innerHTML=sheet.html;const worksheet=document.createElement('div');worksheet.className='report-worksheet';worksheet.append(worksheetHeader,...viewport.childNodes);viewport.append(worksheet);
   const selected=new Set(columns.filter(input=>input.checked).map(input=>input.value));
   const indices=sheet.column_keys.map((key,index)=>({key,index})).filter(item=>item.key!=='_number'&&selected.has(item.key)).map(item=>item.index);
   const fitTogether=indices.length<=8;
   const perPage=fitTogether?Math.max(1,indices.length):Math.max(1,Math.min(8,Math.floor((viewport.clientWidth||innerWidth)/140)-1)),total=Math.max(1,Math.ceil(indices.length/perPage));columnPage=Math.min(columnPage,total-1);
   worksheet.style.minWidth=fitTogether && (viewport.clientWidth||innerWidth)<960?'960px':'';
   const visible=new Set([0,...indices.slice(columnPage*perPage,(columnPage+1)*perPage)]);
   viewport.querySelectorAll('col').forEach(col=>{if(!visible.has(Number(col.dataset.templateColumn)))col.remove();});
   viewport.querySelectorAll('[data-column-start]').forEach(cell=>{const start=Number(cell.dataset.columnStart),end=Number(cell.dataset.columnEnd);let width=0;for(let i=start;i<=end;i++)if(visible.has(i))width++;cell.hidden=!width;if(width)cell.colSpan=width;});
   // Empty-state rows have no workbook coordinates: they must span this page,
   // otherwise the browser allocates all original columns and compresses headers.
   viewport.querySelectorAll('tbody td[colspan]:not([data-column-start])').forEach(cell=>cell.colSpan=visible.size);
   viewport.querySelector('.report-worksheet').style.zoom=String(zoom);
   modal.querySelector('.report-column-page-status').textContent=indices.length?`Columns ${columnPage*perPage+1}–${Math.min(indices.length,(columnPage+1)*perPage)} of ${indices.length}`:'No preview columns selected';
   modal.querySelector('[data-report-column-page="previous"]').disabled=columnPage===0;modal.querySelector('[data-report-column-page="next"]').disabled=columnPage>=total-1;
   modal.querySelector('.report-column-count').textContent=selected.size+' selected';
   sheetBar.replaceChildren();if(sheets.length>1)sheets.forEach((item,index)=>{const button=document.createElement('button');button.type='button';button.textContent=item.name;button.className=index===sheetIndex?'active':'';button.onclick=()=>{sheetIndex=index;columnPage=0;draw();};sheetBar.append(button);});
  }
  async function refresh(){
   const sequence=++requestNumber;controller?.abort();controller=new AbortController();loaded=false;message.hidden=false;message.textContent='Preparing report preview…';field('report_print_button').disabled=true;
   const selectedBarangay=field('report_brgy_select');field('preview_subtitle_top').textContent=String(form.elements.program_name.value).toUpperCase().includes('TUPAD')?'Municipality of Vinzons':(selectedBarangay?.value==='All'?'Municipality of Vinzons':`Barangay ${selectedBarangay?.value}, Vinzons`);
   try{const response=await fetch((modal.dataset.submissionReport?'requirements_report.php?':'beneficiary_report_data.php?')+filters(),{credentials:'same-origin',signal:controller.signal});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Unable to prepare report. Please try again.');if(sequence!==requestNumber)return;sheets=data.sheets;count=data.count;sheetIndex=Math.min(sheetIndex,sheets.length-1);columnPage=0;loaded=true;message.textContent='';message.hidden=true;draw();field('report_print_button').disabled=false;}
   catch(error){if(error.name==='AbortError')return;message.hidden=false;message.textContent=error.message;viewport.replaceChildren(worksheetHeader);}
  }
  open.addEventListener('click',()=>{modal.classList.add('show');modal.setAttribute('aria-hidden','false');refresh();});
  modal.querySelectorAll('[data-close-report]').forEach(button=>button.addEventListener('click',()=>{modal.classList.remove('show','filters-open');modal.setAttribute('aria-hidden','true');controller?.abort();open.focus();}));
  modal.querySelector('.report-filter-toggle')?.addEventListener('click',event=>{const visible=modal.classList.toggle('filters-open');event.currentTarget.setAttribute('aria-expanded',String(visible));});
  modal.querySelectorAll('.report-filter-list select').forEach(select=>select.addEventListener('change',refresh));
  columns.forEach(input=>input.addEventListener('change',()=>{columnPage=0;draw();}));
  modal.querySelectorAll('[data-report-columns]').forEach(button=>button.addEventListener('click',()=>{
   const common=new Set(['first_name','middle_name','last_name','ext_name','birthdate','street_purok_zone','barangay','report_province']);
   // Keep each source template's column order, including combined fields where used.
   if(!columns.some(input=>input.value==='first_name'))common.add('owner_name');
   if(!columns.some(input=>input.value==='street_purok_zone'))common.add('complete_address');
   columns.forEach(input=>input.checked=button.dataset.reportColumns==='all'||common.has(input.value));
   columnPage=0;draw();
  }));
  modal.querySelectorAll('[data-report-column-page]').forEach(button=>button.addEventListener('click',()=>{columnPage+=button.dataset.reportColumnPage==='next'?1:-1;draw();}));
  modal.querySelectorAll('[data-report-zoom]').forEach(button=>button.addEventListener('click',()=>{zoom=button.dataset.reportZoom==='fit'?1:Math.min(1.5,Math.max(.65,Math.round((zoom+(button.dataset.reportZoom==='in'?.1:-.1))*10)/10));modal.querySelector('.report-zoom-value').value=Math.round(zoom*100)+'%';draw();}));
  field('report_print_button').addEventListener('click',()=>{
   if(!loaded)return;if(!count){showBeneficiaryNotice('No Records Found','No records match the selected report filters.');return;}
   const selected=new Set(columns.filter(input=>input.checked).map(input=>input.value));
   if(!selected.size){showBeneficiaryNotice('No Columns Selected','Select at least one field before printing.');return;}
   const popup=window.open('','_blank','width=1400,height=900');if(!popup){showBeneficiaryNotice('Print Window Blocked','Please allow pop-ups, then try printing again.');return;}
   popup.document.write('<!doctype html><html><head><title>Beneficiary Report</title><style>@page{size:A3 landscape;margin:8mm}*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;color:#163b2a}section{break-after:page}section:last-child{break-after:auto}h1{font-size:18pt;text-align:center;margin:0 0 6px}p{text-align:center;font-size:10pt}.report-template-metadata{display:grid;grid-template-columns:1fr 1fr;gap:5px 16px;font-size:9pt}.report-template-metadata>div{display:flex;gap:8px}.report-template-metadata dd{margin:0}table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:1px solid #333;padding:5px 3px;font-size:8pt;line-height:1.4;white-space:pre-line;overflow-wrap:anywhere;vertical-align:middle}th{font-size:9pt;min-height:60px}thead{display:table-header-group}tr{break-inside:avoid}</style></head><body></body></html>');popup.document.close();
   sheets.forEach(sheet=>{
    const visible=new Set([0,...sheet.column_keys.map((key,index)=>selected.has(key)?index:-1).filter(index=>index>0)]);
    if(visible.size===1)return;
    const section=popup.document.createElement('section'),title=popup.document.createElement('h1'),subtitle=popup.document.createElement('p');
    title.textContent=currentReportTitle;subtitle.textContent=field('preview_subtitle_top').textContent+(sheets.length>1?' — '+sheet.name:'');section.append(title,subtitle);
    const table=popup.document.createElement('div');table.innerHTML=sheet.html;
    table.querySelectorAll('col').forEach(col=>{if(!visible.has(Number(col.dataset.templateColumn)))col.remove();});
    table.querySelectorAll('[data-column-start]').forEach(cell=>{
     let span=0;for(let i=Number(cell.dataset.columnStart);i<=Number(cell.dataset.columnEnd);i++)if(visible.has(i))span++;
     if(!span)cell.remove();else cell.colSpan=span;
    });
    section.style.setProperty('--report-font',visible.size>22?'5.5pt':visible.size>15?'6.5pt':'9pt');
    section.append(table);popup.document.body.append(section);
   });
   // Keep the complete original worksheet together; paginate only down the rows.
   const printStyle=popup.document.createElement('style');
   printStyle.textContent='@page{size:A4 landscape;margin:8mm}body{color:#111}section{margin:0 auto 24px;max-width:281mm}h1{font-size:11pt;line-height:1.25}p{font-size:8pt;margin:5px 0 10px}.report-template-metadata{font-size:7pt;margin-bottom:8px}table{width:100%;table-layout:fixed}th,td{font-size:var(--report-font);line-height:1.25;padding:3px 2px;overflow-wrap:anywhere;word-break:normal;print-color-adjust:exact;-webkit-print-color-adjust:exact}th{font-size:var(--report-font)}col:first-child{width:6mm}thead{display:table-header-group}tr{break-inside:avoid}@media print{section{margin:0;max-width:none;break-after:page}section:last-child{break-after:auto}}';
   popup.document.head.append(printStyle);
   popup.focus();setTimeout(()=>popup.print(),150);
  });
  form.addEventListener('submit',event=>{
   if(!loaded||!count){event.preventDefault();showBeneficiaryNotice('No Records Found','No records match the selected report filters.');return;}
   if(!columns.some(input=>input.checked)){event.preventDefault();showBeneficiaryNotice('No Columns Selected','Select at least one field before exporting.');}
  });
  window.addEventListener('resize',draw);
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialize);else initialize();
})();
