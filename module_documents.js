(() => {
  const dialog=document.getElementById('documentPreviewModal'),frame=document.getElementById('documentPreviewFrame'),status=document.getElementById('documentPreviewMessage'),download=document.getElementById('documentPreviewDownload');let trigger=null,blobUrl=null,sequence=0;
  const start=(card)=>{trigger=card;sequence++;if(blobUrl){URL.revokeObjectURL(blobUrl);blobUrl=null;}frame.hidden=true;frame.removeAttribute('src');status.hidden=false;status.textContent='Loading preview...';download.hidden=true;document.getElementById('documentPreviewTitle').textContent=card.querySelector('h3').textContent;dialog.showModal();return sequence;};
  const display=(url,name)=>{download.href=url;download.download=name.replace(/_Blank(?=\.)/i,'');download.hidden=false;frame.src=url;frame.hidden=false;status.hidden=true;};
  dialog.querySelectorAll('[data-close-document]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
  dialog.addEventListener('close',()=>{if(dialog.open)return;sequence++;frame.removeAttribute('src');if(blobUrl){URL.revokeObjectURL(blobUrl);blobUrl=null;}trigger?.focus();});
  document.querySelectorAll('.document-card').forEach(card=>{
    const activate=()=>{start(card);const link=card.querySelector('a'),url=link.getAttribute('href'),name=decodeURIComponent(url.split('/').pop());download.href=url;download.download=name.replace(/_Blank(?=\.)/i,'');download.hidden=false;const matchingPdf={'SPES_Form_2_Official.docx':'assets/forms/SPES%20Form%202%20-%20Application%20Form.pdf','SPES_Form_2A_Official.docx':'assets/forms/SPES%20Form%202-A%20-%20Oath%20of%20Undertaking.pdf'};frame.src=matchingPdf[name]||(/\.pdf$/i.test(name)?url:'document_preview.php?file='+encodeURIComponent(decodeURIComponent(url))); frame.hidden=false;status.hidden=true;};
    card.addEventListener('click',event=>{event.preventDefault();activate();});
    card.addEventListener('keydown',event=>{if(event.target===card&&(event.key==='Enter'||event.key===' ')){event.preventDefault();activate();}});
  });
})();
