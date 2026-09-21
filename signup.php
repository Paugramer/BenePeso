<?php
require_once __DIR__ . '/auth_session.php';
require_once __DIR__ . '/beneficiary_choices.php';
require_once __DIR__ . '/google_auth_helper.php';

$flash = $_SESSION["flash"] ?? "";
unset($_SESSION["flash"]);
$show_age_notice = !empty($_SESSION['show_age_notice']);
unset($_SESSION['show_age_notice']);
$google_signup_success = !empty($_SESSION['google_signup_success']) && ($_GET['google'] ?? '') === 'success';
unset($_SESSION['google_signup_success']);

$form_data = $_SESSION["form_data"] ?? [];
unset($_SESSION["form_data"]);

$google_identity = google_auth_pending_identity();
$google_registration = $google_identity !== null;
if ($google_registration) {
    $form_data['email'] = $google_identity['email'];
    if (empty($form_data['first_name'])) $form_data['first_name'] = $google_identity['given_name'] ?? '';
    if (empty($form_data['last_name'])) $form_data['last_name'] = $google_identity['family_name'] ?? '';
}

function get_val($field) {
    global $form_data;
    return htmlspecialchars($form_data[$field] ?? '');
}

function get_sel($field, $value) {
    global $form_data;
    return (isset($form_data[$field]) && $form_data[$field] === $value) ? 'selected' : '';
}

$barangays = beneficiary_barangay_options();
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

  <link rel="stylesheet" href="style.css?v=32" />
  <link rel="stylesheet" href="frontend_polish.css?v=20260921">
  <link rel="stylesheet" href="beneficiary_responsive.css?v=9">
  <link rel="stylesheet" href="auth_refresh.css?v=12">
  <link rel="stylesheet" href="beneficiary_mobile.css?v=3">
<script src="frontend_polish.js?v=20260921" defer></script>
  <?php if (!$google_registration): ?>
    <script src="https://accounts.google.com/gsi/client" async defer onload="window.dispatchEvent(new Event('google-library-ready'))"></script>
  <script src="google_signin.js?v=6" defer></script>
  <?php endif; ?>
