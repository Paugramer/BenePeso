<?php /* Original beneficiary profile restored from 969802a, with view-only footer. */ ?>
<div class="modal" id="profileModal" aria-hidden="true">
  <div class="modal-backdrop" data-close-profile></div>
  <div class="modal-dialog id-card-dialog" style="max-width: 900px; padding: 0; display: flex; overflow: hidden; background: #fff; border-radius: 16px;">
    <button type="button" class="profile-close-button" data-close-profile aria-label="Close beneficiary profile" style="position: absolute; top: 16px; right: 16px; background: #f9fafb; border: 1px solid #eaecf0; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #667085;"><i class="ph-bold ph-x"></i></button>
    
    <div class="id-card-left" style="width: 320px; background: linear-gradient(135deg, #e8f5e9 0%, #ffffff 100%); position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; border-right: 1px solid #eaecf0;">
       <div class="id-card-pattern" style="position: absolute; top:0; left:0; right:0; bottom:0; opacity: 0.1; background-image: radial-gradient(#1f7a54 1px, transparent 1px); background-size: 10px 10px; z-index: 1;"></div>
       <div class="profile-identity-row" style="z-index: 2; display: flex; flex-direction: column; align-items: center;">
           <div class="id-avatar-large" id="pm_avatar" style="width: 100px; height: 100px; background: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; font-weight: 800; color: #0d2618; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 24px;">?</div>
           
           <div class="profile-badges-row" style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
               <div class="id-badge badge-user-bg" id="pm_program_header" style="background: #1f7a54; color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;">PROGRAM NAME</div>
               <div id="pm_availment_badge"></div>
           </div>
       </div>
    </div>

    <div class="id-card-right" style="flex: 1; padding: 32px; background: #fff; position: relative;">
       <div class="id-card-right-inner">
           <div class="id-header" style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px; border-bottom: 2px solid #1f7a54; padding-bottom: 12px; width: fit-content; min-width: 250px;">
              <div style="width: 36px; height: 36px; background: #1f7a54; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff;"><i class="ph-fill ph-user" style="font-size: 18px;"></i></div>
              <div class="profile-name-stack"><span id="pm_spes_lifecycle" class="spes-lifecycle-ribbon" hidden></span><h3 class="id-name" id="pm_name" style="margin: 0; font-size: 20px; font-weight: 700; color: #0d2618;">Name</h3></div>
           </div>
           
           <div class="id-details-grid resume-details" id="pm_resume_content">
              
              <div class="info-card">
                 <div class="info-icon"><i class="ph-fill ph-phone-call"></i></div>
                 <div class="info-text">
                    <label>CONTACT NO.</label>
                    <div class="detail-value" id="pm_contact"></div>
                 </div>
              </div>

              <div class="info-card">
                 <div class="info-icon"><i class="ph-fill ph-map-pin"></i></div>
                 <div class="info-text">
                    <label>BARANGAY</label>
                    <div class="detail-value" id="pm_barangay"></div>
                 </div>
              </div>

              <div class="info-card">
                 <div class="info-icon"><i class="ph-fill ph-user"></i></div>
                 <div class="info-text">
                    <label>SEX & AGE</label>
                    <div class="detail-value" id="pm_sex_age"></div>
                 </div>
              </div>
              
              <div class="info-card" id="pm_business_container" style="grid-column: span 2; display:none;">
                 <div class="info-icon"><i class="ph-fill ph-briefcase"></i></div>
                 <div class="info-text">
                    <label>BUSINESS NAME</label>
                    <div class="detail-value text-success" id="pm_business_name"></div>
                 </div>
              </div>

              <div class="info-card" id="pm_nature_container">
                 <div class="info-icon"><i class="ph-fill ph-tag"></i></div>
                 <div class="info-text">
                    <label id="pm_dynamic_label">NATURE OF BUSINESS</label>
                    <div class="detail-value" id="pm_type_beneficiary"></div>
                 </div>
              </div>

              <div class="info-card" id="pm_date_availed_container">
                 <div class="info-icon"><i class="ph-fill ph-calendar-check"></i></div>
                 <div class="info-text">
                    <label>DATE AVAILED</label>
                    <div class="detail-value text-success" id="pm_date_availed_val"></div>
                 </div>
              </div>

              <div class="info-card" id="pm_utility_container" style="grid-column: 1 / -1; display:none;">
                 <div class="info-icon"><i class="ph-fill ph-lightning"></i></div>
                 <div class="info-text">
                    <label>UTILITY NEEDS</label>
                    <div class="detail-value" id="pm_utility"></div>
                 </div>
              </div>

              <div class="info-card" id="pm_assets_container" style="grid-column: 1 / -1; display:none;">
                 <div class="info-icon"><i class="ph-fill ph-bank"></i></div>
                 <div class="info-text">
                    <label>BUSINESS ASSETS OWNED</label>
                    <div class="detail-value" id="pm_assets"></div>
                 </div>
              </div>

           </div>
       </div>
       
       <div class="id-actions" style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eaecf0; padding-left: 32px; padding-right: 32px;">
          <div class="added-by" style="font-size: 11px; color: #667085;">ADDED BY: <span id="pm_added_by" style="color: #1f7a54; font-weight:800; text-transform:uppercase;"></span></div>
          <div id="pm_footer_actions" style="display:flex; gap:10px; align-items: center;"></div>
       </div>
    </div>
  </div>
