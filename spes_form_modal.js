(function(){
  let modal, frame, view, currentId, currentPdfUrl, currentPdfBytes, currentPdfName='SPES Form.pdf';
  const $s=v=>String(v??'').trim();
  function loadScript(src){return new Promise((resolve,reject)=>{if(window.PDFLib)return resolve();const s=document.createElement('script');s.src=src;s.onload=resolve;s.onerror=reject;document.head.appendChild(s);});}
  async function getBytes(url){const r=await fetch(url,{credentials:'same-origin',cache:'no-store'});if(!r.ok)throw new Error((await r.json().catch(()=>({}))).error||'The official PDF could not be loaded.');return new Uint8Array(await r.arrayBuffer());}
  function fit(page,font,text,x,top,maxWidth,size=7.5){
    text=$s(text);if(!text)return;
    while(size>5.5&&font.widthOfTextAtSize(text,size)>maxWidth)size-=.25;
    if(font.widthOfTextAtSize(text,size)>maxWidth){const suffix='...';while(text.length&&font.widthOfTextAtSize(text+suffix,size)>maxWidth)text=text.slice(0,-1);text+=suffix;}
    page.drawText(text,{x,y:841.95-top-size,font,size,color:PDFLib.rgb(.03,.03,.03)});
  }
  function center(page,font,text,left,right,baseline,size=9){
    text=$s(text);if(!text)return;
    const width=right-left;
    while(size>6&&font.widthOfTextAtSize(text,size)>width-4)size-=.25;
    if(font.widthOfTextAtSize(text,size)>width-4){const suffix='...';while(text.length&&font.widthOfTextAtSize(text+suffix,size)>width-4)text=text.slice(0,-1);text+=suffix;}
    const textWidth=font.widthOfTextAtSize(text,size);
    page.drawText(text,{x:left+(width-textWidth)/2,y:841.95-baseline,font,size,color:PDFLib.rgb(.03,.03,.03)});
  }
  function mark(page,on,x,top){
    if(!on)return;
    const ink=PDFLib.rgb(.02,.02,.02), yTop=841.95-top;
    page.drawLine({start:{x:x+2.05,y:yTop-6.1},end:{x:x+4.0,y:yTop-8.15},thickness:.65,color:ink});
    page.drawLine({start:{x:x+4.0,y:yTop-8.15},end:{x:x+7.25,y:yTop-3.4},thickness:.65,color:ink});
  }
  function historyMark(page,on,rowBaseline){
    if(!on)return;
    const ink=PDFLib.rgb(.02,.02,.02), boxLeft=36.2, boxTop=841.95-(rowBaseline-8.0);
    page.drawLine({start:{x:boxLeft+1.15,y:boxTop-2.0},end:{x:boxLeft+5.45,y:boxTop-7.15},thickness:.72,color:ink});
    page.drawLine({start:{x:boxLeft+5.45,y:boxTop-2.0},end:{x:boxLeft+1.15,y:boxTop-7.15},thickness:.72,color:ink});
  }
  function cleanHistory(value){value=$s(value);return /^(n\/?a|none|not applicable)$/i.test(value)?'':value;}
  function contains(value,needle){return $s(value).toLowerCase().includes(needle.toLowerCase());}
  async function buildPdf(id){
    await loadScript('vendor/pdf-lib/pdf-lib.min.js?v=1.17.1');
    const base='spes_form.php?id='+encodeURIComponent(id);
    const [data,b2,b2a]=await Promise.all([fetch(base+'&action=data',{credentials:'same-origin',cache:'no-store'}).then(async r=>{const j=await r.json();if(!r.ok)throw new Error(j.error||'SPES data could not be loaded.');return j;}),getBytes(base+'&action=template&form=2'),getBytes(base+'&action=template&form=2a')]);
    const out=await PDFLib.PDFDocument.create(), d2=await PDFLib.PDFDocument.load(b2), d2a=await PDFLib.PDFDocument.load(b2a);
    const [p2]=await out.copyPages(d2,[0]), [p2a]=await out.copyPages(d2a,[0]);out.addPage(p2);out.addPage(p2a);
    const font=await out.embedFont(PDFLib.StandardFonts.Helvetica), bold=await out.embedFont(PDFLib.StandardFonts.HelveticaBold);window.__spesFont=bold;
    const r=data.record||{}, h=data.history||[];
    // Redraw these two source-PDF headings as one centered block so replacing
    // the regional number cannot leave artifacts or clip the line below it.
    p2.drawRectangle({x:235,y:772,width:140,height:20,color:PDFLib.rgb(1,1,1)});
    center(p2,bold,'Regional Office No. 5',235,375,56,7);
    center(p2,bold,'PUBLIC EMPLOYMENT SERVICE OFFICE',235,375,66,7);
    center(p2,font,[$s(r.municipality)||'Vinzons',$s(r.district)||'Camarines Norte'].filter(Boolean).join(', '),247,366,73,7.5);
    center(p2,font,r.last_name,27,104,160,8);center(p2,font,r.first_name,104,185,160,8);center(p2,font,r.middle_name,185,283,160,8);center(p2,font,[$s(r.gsis_beneficiary_name)||$s(r.gsis_beneficiary),$s(r.gsis_relationship)].filter(Boolean).join(' / '),283,455,160,7.8);
    let dob=$s(r.birthdate);if(/^\d{4}-\d{2}-\d{2}$/.test(dob)){const a=dob.split('-');dob=a[1]+'/'+a[2]+'/'+a[0];}
    center(p2,font,dob,27,199,188,8);center(p2,font,r.place_of_birth,199,343,188,7.8);center(p2,font,r.citizenship||'Filipino',343,455,188,8);
    center(p2,font,r.contact_no,27,204,209,7.9);center(p2,font,r.email,204,455,209,7.8);center(p2,font,r.social_media,27,455,232,7.8);
    const civil=$s(r.civil_status), sex=$s(r.sex), type=$s(r.spes_type);mark(p2,contains(civil,'single'),32.4,247.2);mark(p2,contains(civil,'married'),71.9,247.2);mark(p2,contains(civil,'widow'),121.4,247.2);mark(p2,contains(civil,'separated'),176.9,247.2);mark(p2,sex==='Male',243.8,247.2);mark(p2,sex==='Female',281.3,247.2);mark(p2,contains(type,'student')&&!contains(type,'als'),335.3,235);mark(p2,contains(type,'als'),381.6,235);mark(p2,contains(type,'out-of-school')||contains(type,'osy'),344.3,247.5);
    const ps=$s(r.parents_status);[['living together',168.7],['solo parent|single parent',250.8],['separated',317.8],['person with disability',379],['senior citizen',487.8],['sugar plantation worker',72.2],['indigenous people',198],['displaced worker',299],['local',407.3],['ofw',469.7]].forEach((m,i)=>mark(p2,m[0].split('|').some(v=>contains(ps,v)),m[1],i<5?260.7:273.2));
    center(p2,font,data.address,27,567,306,7.9);center(p2,font,r.permanent_address||data.address,27,567,327,7.9);center(p2,font,[$s(r.father_name),$s(r.father_contact)].filter(Boolean).join(' / '),27,297,350,7.8);center(p2,font,[$s(r.mother_name),$s(r.mother_contact)].filter(Boolean).join(' / '),297,567,350,7.8);center(p2,font,r.father_occupation,27,297,370,7.8);center(p2,font,r.mother_occupation,297,567,370,7.8);
    const edu=[['elem',402],['sec',422],['tert',441],['tv',455]];edu.forEach(([k,b])=>{center(p2,font,r[k+'_school'],90,297,b,7.05);const course=$s(r[k==='tert'||k==='tv'?k+'_course':k+'_degree']);const level=$s(r[k+'_year_level']);center(p2,font,course,297,407,b,7.05);if(k==='sec')fit(p2,font,level,431,420,37,6);else center(p2,font,level,407,470,b,6.9);center(p2,font,r[k+'_date_attendance'],470,567,b,6.8);});
    center(p2,font,r.special_skills,27,567,621,7.8);[652,667,681,694].forEach((b,i)=>{const e=h[i]||{},establishment=cleanHistory(e.establishment),year=cleanHistory(e.year),spesId=cleanHistory(e.id),hasAvailment=Boolean(establishment||year||spesId);historyMark(p2,hasAvailment,b);center(p2,font,establishment,105,350,b,7.15);center(p2,font,year,344,403,b,7.3);center(p2,font,spesId,441,535,b,7.15);});
    center(p2,font,r.spes_other_info,27,567,716,7.2);
    const times=await out.embedFont(PDFLib.StandardFonts.TimesRoman), timesBold=await out.embedFont(PDFLib.StandardFonts.TimesRomanBold);
    center(p2a,times,'5',347,374,86,9);center(p2a,times,'Vinzons, Camarines Norte',224,384,114,8.5);
    center(p2a,timesBold,data.full_name,104,326,210,9.5);center(p2a,timesBold,data.age,334,369,210,9.5);center(p2a,timesBold,data.address,72,433,226,9);
    center(p2a,timesBold,data.today.day,165,210,550,9);center(p2a,timesBold,data.today.month,269,411,550,9);center(p2a,timesBold,$s(data.today.year).slice(-2),438,477,550,9);center(p2a,timesBold,r.municipality||'Vinzons',179,455,566,9);center(p2a,timesBold,data.full_name,324,483,610,9);
    currentPdfName=($s(data.full_name)||'Applicant').replace(/[<>:"/\\|?*\x00-\x1F]/g,'').replace(/\s+/g,' ').trim()+' SPES Form.pdf';
    out.setTitle('SPES Forms - '+data.full_name);out.setSubject('Official SPES Form 2 and Form 2-A');return await out.save();
  }
  function ensureModal(){if(modal)return;const css=document.createElement('link');css.rel='stylesheet';css.href='spes_form_modal.css?v=20260813d';document.head.appendChild(css);modal=document.createElement('div');modal.className='spes-form-modal';modal.setAttribute('aria-hidden','true');modal.innerHTML='<div class="spes-form-dialog" role="dialog" aria-modal="true" aria-label="SPES forms"><aside class="spes-form-tools"><div class="spes-form-heading"><span class="spes-form-icon">DOC</span><div><h2>SPES Forms</h2><p>Official beneficiary documents</p></div></div><div class="spes-form-badge"><span></span> Form 2 + Form 2-A <b>2 pages</b></div><div class="spes-action-label">Document actions</div><button class="spes-tool-btn primary" data-action="print"><span class="spes-btn-icon">&#128424;</span><span>Print document<small>Open the print dialog</small></span></button><button class="spes-tool-btn" data-action="pdf"><span class="spes-btn-icon">&#8681;</span><span>Download PDF<small>Save the completed official forms</small></span></button><div class="spes-form-note">Beneficiary details are ready for review, printing, or download.</div><button class="spes-tool-btn secondary spes-form-close" data-action="close"><span class="spes-btn-icon">&#10005;</span><span>Close preview</span></button></aside><main class="spes-form-view"><div class="spes-form-loading"><span class="spes-loader"></span><strong>Preparing official forms</strong><small>Positioning beneficiary information…</small></div><iframe class="spes-form-frame" title="Official filled SPES PDF"></iframe></main></div>';document.body.appendChild(modal);frame=modal.querySelector('iframe');view=modal.querySelector('.spes-form-view');frame.addEventListener('load',()=>view.classList.add('loaded'));modal.addEventListener('click',e=>{const a=e.target.closest('[data-action]')?.dataset.action;if(!a){if(e.target===modal)close();return;}if(a==='close')close();if(a==='print'){frame.contentWindow.focus();frame.contentWindow.print();}if(a==='pdf'&&currentPdfBytes){const blob=new Blob([currentPdfBytes],{type:'application/pdf'}),url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download=currentPdfName;link.click();setTimeout(()=>URL.revokeObjectURL(url),2000);}});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('is-open'))close();});}
  function close(){modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');document.body.style.overflow='';}
  window.openSpesForm=async function(id){
    const profileModal=document.getElementById('profileModal');
    if(profileModal){
      profileModal.classList.remove('show');
      profileModal.setAttribute('aria-hidden','true');
    }
    ensureModal();
    currentId=id;
    currentPdfBytes=null;
    view.classList.remove('loaded');
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow='hidden';
    try{
      currentPdfBytes=await buildPdf(id);
      if(currentPdfUrl)URL.revokeObjectURL(currentPdfUrl);
      currentPdfUrl=URL.createObjectURL(new Blob([currentPdfBytes],{type:'application/pdf'}));
      frame.src=currentPdfUrl;
    }catch(err){
      view.querySelector('.spes-form-loading').textContent=err.message||'The official forms could not be prepared.';
      console.error(err);
    }
  };
})();
