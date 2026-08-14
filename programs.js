function openProgramModal(p, status) {
    const modal = document.getElementById('infoModal');
    const content = document.getElementById('modalContent');
    
    // Determine Button Style based on status
    let btnHtml = '';
    if (status === 'Ongoing') {
        btnHtml = `<button class="btn-primary" style="background:#f39c12" onclick="showStatus('ongoing')">Check Status</button>`;
    } else if (status === 'Completed') {
        btnHtml = `<button class="btn-primary" style="background:#7f8c8d" onclick="showStatus('completed')">Program Completed</button>`;
    } else {
        btnHtml = `<button class="btn-primary" onclick="processApplication(${p.program_id})">Apply for Program</button>`;
    }

    content.innerHTML = `
        <div style="display:flex; gap:30px; flex-wrap:wrap;">
            <div style="flex:1; min-width:250px;">
                <img src="${p.image_path || 'img/default-prog.png'}" style="width:100%; border-radius:15px; margin-bottom:20px;">
                <div style="background:#f8faf9; padding:15px; border-radius:12px;">
                    <p style="font-size:0.8rem; margin-bottom:5px;"><b>Venue:</b> ${p.venue}</p>
                    <p style="font-size:0.8rem;"><b>Slots:</b> ${p.slots}</p>
                </div>
            </div>
            <div style="flex:1.5; min-width:250px;">
                <h2 style="color:#1a4d32; font-size:1.8rem; margin-bottom:15px;">${p.program_name}</h2>
                <div style="margin-bottom:20px;">
                    <h4 style="font-size:0.9rem; color:#1a4d32;">Description</h4>
                    <p style="font-size:0.9rem; color:#7f8c8d; line-height:1.6;">${p.description}</p>
                </div>
                <div style="margin-bottom:20px;">
                    <h4 style="font-size:0.9rem; color:#1a4d32;">Eligibility</h4>
                    <p style="font-size:0.9rem; color:#7f8c8d;">${p.eligibility}</p>
                </div>
                <div style="margin-bottom:20px;">
                    <h4 style="font-size:0.9rem; color:#1a4d32;">Requirements</h4>
                    <p style="font-size:0.9rem; color:#7f8c8d;">${p.requirements}</p>
                </div>
                ${btnHtml}
            </div>
        </div>
    `;

    modal.style.display = 'flex';
}

function processApplication(progId) {
    // We will build apply_process.php next. For now, it shows success.
    closeModal('infoModal');
    showStatus('success');
}

function showStatus(type) {
    const modal = document.getElementById('statusModal');
    const title = document.getElementById('statusTitle');
    const msg = document.getElementById('statusMsg');
    const icon = document.getElementById('statusIcon');

    if (type === 'success') {
        icon.innerHTML = '<div style="font-size:3rem;">✅</div>';
        title.innerText = "Registered Successfully!";
        msg.innerText = "Your application is now being reviewed by our staff. Check your dashboard for updates.";
    } else if (type === 'ongoing') {
        icon.innerHTML = '<div style="font-size:3rem;">⏳</div>';
        title.innerText = "Application Ongoing";
        msg.innerText = "You are currently enrolled in this program. You cannot apply again until it is completed.";
    } else if (type === 'completed') {
        icon.innerHTML = '<div style="font-size:3rem;">🏅</div>';
        title.innerText = "Already Availed";
        msg.innerText = "Records show you have already completed this program. Thank you for participating!";
    }

    modal.style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}