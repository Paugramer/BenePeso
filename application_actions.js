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
  if (data.approval === 'Approved' && progress && Number.isSafeInteger(id) && id > 0) {
    const program = String(data.program || '').toUpperCase();
    const label = program.includes('SPES') ? 'Work assignment' : program.includes('MSME') ? 'Night-market earnings' : 'Remarks';
    const details = document.createElement('details');
    details.className = 'application-optional-details';
    const summary = document.createElement('summary'); summary.textContent = 'Additional information (optional)';
    const link = document.createElement('a');
    link.href = `application_reporting.php?id=${id}&role=${role === 'admin' ? 'admin' : 'peso_staff'}`;
    link.textContent = `Edit ${label.toLowerCase()}`;
    details.append(summary, link); progress.appendChild(details);
  }
};