</head>
<body class="auth-page auth-signup" data-disable-page-loader>

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

        <div class="auth-benefits stagger-4" aria-label="Registration benefits">
          <span><i aria-hidden="true">&#10003;</i>Guided account setup</span>
          <span><i aria-hidden="true">&#10003;</i>Protected beneficiary records</span>
        </div>

      </div>
    </div>

    <div class="right">
      <div class="right-inner">

        <div class="auth-context-bar stagger-1">
          <a class="auth-return" href="index.php" aria-label="Return to the BENEPESO public portal">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14.5 6-6 6 6 6"/></svg>
            <span>Public portal</span>
          </a>
          <div class="role-label"><?= $google_registration ? 'Google-secured registration' : 'User Registration' ?></div>
        </div>

        <h2 class="stagger-2"><?= $google_registration ? 'Complete Your Profile' : 'Create User Account' ?></h2>
        <p class="sub signup-sub stagger-2"><?= $google_registration ? 'Add the remaining details for your BENEPESO account.' : 'One account for PESO programs and beneficiary services.' ?></p>

        <?php if ($google_registration): ?>
          <div class="google-verified-banner stagger-2" role="status">
            <span class="google-verified-mark" aria-hidden="true">&#10003;</span>
            <span><strong>Google account verified</strong><small><?= htmlspecialchars($google_identity['email'], ENT_QUOTES, 'UTF-8') ?></small></span>
          </div>
        <?php endif; ?>

        <div class="signup-progress-copy stagger-2" aria-live="polite">
          <strong id="signupStepLabel">Step 1 of 3</strong>
          <span id="signupStepHelp">Personal information</span>
        </div>
        <div class="step-tracker stagger-2">
            <div class="step-item active" id="tracker1" aria-current="step">
                <div class="step-circle">1</div>
                <span class="step-text">Personal</span>
            </div>
            <div class="step-connector"></div>
            <div class="step-item" id="tracker2">
                <div class="step-circle">2</div>
                <span class="step-text"><?= $google_registration ? 'Contact' : 'Security' ?></span>
            </div>
            <div class="step-connector"></div>
            <div class="step-item" id="tracker3">
                <div class="step-circle">3</div>
                <span class="step-text">Profile</span>
            </div>
        </div>

        <form action="process_signup.php" method="POST" enctype="multipart/form-data" autocomplete="off" id="signupForm" class="stagger-3" novalidate
          data-google-registration="<?= $google_registration ? '1' : '0' ?>"
          data-google-signup-success="<?= $google_signup_success ? '1' : '0' ?>"
          data-draft-key="<?= $google_registration ? 'benepeso_google_signup_draft_' . substr(hash('sha256', mb_strtolower((string)$google_identity['email'])), 0, 24) : '' ?>">
          <?= auth_csrf_input() ?>
          <input type="hidden" name="role" value="user" id="roleInput">
          <input type="hidden" name="municipality" value="Vinzons" id="municipalityHidden">
          <div class="auth-form-alert" id="signupFormAlert" role="alert" aria-live="assertive" hidden></div>

          <div class="form-step active" id="step1">
              
              <div class="form-row">
                <div class="form-group">
                  <label for="firstName">First Name</label>
                  <input type="text" id="firstName" name="first_name" maxlength="50" autocomplete="given-name" placeholder="e.g. Juan" value="<?php echo get_val('first_name'); ?>" required>
                </div>
                <div class="form-group">
                  <label for="middleName">Middle Name (Optional)</label>
                  <input type="text" id="middleName" name="middle_name" maxlength="50" placeholder="e.g. Santos (leave blank if none)" value="<?php echo get_val('middle_name'); ?>" autocomplete="additional-name">
                </div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label for="lastName">Last Name</label>
                  <input type="text" id="lastName" name="last_name" maxlength="50" autocomplete="family-name" placeholder="e.g. Dela Cruz" value="<?php echo get_val('last_name'); ?>" required>
                </div>
                <div class="form-group">
                  <label for="extName">Extension Name (Optional)</label>
                  <input type="text" id="extName" name="ext_name" maxlength="10" placeholder="e.g. Jr. (leave blank if none)" value="<?php echo get_val('ext_name'); ?>">
                </div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label for="birthDate">Date of Birth</label>
                  <input type="date" id="birthDate" name="birthdate" max="<?php echo date('Y-m-d'); ?>" value="<?php echo get_val('birthdate'); ?>" required onchange="calculateAge()">
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
                      <option value="Legally Separated" <?php echo get_sel('civil_status', 'Legally Separated'); ?>>Separated</option>
                    </select>
                  </div>
              </div>

              <button type="button" class="btn" id="nextBtn1" name="next_btn_1" onclick="goToStep(2)">Next: Address & Security</button>
          </div>

          <div class="form-step" id="step2">
              <div class="form-row">
                  <div class="form-group">
                    <label for="contactInput">Contact Number</label>
                    <input type="text" id="contactInput" name="contact_no" placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric" autocomplete="tel" value="<?php echo get_val('contact_no'); ?>" required>
                  </div>
                  <div class="form-group">
                    <label for="purokInput">Purok / Street / Zone</label>
                    <input type="text" id="purokInput" name="street_purok_zone" placeholder="e.g. Purok 1" autocomplete="street-address" value="<?php echo get_val('street_purok_zone'); ?>" required>
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
                <input type="email" id="email" name="email" placeholder="juan.delacruz@email.com" autocomplete="email" value="<?php echo get_val('email'); ?>" required <?= $google_registration ? 'readonly aria-readonly="true"' : '' ?>>
              </div>

              <?php if ($google_registration): ?>
                <div class="google-security-note">
                  Google will secure your sign-in. You can add a BENEPESO password later through password recovery if needed.
                </div>
              <?php else: ?>
              <div class="form-row">
                <div class="form-group">
                  <label for="passwordInput">Password</label>
                  <div class="password-wrap">
                    <input type="password" name="password" id="passwordInput" placeholder="Create password" autocomplete="new-password" minlength="10" aria-describedby="passwordHint" required>
                    <button type="button" class="toggle-pass" id="togglePass1" name="toggle_pass_1" data-target="passwordInput" aria-label="Show password">
                      <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 13.5 2.5 12S6.5 5 12 5Zm0 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8Z"/></svg>
                    </button>
                  </div>
                  <small class="field-hint" id="passwordHint">Use at least 10 characters with uppercase, lowercase, a number, and a symbol.</small>
                </div>

                <div class="form-group">
                  <label for="confirmPasswordInput">Confirm Password</label>
                  <div class="password-wrap">
                    <input type="password" name="confirm_password" id="confirmPasswordInput" placeholder="Retype password" autocomplete="new-password" minlength="10" required>
                    <button type="button" class="toggle-pass" id="togglePass2" name="toggle_pass_2" data-target="confirmPasswordInput" aria-label="Show password">
                      <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 13.5 2.5 12S6.5 5 12 5Zm0 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8Z"/></svg>
                    </button>
                  </div>
                </div>
              </div>
              <div class="password-strength" id="passwordStrength" aria-live="polite">
                <div class="password-strength-head"><span>Stronger password</span><strong id="passwordStrengthLabel">Start typing</strong></div>
                <div class="password-strength-track" aria-hidden="true"><span id="passwordStrengthBar"></span></div>
                <div class="password-checks">
                  <span data-password-check="length">10 or more characters</span>
                  <span data-password-check="case">Upper and lowercase letters</span>
                  <span data-password-check="number">At least one number</span>
                  <span data-password-check="symbol">At least one symbol</span>
                </div>
              </div>
              <?php endif; ?>

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
                <input type="file" name="profile_pic" id="profile_pic" class="visually-hidden-file" accept="image/*" required>
              </div>

              <label class="privacy-acknowledgment" for="privacyAcknowledgment">
                <input type="checkbox" id="privacyAcknowledgment" name="privacy_acknowledgment" value="1" required>
                <span>I have read and understood the <button type="button" class="privacy-notice-link" onclick="openPrivacyNotice()">Privacy Notice</button>, including how PESO Vinzons processes my personal data to create and manage my beneficiary account.</span>
              </label>

              <div class="btn-group">
                  <button type="button" class="btn btn-secondary" id="backBtn3" name="back_btn_3" onclick="goToStep(2)">Back</button>
                  <button class="btn" type="submit" id="signupBtn" name="submit_registration">Complete Registration</button>
              </div>
          </div>

        </form>

        <?php if (!$google_registration): ?>
          <div class="auth-divider auth-divider--signup stagger-4"><span>or sign up with Google</span></div>
          <div
            class="google-signin google-signin--signup stagger-4"
            data-google-signin
            data-client-id="<?= htmlspecialchars(benepeso_google_client_id(), ENT_QUOTES, 'UTF-8') ?>"
            data-csrf="<?= htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"
            data-button-text="signup_with"
          >
            <div class="google-button-host" aria-label="Sign up with Google"></div>
            <p class="google-auth-message" role="status" aria-live="polite"></p>
          </div>
        <?php endif; ?>

        <div class="auth-account-footer stagger-4">
          <p class="small">
            Already have an account?
            <a href="login.php" id="loginLink">Log in securely</a>
          </p>
          <span aria-hidden="true">&bull;</span>
          <a class="auth-support-link" href="https://mail.google.com/mail/?view=cm&amp;fs=1&amp;to=lguvinzonspeso@gmail.com&amp;su=BENEPESO%20Registration%20Help" target="_blank" rel="noopener noreferrer">Contact PESO</a>
        </div>

      </div>
    </div>

  </div>
