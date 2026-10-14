(() => {
  const dialog=document.getElementById('documentPreviewModal'),frame=document.getElementById('documentPreviewFrame'),status=document.getElementById('documentPreviewMessage'),download=document.getElementById('documentPreviewDownload');let trigger=null,blobUrl=null,sequence=0;
  const start=(card)=>{trigger=card;sequence++;if(blobUrl){URL.revokeObjectURL(blobUrl);blobUrl=null;}frame.hidden=true;frame.removeAttribute('src');status.hidden=false;status.textContent='Loading preview?';download.hidden=true;document.getElementById('documentPreviewTitle').textContent=card.querySelector('h3').textContent;dialog.showModal();return sequence;};
  const display=(url,name)=>{download.href=url;download.download=name;download.hidden=false;frame.src=url;frame.hidden=false;status.hidden=true;};
  dialog.querySelectorAll('[data-close-document]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
  dialog.addEventListener('close',()=>{if(dialog.open)return;sequence++;frame.removeAttribute('src');if(blobUrl){URL.revokeObjectURL(blobUrl);blobUrl=null;}trigger?.focus();});
  document.querySelectorAll('.document-card').forEach(card=>{
    const activate=()=>{const guideline=card.querySelector('[data-guideline-program]');if(guideline){guideline.click();return;}start(card);const link=card.querySelector('a'),url=link.getAttribute('href'),name=decodeURIComponent(url.split('/').pop());download.href=url;download.download=name;download.hidden=false;const matchingPdf={'SPES_Form_2_Official.docx':'assets/forms/SPES%20Form%202%20-%20Application%20Form.pdf','SPES_Form_2A_Official.docx':'assets/forms/SPES%20Form%202-A%20-%20Oath%20of%20Undertaking.pdf'};frame.src=matchingPdf[name]||(/\.pdf$/i.test(name)?url:'document_preview.php?file='+encodeURIComponent(decodeURIComponent(url))); frame.hidden=false;status.hidden=true;};
    card.addEventListener('click',event=>{if(event.target.closest('[data-guideline-program]'))return;event.preventDefault();activate();});
    card.addEventListener('keydown',event=>{if(event.target===card&&(event.key==='Enter'||event.key===' ')){event.preventDefault();activate();}});
  });
  const message = document.getElementById('documentDownloadStatus');
  document.querySelectorAll('[data-guideline-program]').forEach(button => {
    button.addEventListener('click', async () => {
      const current=start(button.closest('.document-card'));
      button.disabled = true;
      message.textContent = 'Preparing guidelines…';
      try {
        const program = button.dataset.guidelineProgram;
        const response = await fetch(`program_guidelines.php?program=${encodeURIComponent(program)}`, { credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.items?.length) throw new Error(result.error || 'No accepted program batches have saved guidelines yet.');
        if (!window.PDFLib) throw new Error('The PDF library could not be loaded. Refresh and try again.');
        const { PDFDocument, StandardFonts, rgb } = window.PDFLib;
        const pdf = await PDFDocument.create();
        const font = await pdf.embedFont(StandardFonts.Helvetica);
        const bold = await pdf.embedFont(StandardFonts.HelveticaBold);
        const imageResponse = await fetch('assets/peso_official_report_header.png');
        if (!imageResponse.ok) throw new Error('The PESO letterhead could not be loaded.');
        const letterhead = await pdf.embedPng(await imageResponse.arrayBuffer());
        let page, y;
        const newPage = () => {
          page = pdf.addPage([595.28, 841.89]);
          page.drawImage(letterhead, { x: 32, y: 749, width: 531, height: 60.34 });
          y = 725;
        };
        // The built-in PDF font supports Windows-1252. Keep printable ASCII text.
        const plain = value => String(value || '').replace(/[–—]/g, '-').replace(/[‘’]/g, "'").replace(/[“”]/g, '"').replace(/₱/g, 'PHP ').replace(/[^\x20-\x7E\n]/g, ' ');
        const write = (value, heading = false) => {
          const face = heading ? bold : font;
          const size = heading ? 12 : 10;
          for (const paragraph of plain(value).split('\n')) {
            let line = '';
            for (const word of paragraph.split(/\s+/)) {
              // Split long unbroken strings so they cannot overflow the page.
              const parts = word.match(/.{1,60}/g) || [''];
              for (const part of parts) {
                const next = line ? `${line} ${part}` : part;
                if (face.widthOfTextAtSize(next, size) > 525 && line) {
                  if (y < 45) newPage();
                  page.drawText(line, { x: 35, y, size, font: face, color: rgb(.09, .22, .16) });
                  y -= 15; line = part;
                } else line = next;
              }
            }
            if (y < 45) newPage();
            page.drawText(line, { x: 35, y, size, font: face, color: rgb(.09, .22, .16) });
            y -= 17;
          }
          y -= 8;
        };
        for (const item of result.items) {
          newPage();
          write(`${program} - Program Guidelines & Requirements`, true);
          write(`Batch: ${item.program_code}\nSchedule: ${item.start_date || 'Not recorded'} to ${item.end_date || 'Not recorded'}\nVenue: ${item.venue || 'Not recorded'}`);
          for (const [label, key] of [['Program information', 'description'], ['Eligibility', 'eligibility'], ['Requirements', 'requirements']]) {
            write(label, true); write(item[key] || 'Not recorded. Contact PESO Vinzons for the current instructions.');
          }
          write('Based on the saved program batch information in BENEPESO. Contact PESO Vinzons for updates.');
        }
        const bytes = await pdf.save();
        if(current!==sequence)return;
        const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
        blobUrl=url;display(url,`${program}_Guidelines.pdf`);
        message.textContent = 'Guidelines ready to preview.';
      } catch (error) { if(current===sequence){status.textContent=error.message||'Unable to load preview.';message.textContent=status.textContent;} }
      finally { button.disabled = false; }
    });
  });
})();
