<?php
session_start();
require "db.php";

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }
function e($v){ return h($v); }

function navClass($fileName){
    $current = basename($_SERVER["PHP_SELF"]);
    return ($current === $fileName) ? "nav-item active" : "nav-item";
}

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit();
}

$admin_id = (int)$_SESSION["admin_id"];
$admin_name = $_SESSION["admin_name"] ?? "Admin";
$admin_pic = "default_avatar.png";
$pic_path = "uploads/admin_pics/" . $admin_pic;
if (!file_exists($pic_path) || empty($admin_pic)) { $pic_path = "img/default_avatar.png"; }
$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fname = trim($_POST['first_name']);
    $lname = trim($_POST['last_name']);
    $ext = trim($_POST['extension_name'] ?? '');

    $position = 'PESO Staff';

    $email = trim($_POST['email']);
    $pass = trim($_POST['password']);
    $cpass = trim($_POST['confirm_password']);

    if ($pass !== $cpass) {
        $error = "Passwords do not match.";
    } elseif (strlen($pass) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        $stmt = $conn->prepare("SELECT email FROM peso_staff WHERE email = ? UNION SELECT email FROM users WHERE email = ?");
        $stmt->bind_param("ss", $email, $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = "This email address is already in use.";
        } else {
            $hashed_password = password_hash($pass, PASSWORD_DEFAULT);
            $profile_pic = 'default_avatar.png';

            if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
                $allowed = ['jpg', 'jpeg', 'png'];
                $filename = $_FILES['profile_pic']['name'];
                $ext_file = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                if (in_array($ext_file, $allowed)) {
                    $new_name = uniqid("staff_") . "." . $ext_file;
                    $dest_dir = "uploads/staff_pics/";

                    if (!is_dir($dest_dir)) mkdir($dest_dir, 0777, true);

                    if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $dest_dir . $new_name)) {
                        $profile_pic = $new_name;
                    }
                } else {
                    $error = "Invalid image format. Only JPG and PNG are allowed.";
                }
            }

            if (empty($error)) {
                $insert = $conn->prepare("INSERT INTO peso_staff (first_name, last_name, extension_name, position, email, password_hash, profile_picture) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $insert->bind_param("sssssss", $fname, $lname, $ext, $position, $email, $hashed_password, $profile_pic);

                if ($insert->execute()) {
                    
                    $staff_full_name = trim($fname . " " . $lname);
                    $log_desc = "Created a new PESO Staff account for " . $staff_full_name . ".";
                    
                    $log_stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, description, created_at) VALUES (?, 'Administrator', 'Manage Accounts', 'CREATE', ?, NOW())");
                    if ($log_stmt) {
                        $log_stmt->bind_param("ss", $admin_name, $log_desc);
                        $log_stmt->execute();
                        $log_stmt->close();
                    }

                    $_SESSION['flash'] = "Staff account for $fname $lname was successfully created.";
                    $_SESSION['flash_type'] = "success";
                    header("Location: admin_accounts.php?view=staff");
                    exit();
                } else {
                    $error = "Database error. Failed to create account.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/pesologo.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BENEPESO | Add Staff</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="stylesheet" href="admin_accounts.css">
<link rel="stylesheet" href="shared_sidebar.css">
<style>
    .form-card, .form-header h2, .form-header p, .step-item, .form-group label, .form-group input, .btn-main, .btn-light, .back-link-inner {
        font-family: 'Poppins', sans-serif;
    }

    .content-wrapper {
        max-width: 850px;
        margin: 0 auto;
        padding-top: 10px;
        width: 100%;
    }

    .btn-main {
        background: var(--green);
        color: #fff;
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 700;
        font-size: 14px;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(31,122,84,0.2);
    }
    .btn-main:hover {
        background: var(--green-dark);
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(31,122,84,0.3);
    }

    .btn-light {
        background: #fff;
        color: var(--text);
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 700;
        font-size: 14px;
        border: 1px solid var(--line);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    .btn-light:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        transform: translateY(-2px);
    }

    .form-card {
        background: var(--card);
        border-radius: var(--radius-xl);
        padding: 30px 40px;
        box-shadow: var(--shadow-soft);
        border: 1px solid var(--line);
    }

    .back-link-inner {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #64748b;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        margin-bottom: 20px;
        transition: color 0.3s ease, transform 0.3s ease;
    }
    .back-link-inner:hover {
        color: var(--green);
        transform: translateX(-4px);
    }

    .form-header {
        margin-bottom: 24px;
        text-align: center;
    }
    .form-header h2 {
        color: var(--green-dark);
        font-size: 26px;
        font-weight: 800;
        margin-bottom: 4px;
        letter-spacing: -0.5px;
    }
    .form-header p {
        color: var(--muted);
        font-size: 14px;
        font-weight: 500;
    }

    .step-tracker {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 16px;
        margin-bottom: 32px;
    }
    .step-item {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #64748b;
        font-weight: 700;
        font-size: 14px;
        transition: 0.3s ease;
    }
    .step-item.active {
        color: var(--green-dark);
    }
    .step-circle {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #f1f5f9;
        display: grid;
        place-items: center;
        font-size: 13px;
        color: #64748b;
        border: 2px solid #cbd5e1;
        transition: 0.3s ease;
        font-weight: 800;
    }
    .step-item.active .step-circle {
        background: var(--green);
        color: #fff;
        border-color: var(--green);
        box-shadow: 0 4px 12px rgba(31,122,84,0.3);
    }
    .step-connector {
        width: 60px;
        height: 3px;
        background: #cbd5e1;
        border-radius: 2px;
        transition: 0.3s ease;
    }
    .step-connector.active {
        background: var(--green);
    }

    .form-step {
        display: none;
        animation: fadeInStep 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    .form-step.active {
        display: block;
    }
    @keyframes fadeInStep {
        from { opacity: 0; transform: translateX(15px); }
        to { opacity: 1; transform: translateX(0); }
    }

    .step2-landscape {
        display: flex;
        align-items: center;
        gap: 40px;
        padding: 10px 0;
    }
    .step2-left {
        flex: 0 0 220px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border-right: 1px dashed rgba(0,0,0,0.1);
        padding-right: 40px;
    }
    .step2-right {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .profile-preview {
        width: 140px;
        height: 140px;
        border-radius: 50%;
        background: var(--green-light);
        border: 2px dashed rgba(31,122,84,0.3);
        display: grid;
        place-items: center;
        overflow: hidden;
        position: relative;
        margin-bottom: 16px;
    }
    .profile-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: none;
    }
    .profile-preview i {
        color: var(--green);
        font-size: 40px;
    }
    .upload-btn-label {
        background: #fff;
        color: var(--green-dark);
        padding: 10px 20px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        border: 1px solid var(--line);
        transition: 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: var(--shadow-sm);
    }
    .upload-btn-label:hover {
        background: var(--green-light);
        border-color: var(--green);
        color: var(--green);
        transform: translateY(-2px);
        box-shadow: var(--shadow-hover);
    }

    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
    }
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .form-group.full-width {
        grid-column: 1 / -1;
    }
    .form-group label {
        font-size: 11.5px;
        font-weight: 800;
        color: var(--text);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .form-group input {
        width: 100%;
        padding: 12px 16px;
        border-radius: 12px;
        border: 1px solid #cbd5e1;
        font-family: inherit;
        font-size: 14px;
        color: var(--text);
        font-weight: 500;
        background: #fff;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.01);
        height: 48px;
        transition: border-color 0.3s ease, box-shadow 0.3s ease, transform 0.2s ease;
    }
    .form-group input:focus {
        border-color: var(--green);
        outline: none;
        box-shadow: 0 0 0 4px rgba(31,122,84,0.1);
    }

    .password-wrap {
        position: relative;
    }
    .toggle-pass {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #9ab0a3;
        cursor: pointer;
        padding: 0;
        display: grid;
        place-items: center;
        font-size: 20px;
        transition: color 0.3s ease;
    }
    .toggle-pass:hover {
        color: var(--green);
    }

    .alert {
        padding: 16px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .alert-error {
        background: #fef2f2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }

    .form-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 32px;
        padding-top: 24px;
        border-top: 1px solid var(--line);
    }

    @media(max-width: 768px) {
        .step2-landscape { flex-direction: column; gap: 24px; }
        .step2-left { border-right: none; border-bottom: 1px dashed rgba(0,0,0,0.1); padding-right: 0; padding-bottom: 24px; width: 100%; flex: auto; }
        .form-grid { grid-template-columns: 1fr; }
        .form-card { padding: 24px; }
    }
</style>
<link rel="stylesheet" href="admin_accounts_polish.css">
<link rel="stylesheet" href="frontend_polish.css?v=1">
<script src="frontend_polish.js?v=1" defer></script>
</head>
<body>

<div class="page-wrap">

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="side-area" id="sideArea">
        <div class="side-top">
            <div class="side-brand">
                <img src="img/pesologo.png" alt="PESO Logo" class="side-logo">
                <div>
                    <div class="side-title">BENEPESO</div>
                    <div class="side-sub">Admin Panel</div>
                </div>
            </div>
            <button class="side-close" id="sideClose" type="button" aria-label="Close menu">
                <i class="ph ph-x"></i>
            </button>
        </div>

        <div class="side-user">
          <div class="user-pic-wrap">
            <img src="<?php echo e($pic_path ?? ''); ?>" alt="Admin" class="user-img-side" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin_name); ?>&background=%231f7a54&color=%23fff';">
          </div>
          <div>
            <div class="user-name"><?php echo e($admin_name); ?></div>
            <div class="user-role">Administrator</div>
          </div>
        </div>

        <nav class="nav-area">
            <a href="admin_dashboard.php" class="<?php echo navClass('admin_dashboard.php'); ?>"><i class="ph ph-squares-four"></i> Dashboard</a>
            <a href="admin_program.php" class="<?php echo navClass('admin_program.php'); ?>"><i class="ph ph-briefcase"></i> Programs</a>
            <a href="admin_beneficiaries.php" class="<?php echo navClass('admin_beneficiaries.php'); ?>"><i class="ph ph-users"></i> Beneficiaries</a>
            <a href="admin_accounts.php" class="<?php echo navClass('admin_add_staff.php'); ?>"><i class="ph ph-user-circle-gear"></i> Manage Accounts</a>
            <a href="admin_activity_log.php" class="<?php echo navClass('admin_activity_log.php'); ?>"><i class="ph ph-clock-counter-clockwise"></i> System Logs</a>
            <a href="logout.php?role=admin" class="nav-item logout-item"><i class="ph ph-sign-out"></i> Logout</a>
        </nav>
    </aside>

    <main class="main-area">
        
        <header class="top-area animate-fade-in">
            <div class="top-left">
                <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
                    <span></span><span></span><span></span>
                </button>
                <div class="top-title">
                    <div class="eyebrow">ACCOUNT CONTROL</div>
                    <div class="top-big">Add PESO Staff</div>
                    <div class="top-sub">Register a new administrator to the system.</div>
                </div>
            </div>

            <div class="top-actions">
                <div class="top-chip">
                    <img src="<?php echo e($pic_path ?? ''); ?>" alt="" class="chip-img" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin_name); ?>&background=%231f7a54&color=%23fff';">
                    Administrator
                </div>
            </div>
        </header>

        <div class="content-wrapper">

            <div class="form-card animate-fade-in" style="animation-delay: 0.2s;">
                
                <a href="admin_accounts.php?view=staff" class="back-link-inner">
                    <i class="ph-bold ph-arrow-left"></i> Back to Accounts
                </a>

                <div class="form-header">
                    <h2>Staff Registration</h2>
                    <p>Complete the profile and account security details below.</p>
                </div>

                <div class="step-tracker">
                    <div class="step-item active" id="tracker1">
                        <div class="step-circle">1</div>
                        Profile Info
                    </div>
                    <div class="step-connector" id="tracker-connector"></div>
                    <div class="step-item" id="tracker2">
                        <div class="step-circle">2</div>
                        Account Security
                    </div>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-error">
                        <i class="ph-fill ph-warning-circle" style="font-size: 20px;"></i>
                        <?= h($error) ?>
                    </div>
                <?php endif; ?>
                
                <div class="alert alert-error" id="jsError" style="display: none;">
                    <i class="ph-fill ph-warning-circle" style="font-size: 20px;"></i>
                    <span id="jsErrorText"></span>
                </div>

                <form method="POST" action="admin_add_staff.php" enctype="multipart/form-data" autocomplete="off" id="addStaffForm">
                    
                    <div class="form-step active" id="step1">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="first_name">First Name <span style="color:#e11d48;">*</span></label>
                                <input type="text" id="first_name" name="first_name" placeholder="e.g. Juan" required value="<?= isset($_POST['first_name']) ? h($_POST['first_name']) : '' ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="last_name">Last Name <span style="color:#e11d48;">*</span></label>
                                <input type="text" id="last_name" name="last_name" placeholder="e.g. Dela Cruz" required value="<?= isset($_POST['last_name']) ? h($_POST['last_name']) : '' ?>">
                            </div>

                            <div class="form-group">
                                <label for="extension_name">Extension Name</label>
                                <input type="text" id="extension_name" name="extension_name" placeholder="e.g. Jr., Sr. (Optional)" value="<?= isset($_POST['extension_name']) ? h($_POST['extension_name']) : '' ?>">
                            </div>

                            <div class="form-group">
                                <label for="email">Email Address <span style="color:#e11d48;">*</span></label>
                                <input type="email" id="email" name="email" placeholder="staff@vinzons.gov.ph" required value="<?= isset($_POST['email']) ? h($_POST['email']) : '' ?>">
                            </div>
                        </div>

                        <div class="form-actions" style="justify-content: flex-end;">
                            <button type="button" class="btn-main" onclick="goToStep(2)">Next: Account Security <i class="ph-bold ph-arrow-right" style="margin-left: 6px;"></i></button>
                        </div>
                    </div>

                    <div class="form-step" id="step2">
                        
                        <div class="step2-landscape">
                            <div class="step2-left">
                                <div class="profile-preview">
                                    <i class="ph-fill ph-image" id="iconPlaceholder"></i>
                                    <img id="previewImg" src="#" alt="Preview">
                                </div>
                                <label for="profile_pic" class="upload-btn-label">
                                    <i class="ph-bold ph-upload-simple"></i> Upload Photo
                                </label>
                                <input type="file" name="profile_pic" id="profile_pic" accept="image/png, image/jpeg" style="display: none;">
                            </div>

                            <div class="step2-right">
                                <div style="margin-bottom: 8px;">
                                    <h3 style="color:var(--green-dark); font-weight: 800; font-size: 18px; font-family: 'Poppins', sans-serif;">Set Access Password</h3>
                                    <p style="font-size: 13px; color: var(--muted); font-weight: 500; font-family: 'Poppins', sans-serif;">Create a secure password for this staff member's login.</p>
                                </div>

                                <div class="form-group">
                                    <label for="password">Account Password <span style="color:#e11d48;">*</span></label>
                                    <div class="password-wrap">
                                        <input type="password" id="password" name="password" placeholder="Create a strong password" required minlength="6">
                                        <button type="button" class="toggle-pass" data-target="password" aria-label="Show password">
                                            <i class="ph-bold ph-eye-slash"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="confirm_password">Confirm Password <span style="color:#e11d48;">*</span></label>
                                    <div class="password-wrap">
                                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Retype password" required minlength="6">
                                        <button type="button" class="toggle-pass" data-target="confirm_password" aria-label="Show password">
                                            <i class="ph-bold ph-eye-slash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn-light" onclick="goToStep(1)"><i class="ph-bold ph-arrow-left" style="margin-right: 6px;"></i> Back to Profile</button>
                            <button type="submit" class="btn-main"><i class="ph-bold ph-check-circle" style="margin-right: 6px;"></i> Complete Registration</button>
                        </div>
                    </div>

                </form>
            </div>

        </div>
    </main>

</div>

<script>
    function goToStep(step) {
        const errorBox = document.getElementById('jsError');
        const errorText = document.getElementById('jsErrorText');
        errorBox.style.display = 'none';

        if(step === 2) {
            const fname = document.getElementById('first_name').value.trim();
            const lname = document.getElementById('last_name').value.trim();
            const email = document.getElementById('email').value.trim();
            
            if(!fname || !lname || !email) {
                errorText.textContent = "Please fill in all required fields (marked with *).";
                errorBox.style.display = 'flex';
                return;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if(!emailRegex.test(email)) {
                errorText.textContent = "Please enter a valid email address format.";
                errorBox.style.display = 'flex';
                return;
            }
        }

        document.querySelectorAll('.form-step').forEach(el => el.classList.remove('active'));
        document.getElementById('step' + step).classList.add('active');

        if(step === 1) {
            document.getElementById('tracker1').classList.add('active');
            document.getElementById('tracker2').classList.remove('active');
            document.getElementById('tracker-connector').classList.remove('active');
        } else if (step === 2) {
            document.getElementById('tracker1').classList.add('active');
            document.getElementById('tracker2').classList.add('active');
            document.getElementById('tracker-connector').classList.add('active');
        }
    }

    const profileInput = document.getElementById('profile_pic');
    const previewImg = document.getElementById('previewImg');
    const iconPlaceholder = document.getElementById('iconPlaceholder');

    profileInput.addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) { 
                previewImg.setAttribute('src', e.target.result); 
                previewImg.style.display = 'block';
                iconPlaceholder.style.display = 'none';
                document.querySelector('.profile-preview').style.border = 'none';
            }
            reader.readAsDataURL(file);
        }
    });

    document.querySelectorAll(".toggle-pass").forEach(btn => {
        btn.addEventListener("click", () => {
            const target = document.getElementById(btn.dataset.target);
            const icon = btn.querySelector('i');
            
            if (target.type === "password") {
                target.type = "text";
                icon.classList.remove('ph-eye-slash');
                icon.classList.add('ph-eye');
                btn.style.color = "var(--green)";
            } else {
                target.type = "password";
                icon.classList.remove('ph-eye');
                icon.classList.add('ph-eye-slash');
                btn.style.color = "#9ab0a3";
            }
        });
    });

    document.addEventListener('DOMContentLoaded', () => {
        const menuToggle = document.getElementById('menuToggle');
        const sideArea = document.getElementById('sideArea');
        const sideClose = document.getElementById('sideClose');
        const overlay = document.getElementById('sidebarOverlay');

        if(menuToggle) menuToggle.addEventListener('click', () => { sideArea.classList.add('open'); overlay.classList.add('show'); });
        if(sideClose) sideClose.addEventListener('click', () => { sideArea.classList.remove('open'); overlay.classList.remove('show'); });
        if(overlay) overlay.addEventListener('click', () => { sideArea.classList.remove('open'); overlay.classList.remove('show'); });
    });
</script>

</body>
</html>
