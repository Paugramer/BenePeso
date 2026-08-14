<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "db.php"; 

$flash = $_SESSION["flash"] ?? "";
unset($_SESSION["flash"]);

if (isset($_GET['msg']) && ($_GET['msg'] === 'banned' || $_GET['msg'] === 'account_banned')) {
    $flash = "Access Denied: Your account has been banned by an administrator.";
}

$login_email = $_SESSION["login_email"] ?? "";
unset($_SESSION["login_email"]);

$fp_step  = $_SESSION["fp_step"] ?? "";
$fp_email = $_SESSION["fp_email"] ?? "";
$fp_msg   = $_SESSION["fp_msg"] ?? "";
$fp_role  = $_SESSION["fp_role"] ?? "";

unset($_SESSION["fp_step"]);
unset($_SESSION["fp_msg"]);

$reset_name = "User Account"; 
$masked_email = "";

if (!empty($fp_email) && !empty($fp_role)) {
    $tbl = "users";
    if ($fp_role === "peso_staff") {
        $tbl = "peso_staff";
    } elseif ($fp_role === "admin") {
        $tbl = "admin"; 
    }

    $stmt_n = $conn->prepare("SELECT first_name, last_name FROM $tbl WHERE email=?");
    if ($stmt_n) {
        $stmt_n->bind_param("s", $fp_email);
        $stmt_n->execute();
        $res_n = $stmt_n->get_result();
        if ($row_n = $res_n->fetch_assoc()) {
            $reset_name = trim(($row_n["first_name"] ?? "") . " " . ($row_n["last_name"] ?? ""));
        }
        $stmt_n->close();
    }

    function maskEmail($email) {
        if (empty($email) || strpos($email, '@') === false) return "";
        list($name, $domain) = explode('@', $email);
        $len = strlen($name);
        
        if ($len <= 2) {
            $masked_name = substr($name, 0, 1) . '***';
        } else {
            $masked_name = substr($name, 0, 2) . str_repeat('*', $len - 3) . substr($name, -1);
        }
        return $masked_name . '@' . $domain;
    }
    
    $masked_email = maskEmail($fp_email);
}

$msg_type = "error";
if (strpos(strtolower($fp_msg), 'sent') !== false || strpos(strtolower($fp_msg), 'success') !== false || strpos(strtolower($fp_msg), 'verified') !== false) {
    $msg_type = "success";
}

$lock_until = $_SESSION["lock_until"] ?? 0;
$now = time();
$locked = ($lock_until && $lock_until > $now);
$lock_seconds = $locked ? ($lock_until - $now) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="img/pesologo.png">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>BENEPESO | Secure Login</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="style.css?v=15" />
  <link rel="stylesheet" href="frontend_polish.css?v=1">
  <script src="frontend_polish.js?v=1" defer></script>
</head>
<body class="auth-page auth-login">

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
          <span class="badge">Secure Login</span>
          <span class="badge">Verification</span>
          <span class="badge">PESO Services</span>
        </div>
      </div>
    </div>

    <div class="right">
      <div class="right-inner">

        <div class="role-label stagger-1" style="margin-top: 20px;">System Access</div>

        <h2 class="stagger-2">Welcome Back</h2>
        <p class="sub stagger-2">Please enter your email and password to log in.</p>

        <?php if ($locked): ?>
          <div class="lock-box stagger-3">
            Too many failed attempts. Please wait <b id="lockTimer"><?php echo (int)$lock_seconds; ?></b> seconds before trying again.
          </div>
        <?php endif; ?>

        <form action="process_login.php" method="POST" autocomplete="off" id="loginForm" class="stagger-3" onsubmit="showLoading('Authenticating', 'Verifying your credentials securely...')">
          
          <div class="form-group">
              <label for="email">Email Address</label>
              <input
                type="email"
                id="email"
                name="email"
                placeholder="e.g. juan@email.com"
                value="<?php echo htmlspecialchars($login_email); ?>"
                required
                <?php echo $locked ? "disabled" : ""; ?>
              >
          </div>

          <div class="form-group">
              <label for="passwordInput">Password</label>
              <div class="password-wrap">
                <input
                  type="password"
                  name="password"
                  id="passwordInput"
                  placeholder="Enter your password"
                  required
                  <?php echo $locked ? "disabled" : ""; ?>
                >
                <button type="button" class="toggle-pass" data-target="passwordInput" aria-label="Show password">
                  <svg viewBox="0 0 24 24">
                    <path fill="currentColor" d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 13.5 2.5 12S6.5 5 12 5Zm0 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8Z"/>
                  </svg>
                </button>
              </div>
          </div>

          <button class="btn" type="submit" id="loginBtn" <?php echo $locked ? "disabled" : ""; ?>>
            Secure Login
          </button>
        </form>

        <p class="small stagger-4" style="margin-top:16px;">
          <a href="#" id="forgotLink">Forgot your password?</a>
        </p>

        <p class="small stagger-4" style="margin-top:8px;">
          Don’t have an account?
          <a href="signup.php" id="signupLink">Register here</a>
        </p>

      </div>
    </div>

  </div>