</div>

<script>
(()=>{
  window.openProfileModal = function(rowElement) {
      try {
          const data = JSON.parse(rowElement.getAttribute('data-profile'));
          
          const safeSetText = (id, text) => {
              const el = document.getElementById(id);
              if (el) el.textContent = text || '—';
          };

          const profileAvatar = document.getElementById('pm_avatar');
          if (profileAvatar) {
              profileAvatar.textContent = data.initial || '?';
              if (data.profile_image) {
                  const image = document.createElement('img');
                  image.src = data.profile_image;
                  image.alt = `${data.name || 'Beneficiary'} profile photo`;
                  image.onload = () => { profileAvatar.textContent = ''; profileAvatar.appendChild(image); };
                  image.onerror = () => { profileAvatar.textContent = data.initial || '?'; };
              }
          }
          safeSetText('pm_name', data.name);
          safeSetText('pm_program_header', data.program); 
          const lifecycleRibbon = document.getElementById('pm_spes_lifecycle');
          if (lifecycleRibbon) {
              lifecycleRibbon.hidden = !data.spes_lifecycle_label;
              lifecycleRibbon.textContent = data.spes_lifecycle_label || '';
              lifecycleRibbon.className = `spes-lifecycle-ribbon ${data.spes_lifecycle === 'graduate' ? 'is-graduate' : ''}`;
          }
          safeSetText('pm_added_by', data.added_by); 

          safeSetText('pm_barangay', data.barangay);
          safeSetText('pm_contact', data.contact_no);
          safeSetText('pm_sex_age', `${data.sex || 'N/A'}, ${data.age || 'N/A'}`);
          safeSetText('pm_civil', data.civil_status || 'N/A');
          
          let dateAvailedStr = 'Not yet availed';
          if(data.date_availed && data.date_availed.trim() !== '') {
              let d = new Date(data.date_availed);
              if(!isNaN(d)) {
                  dateAvailedStr = d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
              }
          }
          safeSetText('pm_date_availed_val', dateAvailedStr);
          
          let typeText = "N/A";
          let labelText = "TYPE / CATEGORY";
          
          const busContainer = document.getElementById('pm_business_container');
          const stallContainer = document.getElementById('pm_stall_container');
          const dateContainer = document.getElementById('pm_date_container');
          const utilityContainer = document.getElementById('pm_utility_container');
          const assetsContainer = document.getElementById('pm_assets_container');
          
          if (data.program.toUpperCase().includes('TUPAD')) {
              if(data.type_of_beneficiary) typeText = data.type_of_beneficiary;
              labelText = "APPLICANT TYPE";
              
              if(busContainer) busContainer.style.display = 'none';
              if(stallContainer) stallContainer.style.display = 'none';
              if(dateContainer) dateContainer.style.display = 'none';
              if(utilityContainer) utilityContainer.style.display = 'none';
              if(assetsContainer) assetsContainer.style.display = 'none';
          }
          else if (data.program.toUpperCase().includes('SPES')) {
              if(data.spes_type) typeText = data.spes_type;
              labelText = "SPES CATEGORY";
              
              if(busContainer) busContainer.style.display = 'none';
              if(stallContainer) stallContainer.style.display = 'none';
              if(dateContainer) dateContainer.style.display = 'none';
              if(utilityContainer) utilityContainer.style.display = 'none';
              if(assetsContainer) assetsContainer.style.display = 'none';
          }
          else if (data.program.toUpperCase().includes('MSME')) {
              if(data.business_nature) typeText = data.business_nature;
              labelText = "NATURE OF BUSINESS";
              
              if(busContainer) { busContainer.style.display = 'block'; safeSetText('pm_business_name', data.business_name || 'N/A'); }
              if(stallContainer) stallContainer.style.display = 'none';
              if(dateContainer) dateContainer.style.display = 'none';
              if(utilityContainer) { utilityContainer.style.display = 'block'; safeSetText('pm_utility', data.utility_needs || '—'); }
              if(assetsContainer) { assetsContainer.style.display = 'block'; safeSetText('pm_assets', data.assets_owned || '—'); }
          }
          
          safeSetText('pm_dynamic_label', labelText);
          safeSetText('pm_type_beneficiary', typeText);

          renderBeneficiaryResume(data, labelText, typeText);
          
          function getAvail(s) {
              s = (s || '').toLowerCase().trim();
              if (s === "ongoing") return "pill success";
              if (s === "completed") return "pill neutral";
              if (s === "requirements received") return "pill warning"; 
              if (s === "not yet availed") return "pill warning";
              return "pill neutral";
          }

          const badgeContainer = document.getElementById('pm_availment_badge');
          if (badgeContainer) {
              let displayedAvailment = data.availment;
              if (data.approval === 'Pending') displayedAvailment = 'Pending Review';
              else if (data.approval === 'Rejected') displayedAvailment = 'Application Not Accepted';
              else if (data.availment === 'Not Yet Availed') displayedAvailment = 'Waiting for Requirements';
              else if (data.availment === 'Requirements Received') displayedAvailment = 'Documents Submitted';
              badgeContainer.innerHTML = `<span class="${getAvail(data.availment)}"><span class="pill-dot" style="background:currentColor;"></span> ${displayedAvailment}</span>`;
          }

          window.currentProfileData = data;

          const footerActions=document.getElementById('pm_footer_actions');footerActions.replaceChildren();const close=document.createElement('button');close.type='button';close.className='btn-light';close.textContent='Close View';close.addEventListener('click',()=>document.getElementById('profileModal').classList.remove('show'));footerActions.append(close);
          const pModal = document.getElementById('profileModal');
          if(pModal) pModal.classList.add('show');

      } catch (e) {
          console.error("Error opening profile modal: ", e);
      }
  };

  function renderBeneficiaryResume(data, categoryLabel, categoryValue) {
      const root = document.getElementById('pm_resume_content');
      if (!root) return;
      root.replaceChildren();

      const emptyValues = new Set(['', 'null', 'undefined', 'n/a', 'none']);
      const hasValue = value => {
          if (value === null || value === undefined) return false;
          const normalized = String(value).trim().toLowerCase();
          return !emptyValues.has(normalized);
      };
      const formatDate = value => {
          if (!hasValue(value)) return '';
          const parsed = new Date(String(value).replace(' ', 'T'));
          return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
      };
      const address = [data.street_purok_zone, data.barangay, data.municipality, data.district].filter(hasValue).join(', ');
      const programUpper = String(data.program || '').toUpperCase();
      const lastAvailmentRaw = data.last_availed_at || data.date_availed;
      const lastAvailmentYearMatch = hasValue(lastAvailmentRaw) ? String(lastAvailmentRaw).match(/\b(19|20)\d{2}\b/) : null;
      const lastAvailmentYear = lastAvailmentYearMatch ? lastAvailmentYearMatch[0] : '';
      const availmentSummary = lastAvailmentYear ? `${data.program} — ${lastAvailmentYear}` : '';
      const sections = [
          ['Documents Submitted', 'ph-files', [
              ['Last Recorded Submission', data.submitted_on], ['Requirements Review', data.review_status],
              ['Verified Requirements', data.checklist_summary], ['Resubmission Deadline', data.followup_deadline],
              ['Follow-up Instructions', data.submission_notes]
          ]],
          ['Personal Information', 'ph-user-circle', [
              ['Full Address', address], ['Contact Number', data.contact_no], ['Email Address', data.email || data.business_email],
              ['Birthdate', formatDate(data.birthdate)], ['Age', data.age], ['Sex', data.sex], ['Civil Status', data.civil_status],
              ['Citizenship', data.citizenship], ['Place of Birth', data.place_of_birth], ['ID Type', data.type_of_id], ['ID Number', data.id_number]
          ]],
          ['Program & Status', 'ph-calendar-check', [
              ['Program', data.program], ['Approval Status', data.approval === 'Approved' ? 'Accepted' : data.approval], ['Status', data.availment === 'Not Yet Availed' ? 'Waiting for Requirements' : data.availment],
              ['Previous / Last Participation', availmentSummary], ['Last Availed Year', lastAvailmentYear],
              ['Date Started / Availed', formatDate(data.date_availed)], ['Date Completed', formatDate(data.date_completed)], [categoryLabel, categoryValue]
          ]],
          ['Employment & Livelihood', 'ph-briefcase-metal', [
              ['Occupation', data.occupation], ['Monthly Income', data.avg_monthly_income],
              ['Interested in Employment', data.interested_in_employment],
              ['Skills / Training Needed', data.skills_training_needed || data.special_skills],
              ['Work Experience', data.work_experience]
          ]],
          ['Family & Dependents', 'ph-users-three', [
              ['Dependent', data.dependent_name], ['Relationship', data.dependent_relationship], ['Parent Status', data.parents_status],
              ["Father's Name", data.father_name], ["Father's Contact", data.father_contact], ["Father's Occupation", data.father_occupation],
              ["Mother's Name", data.mother_name], ["Mother's Contact", data.mother_contact], ["Mother's Occupation", data.mother_occupation]
          ]],
          ['Education & Experience', 'ph-graduation-cap', [
              ['Educational Attainment', data.educational_attainment],
              ['Elementary', [data.elem_school, data.elem_year_level, data.elem_date_attendance].filter(hasValue).join(' · ')],
              ['Secondary', [data.sec_school, data.sec_degree, data.sec_year_level, data.sec_date_attendance].filter(hasValue).join(' · ')],
              ['Tertiary', [data.tert_school, data.tert_course, data.tert_year_level, data.tert_date_attendance].filter(hasValue).join(' · ')],
              ['Technical-Vocational', [data.tv_school, data.tv_course, data.tv_year_level, data.tv_date_attendance].filter(hasValue).join(' · ')]
          ]],
          ['Past SPES Participation', 'ph-clock-counter-clockwise', [
              ['SPES Classification', data.spes_lifecycle_label],
              ['All Recorded Participation', data.spes_all_availments],
              ['1st Participation', [data.spes_history_1_year, data.spes_history_1_id && `ID: ${data.spes_history_1_id}`].filter(hasValue).join(' · ')],
              ['2nd Participation', [data.spes_history_2_year, data.spes_history_2_id && `ID: ${data.spes_history_2_id}`].filter(hasValue).join(' · ')],
              ['3rd Participation', [data.spes_history_3_year, data.spes_history_3_id && `ID: ${data.spes_history_3_id}`].filter(hasValue).join(' · ')],
              ['4th Participation', [data.spes_history_4_year, data.spes_history_4_id && `ID: ${data.spes_history_4_id}`].filter(hasValue).join(' · ')]
          ]],
          ['SPES Qualification Review', 'ph-shield-check', [
              ['Age Requirement', data.age ? ((Number(data.age) >= 18 && Number(data.age) <= 30) ? `Meets requirement (${data.age} years old)` : `Does not meet requirement (${data.age} years old; required 18–30)`) : 'For verification'],
              ['Reported Family Income', data.avg_monthly_income],
              ['Region V Income Reference', '2023 PSA threshold for a family of six: ₱16,787.50 monthly / ₱201,450.00 annually (calculated from ₱33,575 annual per capita).']
          ]],
          ['Regional Income Reference', 'ph-chart-line-up', [
              ['Reported Family Income', data.avg_monthly_income],
              ['Region V Reference', '2023 PSA threshold for a family of six: ₱16,787.50 monthly / ₱201,450.00 annually.'],
              ['Use', 'Reference only; apply it when the current program guidelines require an income qualification.']
          ]],
          ['Business Information', 'ph-briefcase', [
              ['Business Name', data.business_name], ['Nature of Business', data.business_nature], ['Ownership Type', data.ownership_type],
              ['Primary Products', data.primary_products], ['Year Started', data.year_started], ['Business Permit', data.business_permit_no],
              ['Permit Validity', formatDate(data.permit_validity)], ['DTI Number', data.dti_no], ['TIN', data.tin_no],
              ['Business Assets', data.business_assets || data.assets_owned], ['Utility Needs', data.utility_needs],
              ['Workers', data.hr_total], ['Business Size', data.business_size], ['Current Capital', data.current_capital],
              ['Daily Earnings', data.daily_earnings], ['Distribution Channels', data.distribution_channels]
          ]]
      ];

      sections.forEach(([title, icon, fields]) => {
          if (title === 'Business Information' && !programUpper.includes('MSME')) return;
          if (title === 'Past SPES Participation' && !programUpper.includes('SPES')) return;
          if (title === 'SPES Qualification Review' && !programUpper.includes('SPES')) return;
          if (title === 'Regional Income Reference' && !programUpper.includes('TUPAD')) return;
          const visible = fields.filter(([, value]) => hasValue(value));
          if (!visible.length) return;
          const section = document.createElement('section');
          section.className = 'resume-section';
          const heading = document.createElement('h4');
          heading.innerHTML = `<i class="ph-fill ${icon}"></i><span></span>`;
          heading.querySelector('span').textContent = title;
          section.appendChild(heading);
          const grid = document.createElement('div');
          grid.className = 'resume-field-grid';
          visible.forEach(([label, value]) => {
              const item = document.createElement('div');
              item.className = 'resume-field';
              const key = document.createElement('span');
              key.textContent = label;
              const content = document.createElement('strong');
              if (label === 'Dependent' && programUpper.includes('TUPAD')) {
                  content.className = 'dependent-profile-link';
                  content.setAttribute('role', 'button');
                  content.setAttribute('tabindex', '0');
                  content.textContent = String(value);
                  content.disabled=true;content.style.cursor='default';
                  content.onkeydown = event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); content.click(); } };
              } else {
                  content.textContent = String(value);
              }
              item.append(key, content);
              grid.appendChild(item);
          });
          section.appendChild(grid);
          root.appendChild(section);
      });
  }

document.querySelectorAll('[data-view-report]').forEach(button=>button.addEventListener('click',()=>window.openProfileModal(button.closest('tr'))));document.querySelectorAll('[data-close-profile]').forEach(button=>button.addEventListener('click',()=>document.getElementById('profileModal').classList.remove('show')));document.addEventListener('keydown',event=>{if(event.key==='Escape')document.getElementById('profileModal').classList.remove('show');});
})();
</script>