</div>

<div class="modal-bg" id="modalBg">
  <div class="modal modal--notice" role="dialog" aria-modal="true" aria-labelledby="signupNoticeTitle">
    <button class="modal-close-btn" type="button" id="closeNoticeBtn" name="close_notice_btn" onclick="closeModal('modalBg')" aria-label="Close notice">&times;</button>
    <div class="modal-icon-header notice-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24">
        <defs><linearGradient id="signupNoticeGradient" x1="4" y1="3" x2="20" y2="21"><stop stop-color="#39c77a"/><stop offset="1" stop-color="#12613e"/></linearGradient></defs>
        <circle cx="12" cy="12" r="9" fill="none" stroke="url(#signupNoticeGradient)" stroke-width="2"/>
        <path d="M12 10.5v6" fill="none" stroke="url(#signupNoticeGradient)" stroke-width="2.2" stroke-linecap="round"/>
        <circle cx="12" cy="7.5" r="1.2" fill="url(#signupNoticeGradient)"/>
      </svg>
    </div>
    <h3 class="modal-title" id="signupNoticeTitle">BENEPESO Notice</h3>
    <p style="color:var(--muted); font-size:14px; margin-bottom:25px;" id="modalText"></p>
    <button class="modal-btn" type="button" id="okNoticeBtn" name="ok_notice_btn" onclick="closeModal('modalBg')">Close</button>
  </div>