</div>

<div class="modal-bg" id="modalBg">
  <div class="modal modal--notice" role="dialog" aria-modal="true" aria-labelledby="noticeTitle">
    <button class="modal-close-btn" type="button" onclick="closeModal('modalBg')" aria-label="Close notice">&times;</button>
    <div class="modal-icon-header" style="color: #f39c12; background: #fef5e7;">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
    </div>
    <h3 class="modal-title" id="noticeTitle">BENEPESO Notice</h3>
    <p style="color:var(--muted); font-size:14px; margin-bottom:25px;" id="modalText"></p>
    <button class="modal-btn" type="button" onclick="closeModal('modalBg')">Close</button>
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

<div class="modal-bg" id="fpEmailBg">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="forgotTitle">
    <button class="modal-close-btn" type="button" onclick="closeModal('fpEmailBg')" aria-label="Close forgot password dialog">&times;</button>
    <div class="modal-icon-header">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
    </div>
    <div class="modal-title" id="forgotTitle">Forgot Password</div>
    <p class="modal-subtitle">Enter your email address and we will send you a 6-digit recovery code.</p>

    <form action="forgot_send.php" method="POST" style="margin-top:20px; text-align:left;" autocomplete="off"
          onsubmit="showLoading('Sending Code', 'Sending verification code securely...')">
      <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" placeholder="e.g. juan@email.com" value="<?php echo htmlspecialchars($fp_email); ?>" required>
      </div>
      <button class="modal-btn" style="margin-top:15px;" type="submit">Send Recovery Code</button>
    </form>

    <?php if ($fp_msg && $fp_step === "email"): ?>
      <div class="alert-msg <?php echo $msg_type; ?>"><?php echo htmlspecialchars($fp_msg); ?></div>
    <?php endif; ?>
  </div>
</div>

<div class="modal-bg" id="fpCodeBg">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="codeTitle">
    <button class="modal-close-btn" type="button" onclick="closeModal('fpCodeBg')" aria-label="Close security code dialog">&times;</button>
    <div class="modal-icon-header">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
    </div>
    <div class="modal-title" id="codeTitle">Enter Security Code</div>
    <p class="modal-subtitle">We sent a 6-digit verification code to <b><?php echo htmlspecialchars($masked_email ?: $fp_email); ?></b></p>

    <form action="forgot_verify.php" method="POST" id="verifyCodeForm" style="margin-top:10px;" autocomplete="off">
      
      <div class="otp-container">
          <input type="text" class="otp-box" maxlength="1" inputmode="numeric" required>
          <input type="text" class="otp-box" maxlength="1" inputmode="numeric" required>
          <input type="text" class="otp-box" maxlength="1" inputmode="numeric" required>
          <input type="text" class="otp-box" maxlength="1" inputmode="numeric" required>
          <input type="text" class="otp-box" maxlength="1" inputmode="numeric" required>
          <input type="text" class="otp-box" maxlength="1" inputmode="numeric" required>
      </div>
      <input type="hidden" name="code" id="actualCodeInput" required>

      <button class="modal-btn" style="margin-top:20px;" type="submit">Verify Code</button>
    </form>

    <form action="forgot_send.php" method="POST" style="margin-top:10px;"
          onsubmit="showLoading('Resending Code', 'Resending verification code...')">
      <input type="hidden" name="email" value="<?php echo htmlspecialchars($fp_email); ?>">
      <button class="modal-btn btn-secondary" type="submit">Resend Code</button>
    </form>

    <?php if ($fp_msg && $fp_step === "code"): ?>
      <div class="alert-msg <?php echo $msg_type; ?>"><?php echo htmlspecialchars($fp_msg); ?></div>
    <?php endif; ?>
  </div>
</div>

