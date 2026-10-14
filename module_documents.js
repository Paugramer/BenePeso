(() => {
  const message = document.getElementById('documentDownloadStatus');
  document.querySelectorAll('[data-guideline-program]').forEach(button => {
    button.addEventListener('click', async () => {
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
        const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
        const link = document.createElement('a'); link.href = url; link.download = `${program}_Guidelines.pdf`; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
        message.textContent = 'Guidelines downloaded.';
      } catch (error) { message.textContent = error.message || 'The guidelines could not be downloaded. Try again.'; }
      finally { button.disabled = false; }
    });
  });
})();