</div>

<div class="modal-bg" id="ageNoticeBg" aria-hidden="true">
  <div class="modal modal--notice age-notice-modal" role="dialog" aria-modal="true" aria-labelledby="ageNoticeTitle" aria-describedby="ageNoticeText">
    <button class="modal-close-btn" type="button" onclick="closeAgeNotice()" aria-label="Close age requirement notice">&times;</button>
    <div class="age-notice-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24">
        <defs><linearGradient id="ageIconGradient" x1="3" y1="3" x2="21" y2="21"><stop stop-color="#2fbf71"/><stop offset="1" stop-color="#11623e"/></linearGradient></defs>
        <circle cx="12" cy="12" r="9" fill="none" stroke="url(#ageIconGradient)" stroke-width="2"/>
        <path d="M12 7v6" fill="none" stroke="url(#ageIconGradient)" stroke-width="2" stroke-linecap="round"/>
        <circle cx="12" cy="16.5" r="1.1" fill="url(#ageIconGradient)"/>
      </svg>
    </div>
    <h3 class="modal-title" id="ageNoticeTitle">Age Requirement Notice</h3>
    <p class="modal-subtitle" id="ageNoticeText">You must be at least 18 years old to create a BENEPESO account. Please check your birthdate.</p>
    <button class="modal-btn" type="button" onclick="closeAgeNotice()">Review Birthdate</button>
  </div>
</div>