<div class="modal-bg" id="fpResetBg">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="resetTitle">
    <button class="modal-close-btn" type="button" onclick="closeModal('fpResetBg')" aria-label="Close password reset dialog">&times;</button>
    <div class="modal-icon-header">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
    </div>
    <div class="modal-title" id="resetTitle">Create New Password</div>
    
    <div class="reset-account-badge">
        <div class="reset-avatar"><?php echo strtoupper(substr($reset_name, 0, 1) ?: 'U'); ?></div>
        <div class="reset-info">
            <span class="reset-label">Resetting password for</span>
            <span style="font-size: 14px; color: var(--green-dark); font-weight: 800;"><?php echo htmlspecialchars($reset_name); ?></span>
            <span style="font-size: 11px; color: var(--muted); letter-spacing: 0.5px;"><?php echo htmlspecialchars($masked_email); ?></span>
        </div>
    </div>

    <p class="modal-subtitle" style="text-align:left; margin-bottom:15px;">Your new password must be at least 8 characters long.</p>

    <form action="forgot_reset.php" method="POST" style="text-align:left;" autocomplete="off"
          onsubmit="showLoading('Updating Password', 'Updating your password securely...')">
      
      <div class="form-group">
          <label>New Password</label>
          <div class="password-wrap">
              <input type="password" name="new_password" id="newPassFp" placeholder="Enter new password" required>
              <button type="button" class="toggle-pass" data-target="newPassFp" aria-label="Show password">
                  <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 13.5 2.5 12S6.5 5 12 5Zm0 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8Z"/></svg>
              </button>
          </div>
      </div>

      <div class="form-group" style="margin-top:12px;">
          <label>Confirm Password</label>
          <div class="password-wrap">
              <input type="password" name="confirm_password" id="confPassFp" placeholder="Retype new password" required>
              <button type="button" class="toggle-pass" data-target="confPassFp" aria-label="Show password">
                  <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 13.5 2.5 12S6.5 5 12 5Zm0 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8Z"/></svg>
              </button>
          </div>
      </div>

      <button class="modal-btn" style="margin-top:20px;" type="submit">Update Password</button>
    </form>

    <?php if ($fp_msg && $fp_step === "reset"): ?>
      <div class="alert-msg <?php echo $msg_type; ?>"><?php echo htmlspecialchars($fp_msg); ?></div>
    <?php endif; ?>
  </div>
</div>

<script>
  const flash = <?php echo json_encode($flash); ?>;
  const fpStep = <?php echo json_encode($fp_step); ?>;
  const isLocked = <?php echo $locked ? "true" : "false"; ?>;

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

  function showLoading(title, msg){
    document.querySelectorAll('.modal-bg').forEach(modal => {
        modal.style.display = 'none';
    });
    
    document.getElementById("loadingTitle").textContent = title;
    document.getElementById("loadingMsg").textContent = msg;
    openModal("loadingBg");
  }

  document.getElementById("forgotLink").addEventListener("click", (e)=>{
    e.preventDefault();
    openModal("fpEmailBg");
  });

  const otpBoxes = document.querySelectorAll('.otp-box');
  const actualCodeInput = document.getElementById('actualCodeInput');
  const verifyCodeForm = document.getElementById('verifyCodeForm');
  
  if(otpBoxes.length > 0) {
      otpBoxes.forEach((box, index) => {
          box.addEventListener('input', (e) => {
              box.value = box.value.replace(/[^0-9]/g, '');
              if(box.value && index < otpBoxes.length - 1) {
                  otpBoxes[index + 1].focus();
              }
          });

          box.addEventListener('keydown', (e) => {
              if (e.key === 'Backspace' && !box.value && index > 0) {
                  otpBoxes[index - 1].focus();
              }
          });

          box.addEventListener('paste', (e) => {
              e.preventDefault();
              const pastedData = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0, 6);
              for (let i = 0; i < pastedData.length; i++) {
                  otpBoxes[i].value = pastedData[i];
              }
              if(pastedData.length > 0) {
                  otpBoxes[Math.min(pastedData.length, 5)].focus();
              }
          });
      });

      verifyCodeForm.addEventListener('submit', function(e) {
          let code = '';
          otpBoxes.forEach(b => code += b.value);
          actualCodeInput.value = code;
          showLoading('Verifying Code', 'Please wait while we verify your code...');
      });
  }

  if (fpStep === "email") openModal("fpEmailBg");
  if (fpStep === "code")  openModal("fpCodeBg");
  if (fpStep === "reset") openModal("fpResetBg");

  ["modalBg","fpEmailBg","fpCodeBg","fpResetBg"].forEach(id=>{
    const el = document.getElementById(id);
    if (el){
      el.addEventListener("mousedown", (e)=>{
        if(e.target === el) closeModal(id);
      });
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;
    const openDialog = Array.from(document.querySelectorAll('.modal-bg')).find(item => item.style.display === 'flex' && item.id !== 'loadingBg');
    if (openDialog) closeModal(openDialog.id);
  });

  let lockSeconds = <?php echo (int)$lock_seconds; ?>;
  const timerEl = document.getElementById("lockTimer");
  if (timerEl && lockSeconds > 0){
    const t = setInterval(()=>{
      lockSeconds--;
      timerEl.textContent = lockSeconds;
      if (lockSeconds <= 0){
        clearInterval(t);
        location.reload();
      }
    }, 1000);
  }

  const loginForm = document.getElementById("loginForm");
  if (loginForm && !isLocked){
    loginForm.addEventListener("submit", ()=>{
      if(loginForm.checkValidity()) {
         loginForm.querySelector('button[type="submit"]').disabled = true;
      }
    });
  }

  document.querySelectorAll(".toggle-pass").forEach(btn => {
    btn.addEventListener("click", ()=>{
      const target = document.getElementById(btn.dataset.target);
      if (!target) return;
      target.type = target.type === "password" ? "text" : "password";
      btn.style.color = target.type === "text" ? "var(--green)" : "#9ab0a3";
    });
  });
</script>

</body>
</html>
