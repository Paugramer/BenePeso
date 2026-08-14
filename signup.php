<?php
session_start();

$flash = $_SESSION["flash"] ?? "";
unset($_SESSION["flash"]);

$form_data = $_SESSION["form_data"] ?? [];
unset($_SESSION["form_data"]);

function get_val($field) {
    global $form_data;
    return htmlspecialchars($form_data[$field] ?? '');
}

function get_sel($field, $value) {
    global $form_data;
    return (isset($form_data[$field]) && $form_data[$field] === $value) ? 'selected' : '';
}

$barangays = [
  "Aguit-It",
  "Banocboc",
  "Cagbalogo",
  "Calangcawan Norte",
  "Calangcawan Sur",
  "Guinacutan",
  "Mangcayo",
  "Mangcawayan",
  "Manlucugan",
  "Matango",
  "Napilihan",
  "Pinagtigasan",
  "Barangay I (Pob.)",
  "Barangay II (Pob.)",
  "Barangay III (Pob.)",
  "Sabang",
  "Santo Domingo",
  "Singi",
  "Sula"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="img/pesologo.png">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BENEPESO | Sign Up</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="style.css?v=16" />
  <link rel="stylesheet" href="frontend_polish.css?v=1">
  <script src="frontend_polish.js?v=1" defer></script>
</head>
<body class="auth-page auth-signup">

<div class="box">
  <div class="card compact">

    <div class="left">
      <div class="brand">
        <div class="logo stagger-1">
          <img src="img/pesologo.png" alt="PESO Logo" onerror="this.style.display='none'">
        </div>

        <h1 class="stagger-2">BENEPESO</h1>

        <p class="stagger-3">
          Beneficiary Profiling, Eligibility, and Verification System for PESO Programs
        </p>

        <div class="badges stagger-4">
          <span class="badge">Fast Sign Up</span>
          <span class="badge">Verified Access</span>
          <span class="badge">PESO Services</span>
        </div>
      </div>
    </div>

    <div class="right">
      <div class="right-inner">

        <div class="role-label stagger-1" style="margin-top: 15px;">User Registration</div>

        <h2 class="stagger-2">Create User Account</h2>
        
        <div class="step-tracker stagger-2">
            <div class="step-item active" id="tracker1">
                <div class="step-circle">1</div>
                <span class="step-text">Personal</span>
            </div>
            <div class="step-connector"></div>
            <div class="step-item" id="tracker2">
                <div class="step-circle">2</div>
                <span class="step-text">Security</span>
            </div>
            <div class="step-connector"></div>
            <div class="step-item" id="tracker3">
                <div class="step-circle">3</div>
                <span class="step-text">Profile</span>
            </div>
        </div>

        <form action="process_signup.php" method="POST" enctype="multipart/form-data" autocomplete="off" id="signupForm" class="stagger-3" novalidate>
          <input type="hidden" name="role" value="user" id="roleInput">
          <input type="hidden" name="municipality" value="Vinzons" id="municipalityHidden">

          <div class="form-step active" id="step1">
              
              <div class="form-row">
                <div class="form-group">
                  <label for="firstName">First Name</label>
                  <input type="text" id="firstName" name="first_name" placeholder="e.g. Juan" value="<?php echo get_val('first_name'); ?>" required>
                </div>
                <div class="form-group">
                  <label for="middleName">Middle Name</label>
                  <input type="text" id="middleName" name="middle_name" placeholder="e.g. Santos or N/A" value="<?php echo get_val('middle_name'); ?>" required>
                </div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label for="lastName">Last Name</label>
                  <input type="text" id="lastName" name="last_name" placeholder="e.g. Dela Cruz" value="<?php echo get_val('last_name'); ?>" required>
                </div>
                <div class="form-group">
                  <label for="extName">Extension Name</label>
                  <input type="text" id="extName" name="ext_name" placeholder="e.g. Jr., or N/A" value="<?php echo get_val('ext_name'); ?>">
                </div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label for="birthDate">Birthdate</label>
                  <input type="date" id="birthDate" name="birthdate" value="<?php echo get_val('birthdate'); ?>" required onchange="calculateAge()">
                </div>
                <div class="form-group">
                  <label for="ageInput">Age</label>
                  <input type="number" id="ageInput" name="age" readonly placeholder="Auto" required>
                </div>
              </div>

              <div class="form-row">
                  <div class="form-group">
                    <label for="sexSelect">Sex</label>
                    <select name="sex" id="sexSelect" required>
                      <option value="" disabled <?php echo empty(get_val('sex')) ? 'selected' : ''; ?>>Select sex</option>
                      <option value="Male" <?php echo get_sel('sex', 'Male'); ?>>Male</option>
                      <option value="Female" <?php echo get_sel('sex', 'Female'); ?>>Female</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label for="civilStatus">Civil Status</label>
                    <select name="civil_status" id="civilStatus" required>
                      <option value="" disabled <?php echo empty(get_val('civil_status')) ? 'selected' : ''; ?>>Select status</option>
                      <option value="Single" <?php echo get_sel('civil_status', 'Single'); ?>>Single</option>
                      <option value="Married" <?php echo get_sel('civil_status', 'Married'); ?>>Married</option>
                      <option value="Widowed" <?php echo get_sel('civil_status', 'Widowed'); ?>>Widowed</option>
                      <option value="Legally Separated" <?php echo get_sel('civil_status', 'Legally Separated'); ?>>Legally Separated</option>
                    </select>
                  </div>
              </div>

              <button type="button" class="btn" id="nextBtn1" name="next_btn_1" onclick="goToStep(2)">Next: Address & Security</button>
          </div>

          <div class="form-step" id="step2">
              <div class="form-row">
                  <div class="form-group">
                    <label for="contactInput">Contact Number</label>
                    <input type="text" id="contactInput" name="contact_no" placeholder="09XXXXXXXXX" maxlength="11" value="<?php echo get_val('contact_no'); ?>" required>
                  </div>
                  <div class="form-group">
                    <label for="purokInput">Purok / Street / Zone</label>
                    <input type="text" id="purokInput" name="street_purok_zone" placeholder="e.g. Purok 1" value="<?php echo get_val('street_purok_zone'); ?>" required>
                  </div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label for="barangayInput">Barangay</label>
                  <select name="barangay" id="barangayInput" required>
                    <option value="" disabled <?php echo empty(get_val('barangay')) ? 'selected' : ''; ?>>Select barangay</option>
                    <?php foreach ($barangays as $barangay): ?>
                      <option value="<?php echo htmlspecialchars($barangay); ?>" <?php echo get_sel('barangay', $barangay); ?>>
                        <?php echo htmlspecialchars($barangay); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label for="municipalityDisplay">Municipality</label>
                  <input type="text" id="municipalityDisplay" name="municipality_display" value="Vinzons" readonly required>
                </div>
              </div>

              <div class="form-group full-width">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="juan.delacruz@email.com" value="<?php echo get_val('email'); ?>" required>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label for="passwordInput">Password</label>
                  <div class="password-wrap">
                    <input type="password" name="password" id="passwordInput" placeholder="Create password" required>
                    <button type="button" class="toggle-pass" id="togglePass1" name="toggle_pass_1" data-target="passwordInput" aria-label="Show password">
                      <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 13.5 2.5 12S6.5 5 12 5Zm0 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8Z"/></svg>
                    </button>
                  </div>
                </div>

                <div class="form-group">
                  <label for="confirmPasswordInput">Confirm Password</label>
                  <div class="password-wrap">
                    <input type="password" name="confirm_password" id="confirmPasswordInput" placeholder="Retype password" required>
                    <button type="button" class="toggle-pass" id="togglePass2" name="toggle_pass_2" data-target="confirmPasswordInput" aria-label="Show password">
                      <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 13.5 2.5 12S6.5 5 12 5Zm0 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8Z"/></svg>
                    </button>
                  </div>
                </div>
              </div>

              <div class="btn-group">
                  <button type="button" class="btn btn-secondary" id="backBtn2" name="back_btn_2" onclick="goToStep(1)">Back</button>
                  <button type="button" class="btn" id="nextBtn2" name="next_btn_2" onclick="goToStep(3)">Next: Profile</button>
              </div>
          </div>

          <div class="form-step" id="step3">
              <div style="text-align: center; color: var(--muted); font-size: 13px; margin-bottom: 10px;">
                  Add a photo so the PESO office can verify your identity.
              </div>

              <div class="profile-upload-container" id="profileUploadContainer">
                <div class="profile-preview-box">
                  <svg class="placeholder-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                      <circle cx="12" cy="13" r="4"></circle>
                  </svg>
                  <img id="previewImg" src="#" alt="Profile" style="display:none;">
                </div>
                <label for="profile_pic" class="upload-label">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px; vertical-align:middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                    Upload Photo
                </label>
                <input type="file" name="profile_pic" id="profile_pic" accept="image/*" autocomplete="off" style="display:none;" required>
              </div>

              <label class="privacy-acknowledgment" for="privacyAcknowledgment">
                <input type="checkbox" id="privacyAcknowledgment" name="privacy_acknowledgment" value="1" required>
                <span>I have read and understood the <a href="privacy_notice.php" target="_blank" rel="noopener">BenePeso Privacy Notice</a>, including how PESO Vinzons processes my personal data to create and manage my beneficiary account.</span>
              </label>

              <div class="btn-group">
                  <button type="button" class="btn btn-secondary" id="backBtn3" name="back_btn_3" onclick="goToStep(2)">Back</button>
                  <button class="btn" type="submit" id="signupBtn" name="submit_registration">Complete Registration</button>
              </div>
          </div>

        </form>

        <p class="small stagger-4">
          Already have an account?
          <a href="login.php" id="loginLink">Log in here</a>
        </p>

      </div>
    </div>

  </div>
</div>

<div class="modal-bg" id="modalBg">
  <div class="modal modal--notice" role="dialog" aria-modal="true" aria-labelledby="signupNoticeTitle">
    <button class="modal-close-btn" type="button" id="closeNoticeBtn" name="close_notice_btn" onclick="closeModal('modalBg')" aria-label="Close notice">&times;</button>
    <div class="modal-icon-header" style="color: #f39c12; background: #fef5e7;">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
    </div>
    <h3 class="modal-title" id="signupNoticeTitle">BENEPESO Notice</h3>
    <p style="color:var(--muted); font-size:14px; margin-bottom:25px;" id="modalText"></p>
    <button class="modal-btn" type="button" id="okNoticeBtn" name="ok_notice_btn" onclick="closeModal('modalBg')">Close</button>
  </div>
</div>

<div class="modal-bg" id="loadingBg">
  <div class="modal modal--loading" role="status" aria-live="polite">
    <div class="spinner" style="margin: 0 auto 20px;"></div>
    <h3 style="color:var(--green-dark); font-size:20px; font-weight:800; margin-bottom:8px;" id="loadingTitle">Please wait</h3>
    <p style="color:var(--muted); font-size:14px; font-weight:500;" id="loadingMsg">Processing...</p>
    <small style="color:#9ab0a3; display:block; margin-top:8px;">This may take a few seconds.</small>
  </div>
</div>

<script>
  let currentStep = 1;

  window.addEventListener('DOMContentLoaded', () => {
      const bDate = document.getElementById('birthDate').value;
      if(bDate) {
          calculateAge();
      }
  });

  function calculateAge() {
      const birthDateVal = document.getElementById('birthDate').value;
      if (!birthDateVal) return;
      const birthDate = new Date(birthDateVal);
      const today = new Date();
      let age = today.getFullYear() - birthDate.getFullYear();
      const m = today.getMonth() - birthDate.getMonth();
      if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
          age--;
      }
      document.getElementById('ageInput').value = Math.max(0, age); 
  }

  function goToStep(step) {
      if (step > currentStep) {
          const currentStepEl = document.getElementById('step' + currentStep);
          const requiredInputs = currentStepEl.querySelectorAll('[required]');
          let isValid = true;

          requiredInputs.forEach(input => {
              if (!input.value.trim()) {
                  isValid = false;
                  input.style.borderColor = "#c0392b"; 
                  input.style.boxShadow = "0 0 0 4px rgba(192,57,43,0.1)";
              } else {
                  input.style.borderColor = ""; 
                  input.style.boxShadow = "";
              }
          });

          if (!isValid) {
              document.getElementById("modalText").textContent = "Please fill in all required fields before proceeding.";
              openModal("modalBg");
              return;
          }

          if (currentStep === 2) {
              const pass = document.getElementById('passwordInput').value;
              const conf = document.getElementById('confirmPasswordInput').value;
              const contact = document.getElementById('contactInput').value;
              
              if (contact.length !== 11 || !contact.startsWith("09")) {
                  document.getElementById("modalText").textContent = "Contact number must be 11 digits and start with 09.";
                  openModal("modalBg");
                  return;
              }
              if (pass !== conf) {
                  document.getElementById("modalText").textContent = "Passwords do not match.";
                  openModal("modalBg");
                  return;
              }
              if (pass.length < 8) {
                  document.getElementById("modalText").textContent = "Password must be at least 8 characters.";
                  openModal("modalBg");
                  return;
              }
          }
      }

      const currentEl = document.getElementById('step' + currentStep);
      const nextEl = document.getElementById('step' + step);

      const outAnim = step > currentStep ? 'slideFadeOutLeft' : 'slideFadeOutRight';
      const inAnim = step > currentStep ? 'slideFadeInRight' : 'slideFadeInLeft';

      currentEl.style.animation = `${outAnim} 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards`;

      setTimeout(() => {
          currentEl.classList.remove('active');
          currentEl.style.animation = "";
          
          nextEl.classList.add('active');
          nextEl.style.animation = `${inAnim} 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards`;
      }, 250); 

      for (let i = 1; i <= 3; i++) {
          const tracker = document.getElementById('tracker' + i);
          if (i < step) {
              tracker.classList.remove('active');
              tracker.classList.add('completed');
          } else if (i === step) {
              tracker.classList.add('active');
              tracker.classList.remove('completed');
          } else {
              tracker.classList.remove('active', 'completed');
          }
      }

      currentStep = step;
  }

  const pass1 = document.getElementById('passwordInput');
  const pass2 = document.getElementById('confirmPasswordInput');

  function checkPasswordMatch() {
      if(pass2.value.length === 0) {
          pass2.style.borderColor = "";
          pass2.style.boxShadow = "";
          return;
      }
      if(pass1.value === pass2.value && pass1.value.length >= 8) {
          pass2.style.borderColor = "var(--green)"; 
          pass2.style.boxShadow = "0 0 0 4px rgba(31, 122, 84, 0.1)";
      } else {
          pass2.style.borderColor = "#c0392b"; 
          pass2.style.boxShadow = "0 0 0 4px rgba(192, 57, 43, 0.1)";
      }
  }

  pass1.addEventListener('input', checkPasswordMatch);
  pass2.addEventListener('input', checkPasswordMatch);

  const flash = <?php echo json_encode($flash); ?>;
  function openModal(id){
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.style.display = "flex";
    document.body.classList.add("modal-open");
  }
  function closeModal(id){
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.style.display = "none";
    const hasOpenModal = Array.from(document.querySelectorAll('.modal-bg')).some(item => item.style.display === 'flex');
    if (!hasOpenModal) document.body.classList.remove("modal-open");
  }

  if (flash){
    document.getElementById("modalText").textContent = flash;
    openModal("modalBg");
  }

  document.getElementById("modalBg").addEventListener("mousedown", (event) => {
    if (event.target === event.currentTarget) closeModal("modalBg");
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && document.getElementById("modalBg").style.display === "flex") {
      closeModal("modalBg");
    }
  });

  function showLoading(title, msg){
    document.getElementById("loadingTitle").textContent = title;
    document.getElementById("loadingMsg").textContent = msg;
    openModal("loadingBg");
  }

  const profileInput = document.getElementById('profile_pic');
  const previewImg = document.getElementById('previewImg');
  const placeholderIcon = document.querySelector('.placeholder-icon');
  const uploadContainer = document.getElementById('profileUploadContainer');

  profileInput.addEventListener('change', function() {
    const file = this.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = function(e) { 
          previewImg.setAttribute('src', e.target.result); 
          previewImg.style.display = 'block';
          if(placeholderIcon) placeholderIcon.style.display = 'none';
          
          uploadContainer.style.borderColor = "var(--green)";
          uploadContainer.style.background = "#f0f5f2";
      }
      reader.readAsDataURL(file);
    }
  });

  const contactInput = document.getElementById("contactInput");
  if (contactInput){
    contactInput.addEventListener("input", () => {
      contactInput.value = contactInput.value.replace(/\D/g, "").slice(0, 11);
    });
  }

  document.querySelectorAll(".toggle-pass").forEach(btn => {
    btn.addEventListener("click", () => {
      const target = document.getElementById(btn.dataset.target);
      target.type = target.type === "password" ? "text" : "password";
      btn.style.color = target.type === "text" ? "var(--green)" : "#9ab0a3";
    });
  });

  document.getElementById("signupForm").addEventListener("submit", (e) => {
    if (!profileInput.value) {
        e.preventDefault();
        uploadContainer.style.borderColor = "#c0392b";
        uploadContainer.style.background = "#fdf2f0";
        document.getElementById("modalText").textContent = "Please upload a profile photo to complete registration.";
        openModal("modalBg");
        return;
    }

    const password = document.getElementById("passwordInput").value;
    const confirmPassword = document.getElementById("confirmPasswordInput").value;
    if (password !== confirmPassword){
      e.preventDefault();
      document.getElementById("modalText").textContent = "Passwords do not match. Please retype carefully.";
      openModal("modalBg");
      return;
    }
    
    showLoading("Creating account", "Uploading photo and saving details securely...");
  });
</script>

</body>
</html>