<div class="modal-bg" id="privacyNoticeBg" aria-hidden="true">
  <div class="modal signup-privacy-modal" role="dialog" aria-modal="true" aria-labelledby="privacyNoticeTitle">
    <button class="modal-close-btn" type="button" onclick="closePrivacyNotice()" aria-label="Close privacy notice">&times;</button>
    <h3 class="modal-title" id="privacyNoticeTitle">Privacy Notice</h3>
    <p class="signup-privacy-intro">Review how PESO Vinzons processes and protects your account information.</p>
    <iframe src="privacy_notice.php?embedded=1" title="PESO Vinzons Privacy Notice"></iframe>
    <button class="modal-btn signup-privacy-return" type="button" onclick="closePrivacyNotice()">Return to Registration</button>
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
      if (!birthDateVal) {
          document.getElementById('ageInput').value = '';
          return null;
      }
      const parts = birthDateVal.split('-').map(Number);
      if (parts.length !== 3 || parts.some(Number.isNaN)) return null;
      const birthDate = new Date(parts[0], parts[1] - 1, parts[2]);
      const today = new Date();
      let age = today.getFullYear() - birthDate.getFullYear();
      const m = today.getMonth() - birthDate.getMonth();
      if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
          age--;
      }
      document.getElementById('ageInput').value = Math.max(0, age);
      return age;
  }

  const signupFormAlert = document.getElementById('signupFormAlert');
  const signupStepLabel = document.getElementById('signupStepLabel');
  const signupStepHelp = document.getElementById('signupStepHelp');
  const signupStepDescriptions = {
      1: 'Personal information',
      2: <?= $google_registration ? "'Contact and address'" : "'Address and account security'" ?>,
      3: 'Photo and privacy confirmation'
  };

  function showSignupAlert(message) {
      if (!signupFormAlert) return;
      signupFormAlert.textContent = message;
      signupFormAlert.hidden = false;
  }

  function hideSignupAlert() {
      if (!signupFormAlert) return;
      signupFormAlert.hidden = true;
      signupFormAlert.textContent = '';
  }

  function fieldLabel(input) {
      const label = document.querySelector(`label[for="${input.id}"]`);
      return label ? label.textContent.replace(/\s*\(Optional\)\s*/i, '').trim() : 'This field';
  }

  function fieldMessage(input) {
      const name = fieldLabel(input);
      if (!String(input.value || '').trim()) return `${name} is required.`;
      if (input.validity?.typeMismatch) return `Enter a valid ${name.toLowerCase()}.`;
      if (input.validity?.tooShort) return `${name} must contain at least ${input.minLength} characters.`;
      return `Check the ${name.toLowerCase()} and try again.`;
  }

  function setFieldError(input, message) {
      if (!input) return;
      const group = input.closest('.form-group');
      const errorId = `${input.id}Error`;
      let error = group?.querySelector('.field-error');
      if (!error && group) {
          error = document.createElement('small');
          error.className = 'field-error';
          error.id = errorId;
          group.appendChild(error);
      }
      if (error) error.textContent = message;
      input.classList.add('is-invalid');
      input.classList.remove('is-valid');
      input.setAttribute('aria-invalid', 'true');
      const describedBy = new Set((input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
      describedBy.add(errorId);
      input.setAttribute('aria-describedby', Array.from(describedBy).join(' '));
  }

  function clearFieldError(input, showValid = false) {
      if (!input) return;
      const errorId = `${input.id}Error`;
      input.closest('.form-group')?.querySelector(`#${errorId}`)?.remove();
      input.classList.remove('is-invalid');
      input.classList.toggle('is-valid', showValid && Boolean(String(input.value || '').trim()));
      input.removeAttribute('aria-invalid');
      const describedBy = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(id => id && id !== errorId);
      if (describedBy.length) input.setAttribute('aria-describedby', describedBy.join(' '));
      else input.removeAttribute('aria-describedby');
  }

  function goToStep(step) {
      if (step > currentStep) {
          const currentStepEl = document.getElementById('step' + currentStep);
          const requiredInputs = currentStepEl.querySelectorAll('[required]');
          let isValid = true;

          let firstInvalid = null;

          requiredInputs.forEach(input => {
              if (!String(input.value || '').trim() || !input.checkValidity()) {
                  isValid = false;
                  firstInvalid ||= input;
                  setFieldError(input, fieldMessage(input));
              } else {
                  clearFieldError(input, true);
              }
          });

          if (!isValid) {
              showSignupAlert('Please review the highlighted fields before continuing.');
              firstInvalid?.focus({ preventScroll: true });
              return;
          }

          if (currentStep === 1) {
              const age = calculateAge();
              if (age === null || age < 18) {
                  const birthDateInput = document.getElementById('birthDate');
                  setFieldError(birthDateInput, 'You must be at least 18 years old to register.');
                  showSignupAlert('Please review your date of birth.');
                  openModal("ageNoticeBg");
                  return;
              }
          }

          if (currentStep === 2) {
              const passInput = document.getElementById('passwordInput');
              const confirmInput = document.getElementById('confirmPasswordInput');
              const pass = passInput ? passInput.value : '';
              const conf = confirmInput ? confirmInput.value : '';
              const contact = document.getElementById('contactInput').value;
              
              if (contact.length !== 11 || !contact.startsWith("09")) {
                  const contactInput = document.getElementById('contactInput');
                  setFieldError(contactInput, 'Use an 11-digit Philippine mobile number beginning with 09.');
                  showSignupAlert('Please review the highlighted contact number.');
                  contactInput.focus({ preventScroll: true });
                  return;
              }
              if (passInput && pass !== conf) {
                  setFieldError(confirmInput, 'Passwords do not match.');
                  showSignupAlert('Please confirm your account password.');
                  confirmInput.focus({ preventScroll: true });
                  return;
              }
              if (passInput && (pass.length < 10 || !/[a-z]/.test(pass) || !/[A-Z]/.test(pass) || !/[0-9]/.test(pass) || !/[^A-Za-z0-9]/.test(pass))) {
                  setFieldError(passInput, 'Use 10+ characters with uppercase, lowercase, a number, and a symbol.');
                  showSignupAlert('Please meet all password security requirements.');
                  passInput.focus({ preventScroll: true });
                  return;
              }
          }
      }

      hideSignupAlert();

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
              tracker.removeAttribute('aria-current');
          } else if (i === step) {
              tracker.classList.add('active');
              tracker.classList.remove('completed');
              tracker.setAttribute('aria-current', 'step');
          } else {
              tracker.classList.remove('active', 'completed');
              tracker.removeAttribute('aria-current');
          }
      }

      currentStep = step;
      if (signupStepLabel) signupStepLabel.textContent = `Step ${step} of 3`;
      if (signupStepHelp) signupStepHelp.textContent = signupStepDescriptions[step];

      setTimeout(() => {
          nextEl.querySelector('input:not([type="hidden"]):not([readonly]), select, button')?.focus({ preventScroll: true });
      }, 280);
  }

  const pass1 = document.getElementById('passwordInput');
  const pass2 = document.getElementById('confirmPasswordInput');

  function updatePasswordStrength() {
      if (!pass1) return;
      const value = pass1.value;
      const checks = {
          length: value.length >= 10,
          case: /[a-z]/.test(value) && /[A-Z]/.test(value),
          number: /[0-9]/.test(value),
          symbol: /[^A-Za-z0-9]/.test(value)
      };
      const score = Object.values(checks).filter(Boolean).length;
      const bar = document.getElementById('passwordStrengthBar');
      const label = document.getElementById('passwordStrengthLabel');
      const labels = value ? ['Needs work', 'Needs work', 'Fair', 'Good', 'Strong'] : ['Start typing'];
      if (bar) {
          bar.style.width = value ? `${Math.max(18, score * 25)}%` : '0';
          bar.style.backgroundColor = score >= 4 ? '#26835b' : (score >= 2 ? '#c09a35' : '#b85b4f');
      }
      if (label) label.textContent = labels[value ? score : 0];
      Object.entries(checks).forEach(([key, met]) => {
          document.querySelector(`[data-password-check="${key}"]`)?.classList.toggle('is-met', met);
      });
  }

  function checkPasswordMatch() {
      if (!pass1 || !pass2) return;
      if(pass2.value.length === 0) {
          clearFieldError(pass2);
          return;
      }
      if(pass1.value === pass2.value && pass1.value.length >= 10) {
          clearFieldError(pass2, true);
      } else {
          setFieldError(pass2, 'Passwords do not match.');
      }
  }

  pass1?.addEventListener('input', () => {
      updatePasswordStrength();
      const value = pass1.value;
      const isStrong = value.length >= 10 && /[a-z]/.test(value) && /[A-Z]/.test(value) && /[0-9]/.test(value) && /[^A-Za-z0-9]/.test(value);
      clearFieldError(pass1, isStrong);
      checkPasswordMatch();
  });
  pass2?.addEventListener('input', checkPasswordMatch);
  updatePasswordStrength();

  document.querySelectorAll('#signupForm input:not([type="hidden"]):not([type="file"]), #signupForm select').forEach(input => {
      const clear = () => {
          const value = String(input.value || '').trim();
          const isContactValid = input.id !== 'contactInput' || (value.length === 11 && value.startsWith('09'));
          if (input.checkValidity() && value && isContactValid) clearFieldError(input, true);
      };
      input.addEventListener('input', clear);
      input.addEventListener('change', clear);
  });

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
    if (modal.dataset.locked === 'true') return;
    modal.style.display = "none";
    const hasOpenModal = Array.from(document.querySelectorAll('.modal-bg')).some(item => item.style.display === 'flex');
    if (!hasOpenModal) document.body.classList.remove("modal-open");
  }

  window.showAuthNotice = function(message, title = "BENEPESO Notice", options = {}) {
    const titleEl = document.getElementById("signupNoticeTitle");
    const messageEl = document.getElementById("modalText");
    const dialog = document.querySelector("#modalBg .modal");
    const action = document.getElementById("okNoticeBtn");
    const close = document.getElementById("closeNoticeBtn");
    const modalRoot = document.getElementById('modalBg');
    const inferredError = /access denied|restricted|banned|could not|not completed|invalid|expired|already registered|error/i.test(message);
    const inferredSuccess = /success|verified|created/i.test(message);
    const type = options.type || (inferredError ? (/restricted|banned|access denied/i.test(message) ? 'restricted' : 'error') : (inferredSuccess ? 'success' : 'info'));
    if (titleEl) titleEl.textContent = title;
    if (messageEl) messageEl.textContent = message;
    if (dialog) dialog.dataset.state = type;
    if (action) {
      action.textContent = options.actionLabel || (type === 'success' ? 'Continue' : 'Close');
      action.onclick = () => options.redirect ? window.location.assign(options.redirect) : closeModal('modalBg');
    }
    if (close) {
      close.style.display = options.redirect ? 'none' : '';
    }
    if (modalRoot) modalRoot.dataset.locked = options.redirect ? 'true' : 'false';
    openModal("modalBg");
    window.setTimeout(() => action?.focus(), 80);
  };

  function openPrivacyNotice() {
    const modal = document.getElementById('privacyNoticeBg');
    modal.setAttribute('aria-hidden', 'false');
    openModal('privacyNoticeBg');
    modal.querySelector('.modal-close-btn')?.focus();
  }

  function closePrivacyNotice() {
    const modal = document.getElementById('privacyNoticeBg');
    modal.setAttribute('aria-hidden', 'true');
    closeModal('privacyNoticeBg');
    document.querySelector('.privacy-notice-link')?.focus();
  }

  function closeAgeNotice() {
    const modal = document.getElementById('ageNoticeBg');
    modal.setAttribute('aria-hidden', 'true');
    closeModal('ageNoticeBg');
    document.getElementById('birthDate')?.focus();
  }

  const showAgeNotice = <?= $show_age_notice ? 'true' : 'false' ?>;
  const googleSignupSuccess = <?= $google_signup_success ? 'true' : 'false' ?>;
  if (googleSignupSuccess) {
    window.showAuthNotice(
      'Your BENEPESO account has been created and linked securely to Google. Use Google for future sign-ins. If you also want password access, choose Forgot Password on the login page to create a BENEPESO password.',
      'Account created successfully',
      { type: 'success', actionLabel: 'Go to my dashboard', redirect: 'home.php' }
    );
  } else if (showAgeNotice) {
    openModal("ageNoticeBg");
  } else if (flash){
    window.showAuthNotice(flash, /access denied|restricted|banned/i.test(flash) ? "Access denied" : "BENEPESO Notice");
  }

  document.getElementById("modalBg").addEventListener("mousedown", (event) => {
    if (event.target === event.currentTarget) closeModal("modalBg");
  });

  document.getElementById("ageNoticeBg").addEventListener("mousedown", (event) => {
    if (event.target === event.currentTarget) closeAgeNotice();
  });

  document.getElementById("privacyNoticeBg").addEventListener("mousedown", (event) => {
    if (event.target === event.currentTarget) closePrivacyNotice();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      if (document.getElementById("ageNoticeBg").style.display === "flex") {
        closeAgeNotice();
      } else if (document.getElementById("privacyNoticeBg").style.display === "flex") {
        closePrivacyNotice();
      } else if (document.getElementById("modalBg").style.display === "flex") {
        closeModal("modalBg");
      }
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
    if (profileInput.required && !profileInput.value) {
        e.preventDefault();
        uploadContainer.style.borderColor = "#c0392b";
        uploadContainer.style.background = "#fdf2f0";
        showSignupAlert('Upload a clear profile photo to complete registration.');
        profileInput.focus();
        return;
    }

    const privacyInput = document.getElementById('privacyAcknowledgment');
    if (!privacyInput.checked) {
        e.preventDefault();
        showSignupAlert('Read and acknowledge the Privacy Notice before registering.');
        privacyInput.closest('.privacy-acknowledgment')?.classList.add('is-invalid');
        privacyInput.focus();
        return;
    }

    const passwordInput = document.getElementById("passwordInput");
    const confirmPasswordInput = document.getElementById("confirmPasswordInput");
    if (passwordInput && passwordInput.value !== confirmPasswordInput.value){
      e.preventDefault();
      goToStep(2);
      window.setTimeout(() => {
          setFieldError(confirmPasswordInput, 'Passwords do not match.');
          showSignupAlert('Please confirm your account password.');
          confirmPasswordInput.focus({ preventScroll: true });
      }, 300);
      return;
    }
    
    showLoading("Creating account", "Uploading photo and saving details securely...");
  });

  document.getElementById('privacyAcknowledgment')?.addEventListener('change', (event) => {
      event.target.closest('.privacy-acknowledgment')?.classList.remove('is-invalid');
      if (event.target.checked) hideSignupAlert();
  });
</script>
<script src="signup_draft.js?v=1"></script>

</body>
</html>
