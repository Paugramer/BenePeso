<?php
session_start();
require "db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

function h($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }

$flash_msg = $_SESSION["flash"] ?? "";
unset($_SESSION["flash"]);

$user_id = (int)$_SESSION["user_id"];

$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$fn = trim($user['first_name'] ?? "");
$mn = trim($user['middle_name'] ?? "");
$ln = trim($user['last_name'] ?? "");
$ex = trim($user['ext_name'] ?? "");
$user_display_name = trim($fn . ($mn ? " " . substr($mn, 0, 1) . "." : "") . " " . $ln . ($ex ? " " . $ex : ""));
$user_display_name = !empty($user_display_name) ? $user_display_name : "User";

$basic_name = trim($fn . " " . $ln);

$profile_pic = !empty($user['profile_pic']) ? "uploads/" . htmlspecialchars($user['profile_pic']) : "img/default_user.png";
$first_char = !empty($fn) ? strtoupper(substr($fn, 0, 1)) : "U";

$prog_stmt = $conn->prepare("
    SELECT b.beneficiary_id, p.program_name, p.requirements, p.venue, 
           b.availment_status, b.approval_status, b.approval_note, b.created_at 
    FROM beneficiaries b 
    JOIN programs p ON b.program_id = p.program_id 
    WHERE b.email = ? 
    ORDER BY b.created_at DESC
");
$prog_stmt->bind_param("s", $user['email']);
$prog_stmt->execute();
$availed_programs_result = $prog_stmt->get_result();

$availed_programs = [];
while ($row = $availed_programs_result->fetch_assoc()) {
    $availed_programs[] = $row;
}

// STRICT UNIFIED LOG FILTER (Registered User Only)
$log_stmt = $conn->prepare("
    SELECT action_type, module_name, description, created_at 
    FROM activity_logs 
    WHERE (actor_name = ? OR actor_name = ?) AND actor_role = 'Registered User'
    ORDER BY created_at DESC 
");
$log_stmt->bind_param("ss", $user_display_name, $basic_name);
$log_stmt->execute();
$activity_logs_result = $log_stmt->get_result();

$activity_logs = [];
while ($row = $activity_logs_result->fetch_assoc()) {
    $activity_logs[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/pesologo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | BENEPESO</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="home.css">
    <style>
        .status-card-horizontal {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            border: 1px solid #e1ebe5;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .status-card-horizontal:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(47, 107, 79, 0.08);
            border-color: #2f6b4f;
        }
        .card-left { display: flex; align-items: center; gap: 15px; }
        .program-icon {
            background: #e8f5e9;
            color: #2f6b4f;
            padding: 12px;
            border-radius: 12px;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card-left h4 { margin: 0 0 4px 0; color: #1f4d38; font-weight: 800; font-size: 16px; }
        .date-applied { font-size: 12.5px; color: #5e6f66; font-weight: 500; }
        
        .card-right { display: flex; align-items: center; gap: 20px; }
        .status-pill {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pill.approved { background: #e8f5e9; color: #2e7d32; }
        .status-pill.pending { background: #fff8e1; color: #f57f17; }
        .status-pill.rejected { background: #ffebee; color: #c62828; }
        
        .btn-check-status {
            background: #e8f5e9;
            color: #2f6b4f;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            pointer-events: none; 
        }
        .status-card-horizontal:hover .btn-check-status {
            background: #2f6b4f;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(47, 107, 79, 0.2);
        }

        .btn-spes-form {
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 38px; padding: 9px 16px; border: 1px solid #bfd9cd;
            border-radius: 8px; background: #fff; color: #1f7a55;
            font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap;
        }
        .btn-spes-form:hover { background: #eef8f3; border-color: #1f7a55; }

        .pagination-controls {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #f0f4f2;
        }
        .pagination-btn {
            background: #f0f4f2;
            color: #1f4d38;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .pagination-btn:hover:not(:disabled) {
            background: #2f6b4f;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(47, 107, 79, 0.15);
        }
        .pagination-btn:disabled {
            background: #f5f8f6;
            color: #a0b0a6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .pagination-info {
            font-size: 13px;
            color: #5e6f66;
            font-weight: 600;
        }

        .modal { display: none; position: fixed; inset: 0; background: rgba(22, 53, 36, 0.5); backdrop-filter: blur(6px); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
        .modal.show { display: flex; animation: fadeIn 0.3s ease-out; }
        .modal-content { background: #fff; width: 100%; max-width: 450px; border-radius: 24px; padding: 35px; position: relative; box-shadow: 0 24px 60px rgba(0,0,0,0.15); }
        .modal-close { position: absolute; top: 20px; right: 20px; background: #f5f8f6; border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; font-weight: bold; color: #5e6f66; transition: 0.2s; display: flex; align-items: center; justify-content: center; }
        .modal-close:hover { background: #ffebee; color: #d32f2f; }
        .modal-icon { margin: 0 auto 20px; display: flex; justify-content: center; align-items: center; }
        .modal-icon svg { width: 80px !important; height: 80px !important; }
        .icon-success svg { stroke: #2e7d32; }
        .icon-danger svg { stroke: #c62828; }
        .icon-warning svg { stroke: #f57f17; }
        .alert-box { text-align: center; }
        
        /* PREMIUM LOG STYLES */
        .action-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: 0.05em; border: 1px solid transparent; white-space: nowrap; text-transform: uppercase; }
        .pill-dot { width: 6px; height: 6px; border-radius: 50%; }
        .pill-green { background: #e6f4ed; color: #1f7a54; border-color: rgba(31,122,84,0.2); }
        .pill-green .pill-dot { background: #1f7a54; }
        .pill-red { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .pill-red .pill-dot { background: #dc2626; }
        .pill-blue { background: #e0f2fe; color: #0284c7; border-color: #bae6fd; }
        .pill-blue .pill-dot { background: #0284c7; }
        .pill-gray { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }
        .pill-gray .pill-dot { background: #475569; }

        .log-card-premium { background: #fff; border: 1px solid #e1ebe5; border-radius: 16px; padding: 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; transition: 0.3s; }
        .log-card-premium:hover { box-shadow: 0 10px 30px rgba(47, 107, 79, 0.08); border-color: #1f7a54; transform: translateY(-2px); }
        .log-premium-left { display: flex; flex-direction: column; gap: 8px; }
        .log-premium-right { text-align: right; display: flex; flex-direction: column; gap: 4px; align-items: flex-end;}

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        @media(max-width: 768px) {
            .log-card-premium { flex-direction: column; align-items: flex-start; gap: 12px; }
            .log-premium-right { text-align: left; align-items: flex-start; }
        }
    </style>
    <link rel="stylesheet" href="profile.css?v=8">
    <link rel="stylesheet" href="frontend_polish.css?v=1">
    <script src="frontend_polish.js?v=1" defer></script>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <a class="brand-area" href="home.php">
      <img class="brand-logo" src="img/pesologo.png" alt="PESO Logo" onerror="this.style.display='none'">
      <div class="brand-name">
        <div class="brand-title">BENEPESO</div>
        <div class="brand-subtitle">PESO Vinzons</div>
      </div>
    </a>

    <button class="menu-button" id="menuButton" type="button" aria-label="Toggle menu">
      <span></span><span></span><span></span>
    </button>

    <nav class="menu-area" id="menuArea">
      <a class="menu-item" href="home.php">Home</a>
      <a class="menu-item" href="programs.php">Programs</a>
      <a class="menu-item" href="about.php">About</a>

      <div class="account-area" id="accountWrap">
        <button class="account-button active" id="accountButton" type="button">
          <span class="account-icon"><?php echo htmlspecialchars($first_char); ?></span>
          <span class="account-text"><?php echo htmlspecialchars($user_display_name); ?></span>
          <span class="account-arrow">▾</span>
        </button>

        <div class="account-dropdown" id="accountDropdown">
          <a href="profile.php">My Profile</a>
          <a href="verification.php">Verification</a>
          <div class="dropdown-line"></div>
          <a class="logout-link" href="logout.php?role=user">Logout</a>
        </div>
      </div>
    </nav>
  </div>
</header>

<main class="profile-container content-wrap page-wrap">
    
    <section class="landscape-id-card stagger-1">
        <div class="id-card-inner">
            <div class="id-avatar-wrapper">
                <form id="avatarForm" action="update_avatar.php" method="POST" enctype="multipart/form-data">
                    <div class="id-avatar">
                        <img id="profileImagePreview" src="<?= $profile_pic ?>" alt="Profile Picture" onerror="this.src='img/default_user.png'">
                        <label for="avatarUpload" class="avatar-edit-overlay">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                        </label>
                        <input type="file" id="avatarUpload" name="new_avatar" accept="image/*" style="display:none;" onchange="previewAndSubmitAvatar(this)">
                    </div>
                </form>
            </div>
            
            <div class="id-details">
                <div class="id-badges">
                    <span class="badge-role">Beneficiary</span>
                    <span class="badge-status">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Verified Profile
                    </span>
                </div>
                <h1 class="id-name"><?= htmlspecialchars($user_display_name) ?></h1>
                <div class="id-meta">
                    <span class="meta-item">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        <?= htmlspecialchars($user['email']) ?>
                    </span>
                    <span class="meta-item">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        <?= htmlspecialchars($user['barangay'] . ', ' . ($user['municipality'] ?: 'Vinzons')) ?>
                    </span>
                </div>
            </div>
        </div>
    </section>

    <nav class="profile-tabs-nav stagger-2">
        <button class="tab-link active" onclick="switchTab(event, 'personal-info')">Personal Details</button>
        <button class="tab-link" onclick="switchTab(event, 'my-programs')">Availed Programs</button>
        <button class="tab-link" onclick="switchTab(event, 'activity-log')">Activity Logs</button>
        <button class="tab-link" onclick="switchTab(event, 'security')">Security</button>
    </nav>

    <section class="profile-main stagger-3">
        
        <div id="personal-info" class="tab-content active">
            <div class="content-header">
                <div>
                    <h3>Personal Information</h3>
                    <p>Manage and update your registered details below.</p>
                </div>
                <button type="button" class="btn-edit" id="editToggle" onclick="toggleEdit()">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    Edit Details
                </button>
            </div>
            
            <form id="profileForm" action="update_profile_process.php" method="POST">
                <div class="info-grid">
                    <div class="section-divider">Name Information</div>
                    <div class="info-group">
                        <label for="profileFirstName">First Name</label>
                        <input type="text" id="profileFirstName" name="first_name" value="<?= h($user['first_name']) ?>" maxlength="50" autocomplete="given-name" required readonly class="form-input">
                    </div>
                    <div class="info-group">
                        <label for="profileMiddleName">Middle Name</label>
                        <input type="text" id="profileMiddleName" name="middle_name" value="<?= h($user['middle_name']) ?>" maxlength="50" autocomplete="additional-name" readonly class="form-input">
                    </div>
                    <div class="info-group">
                        <label for="profileLastName">Last Name</label>
                        <input type="text" id="profileLastName" name="last_name" value="<?= h($user['last_name']) ?>" maxlength="50" autocomplete="family-name" required readonly class="form-input">
                    </div>
                    <div class="info-group">
                        <label for="profileExtensionName">Extension Name</label>
                        <input type="text" id="profileExtensionName" name="ext_name" value="<?= h($user['ext_name']) ?>" maxlength="10" autocomplete="honorific-suffix" readonly class="form-input">
                    </div>

                    <div class="section-divider">Identity & Contact</div>
                    <div class="info-group">
                        <label for="profileBirthdate">Birth Date</label>
                        <input type="date" id="profileBirthdate" name="birthdate" value="<?= h($user['birthdate']) ?>" max="<?= date('Y-m-d') ?>" autocomplete="bday" required readonly class="form-input">
                    </div>
                    <div class="info-group">
                        <label for="profileSex">Sex</label>
                        <select id="profileSex" name="sex" required disabled class="form-input">
                            <option value="Male" <?= $user['sex'] == 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= $user['sex'] == 'Female' ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>
                    <div class="info-group">
                        <label for="profileCivilStatus">Civil Status</label>
                        <select id="profileCivilStatus" name="civil_status" required disabled class="form-input">
                            <option value="Single" <?= $user['civil_status'] == 'Single' ? 'selected' : '' ?>>Single</option>
                            <option value="Married" <?= $user['civil_status'] == 'Married' ? 'selected' : '' ?>>Married</option>
                            <option value="Widowed" <?= $user['civil_status'] == 'Widowed' ? 'selected' : '' ?>>Widowed</option>
                            <option value="Legally Separated" <?= $user['civil_status'] == 'Legally Separated' ? 'selected' : '' ?>>Legally Separated</option>
                        </select>
                    </div>
                    <div class="info-group">
                        <label for="profileContactNumber">Contact Number</label>
                        <input type="text" id="profileContactNumber" name="contact_no" value="<?= h($user['contact_no']) ?>" inputmode="numeric" autocomplete="tel" pattern="09[0-9]{9}" maxlength="11" data-numeric-only required readonly class="form-input" aria-describedby="contactNumberHint">
                        <small class="field-hint" id="contactNumberHint">Use an 11-digit Philippine mobile number beginning with 09.</small>
                    </div>

                    <div class="section-divider">Address Information</div>
                    <div class="info-group">
                        <label for="profileStreet">Street / Purok / Zone</label>
                        <input type="text" id="profileStreet" name="street_purok_zone" value="<?= h($user['street_purok_zone']) ?>" maxlength="100" autocomplete="address-line1" readonly class="form-input">
                    </div>
                    <div class="info-group">
                        <label for="profileBarangay">Barangay</label>
                        <select id="profileBarangay" name="barangay" required disabled class="form-input">
                            <?php 
                            $barangays = ["Aguit-It","Banocboc","Cagbalogo","Calangcawan Norte","Calangcawan Sur","Guinacutan","Mangcayo","Mangcawayan","Manlucugan","Matango","Napilihan","Pinagtigasan","Barangay I (Pob.)","Barangay II (Pob.)","Barangay III (Pob.)","Sabang","Santo Domingo","Singi","Sula"];
                            foreach($barangays as $b) {
                                $selected = ($user['barangay'] == $b) ? 'selected' : '';
                                echo "<option value=\"$b\" $selected>$b</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="info-group">
                        <label for="profileMunicipality">Municipality</label>
                        <input type="text" id="profileMunicipality" name="municipality" value="<?= h($user['municipality'] ?: 'Vinzons') ?>" readonly class="form-input disabled-input">
                    </div>
                    <div class="info-group">
                        <label for="profileDistrict">District / Province</label>
                        <input type="text" id="profileDistrict" name="district" value="<?= h($user['district'] ?: 'Camarines Norte') ?>" readonly class="form-input disabled-input">
                    </div>

                    <div class="info-group full-row">
                        <label for="profileEmail">Email Address</label>
                        <input type="email" id="profileEmail" name="email" value="<?= h($user['email']) ?>" maxlength="120" autocomplete="email" required readonly class="form-input">
                    </div>
                </div>

                <div id="saveAction" class="profile-save-actions" hidden>
                    <button type="button" class="btn-cancel" onclick="location.reload()">Cancel</button>
                    <button type="submit" class="btn-save">Save Changes</button>
                </div>
            </form>
        </div>

        <div id="my-programs" class="tab-content">
            <div class="content-header">
                <div>
                    <h3>Availed Programs</h3>
                    <p>Track the status of your PESO program applications.</p>
                </div>
                <div class="application-count" aria-label="Total program applications">
                    <strong><?= count($availed_programs) ?></strong>
                    <span><?= count($availed_programs) === 1 ? 'Application' : 'Applications' ?></span>
                </div>
            </div>
            
            <div class="results-grid" id="availedProgramsContainer"></div>
            
            <div class="pagination-controls" id="progPaginationControls" style="display: none;">
                <button class="pagination-btn" id="progPrevBtn" onclick="changeProgPage(-1)" disabled>← Previous</button>
                <span class="pagination-info" id="progPageInfo">Page 1 of 1</span>
                <button class="pagination-btn" id="progNextBtn" onclick="changeProgPage(1)" disabled>Next →</button>
            </div>
            
            <?php if (count($availed_programs) == 0): ?>
                <div class="no-results-card programs-empty-state">
                    <div class="no-results-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M3 7h6l2 2h10v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"></path><path d="M3 7V5a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v2"></path></svg>
                    </div>
                    <h4>No Programs Availed Yet</h4>
                    <p>You have not applied for a PESO program yet. Explore the current opportunities to get started.</p>
                    <a class="empty-program-link" href="programs.php">Explore Programs</a>
                </div>
            <?php endif; ?>
        </div>

        <div id="activity-log" class="tab-content">
            <div class="content-header">
                <div>
                    <h3>System Activity</h3>
                    <p>A secure log of your recent interactions.</p>
                </div>
                <div class="activity-count" aria-label="Total recorded activities">
                    <strong><?= count($activity_logs) ?></strong>
                    <span><?= count($activity_logs) === 1 ? 'Activity' : 'Activities' ?></span>
                </div>
            </div>
            
            <div class="log-timeline" id="activityLogContainer"></div>

            <div class="pagination-controls" id="logPaginationControls" style="display: none;">
                <button class="pagination-btn" id="logPrevBtn" onclick="changeLogPage(-1)" disabled>← Previous</button>
                <span class="pagination-info" id="logPageInfo">Page 1 of 1</span>
                <button class="pagination-btn" id="logNextBtn" onclick="changeLogPage(1)" disabled>Next →</button>
            </div>

            <?php if (count($activity_logs) == 0): ?>
                <div class="no-results-card activity-empty-state">
                    <div class="no-results-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7"></path><path d="M3 4v5h5"></path><path d="M12 7v5l3 2"></path></svg>
                    </div>
                    <h4>No Recent Activity</h4>
                    <p>Your recent account actions will appear here for reference.</p>
                </div>
            <?php endif; ?>
        </div>

        <div id="security" class="tab-content">
            <div class="content-header">
                <div>
                    <h3>Security Settings</h3>
                    <p>Update your password to keep your account safe.</p>
                </div>
                <div class="security-state" aria-label="Account security status">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 13c0 5-8 9-8 9s-8-4-8-9V5l8-3 8 3v8Z"></path><path d="m9 12 2 2 4-4"></path></svg>
                    <span>Protected Account</span>
                </div>
            </div>
            <div class="security-card">
                <div class="security-overview">
                    <div class="security-overview-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>
                    </div>
                    <span class="security-eyebrow">Password Protection</span>
                    <h4>Keep your account secure</h4>
                    <p>Use a strong password that is difficult to guess and unique to BENEPESO.</p>
                    <ul class="security-checklist">
                        <li><span>At least 8 characters</span></li>
                        <li><span>Mix letters, numbers, and symbols</span></li>
                        <li><span>Avoid personal information</span></li>
                    </ul>
                </div>
                <form id="securityForm" class="security-form-panel" action="update_password_process.php" method="POST">
                    <div class="security-form-heading">
                        <h4>Change Password</h4>
                        <p>Enter and confirm your new account password.</p>
                    </div>
                    <div class="info-group security-field">
                        <label for="newPass">New Password</label>
                        <div class="password-wrap">
                            <input type="password" name="new_pass" id="newPass" required minlength="8" autocomplete="new-password" class="form-input" placeholder="Enter new password" aria-describedby="passwordSecurityHint">
                            <button type="button" class="toggle-pass" data-target="newPass" aria-label="Show or hide new password">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 13.5 2.5 12S6.5 5 12 5Zm0 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8Z"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="info-group security-field">
                        <label for="confPass">Confirm New Password</label>
                        <div class="password-wrap">
                            <input type="password" name="confirm_new_pass" id="confPass" required minlength="8" autocomplete="new-password" class="form-input" placeholder="Retype new password">
                            <button type="button" class="toggle-pass" data-target="confPass" aria-label="Show or hide confirmed password">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 13.5 2.5 12S6.5 5 12 5Zm0 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8Z"/></svg>
                            </button>
                        </div>
                    </div>
                    <small class="security-hint" id="passwordSecurityHint">You will use this password the next time you sign in.</small>
                    <button type="submit" class="btn-security">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <span>Update Password</span>
                    </button>
                </form>
            </div>
        </div>

    </section>
</main>

<div id="confirmModal" class="modal-overlay modal">
    <div class="modal-content alert-box" style="padding-top: 40px; max-width: 450px; margin: 0 auto; top: 20%;">
        <div class="modal-icon" style="margin: 0 auto 20px; display: flex; justify-content: center; align-items: center; color: #1f4d38;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="60" height="60"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </div>
        <h2 id="modalTitle" style="margin-bottom:10px; font-weight:800; color: #1f4d38;">Confirm Action</h2>
        <p id="modalMessage" style="font-size:14px; color:#5e6f66; margin-bottom:25px; font-weight:500;">Are you sure you want to proceed with this update?</p>
        <div style="display: flex; gap: 10px; justify-content: center;">
            <button type="button" class="btn-cancel" onclick="closeModal('confirmModal')" style="border:none; padding:12px 24px; border-radius:10px; background:#f0f4f2; color:#5e6f66; font-weight:700; cursor:pointer; width: 100%;">Cancel</button>
            <button type="button" class="btn-save" id="modalConfirmBtn" style="border:none; padding:12px 24px; border-radius:10px; background:#2f6b4f; color:#fff; font-weight:700; cursor:pointer; width: 100%;">Yes, Proceed</button>
        </div>
    </div>
</div>

<div class="modal status-modal" id="statusModal" role="dialog" aria-modal="true" aria-labelledby="statusModalTitle" aria-hidden="true">
    <div class="modal-content status-modal-dialog">
        <button type="button" class="modal-close" onclick="closeModal('statusModal')" aria-label="Close status details">&times;</button>
        <div class="status-modal-header">
            <div class="modal-icon" id="statusIcon" aria-hidden="true"></div>
            <div class="status-modal-heading">
                <span class="status-modal-eyebrow">Application Status</span>
                <h2 id="statusModalTitle">Status</h2>
            </div>
        </div>
        <div id="statusModalBody" class="status-modal-body"></div>
        <button type="button" class="status-modal-action" onclick="closeModal('statusModal')">Done</button>
    </div>
</div>

<div id="toastNotification" class="toast-notification">
    <div class="toast-icon">
        <svg id="toastIconSvg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
    </div>
    <div class="toast-content">
        <h4 id="toastTitle">Notification</h4>
        <p id="toastMessage">Message goes here.</p>
    </div>
    <button class="toast-close" onclick="closeToast()">×</button>
</div>

<footer class="site-footer">
  <div class="content-wrap footer-grid">
    <div class="footer-brand">
      <img class="footer-logo" src="img/pesologo.png" alt="PESO Logo" onerror="this.style.display='none'">
      <div>
        <div class="footer-title">BENEPESO</div>
        <div class="footer-sub">PESO Vinzons • Beneficiary Profiling & Verification</div>
      </div>
    </div>

    <div class="footer-col">
      <div class="footer-head">Links</div>
      <a href="home.php">Home</a>
      <a href="programs.php">Programs</a>
      <a href="verification.php">Verification</a>
      <a href="profile.php">Profile</a>
      <a href="privacy_notice.php">Privacy Notice</a>
    </div>

    <div class="footer-col">
      <div class="footer-head">Office</div>
      <div class="footer-text">Municipality of Vinzons, Camarines Norte</div>
      <div class="footer-text">Public Employment Service Office (PESO)</div>
    </div>
  </div>

  <div class="content-wrap footer-bottom">
    <div>© <?php echo date("Y"); ?> BENEPESO • PESO Vinzons</div>
    <div class="footer-mini">Republic of the Philippines • Province of Camarines Norte</div>
  </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('accountButton');
    const drop = document.getElementById('accountDropdown');
    
    if(btn && drop) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            drop.classList.toggle('show');
        });
        document.addEventListener('click', function(e) {
            if(!drop.contains(e.target) && !btn.contains(e.target)) {
                drop.classList.remove('show');
            }
        });
    }

    const menuBtn = document.getElementById('menuButton');
    const menuArea = document.getElementById('menuArea');
    if(menuBtn && menuArea) {
        menuBtn.addEventListener('click', function() {
            menuArea.classList.toggle('open');
        });
    }

    document.querySelectorAll(".toggle-pass").forEach(btn => {
        btn.addEventListener("click", () => {
            const target = document.getElementById(btn.dataset.target);
            if (!target) return;
            target.type = target.type === "password" ? "text" : "password";
            btn.style.color = target.type === "text" ? "var(--green)" : "#9ab0a3";
        });
    });

    document.querySelectorAll('[data-numeric-only]').forEach(input => {
        input.addEventListener('input', function() {
            const maxLength = Number(this.maxLength) > 0 ? Number(this.maxLength) : 255;
            this.value = this.value.replace(/\D/g, '').slice(0, maxLength);
        });
    });
});

function switchTab(evt, tabName) {
    const tabcontent = document.getElementsByClassName("tab-content");
    for (let i = 0; i < tabcontent.length; i++) {
        tabcontent[i].classList.remove("active");
    }
    const tablinks = document.getElementsByClassName("tab-link");
    for (let i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
    }
    document.getElementById(tabName).classList.add("active");
    evt.currentTarget.classList.add("active");
}

function toggleEdit() {
    const inputs = document.querySelectorAll('#profileForm input:not(.disabled-input), #profileForm select:not(.disabled-input)');
    const saveAction = document.getElementById('saveAction');
    const editBtn = document.getElementById('editToggle');
    
    inputs.forEach(input => {
        if (input.tagName === 'SELECT') {
            input.disabled = !input.disabled;
        } else {
            input.readOnly = !input.readOnly;
        }
        input.classList.toggle('editing');
    });
    
    saveAction.hidden = false;
    editBtn.hidden = true;
    document.getElementById('profileFirstName').focus();
}

function previewAndSubmitAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('profileImagePreview').src = e.target.result;
        }
        reader.readAsDataURL(input.files[0]);
        setTimeout(() => { document.getElementById('avatarForm').submit(); }, 500);
    }
}

function showToast(title, message, type = 'error') {
    const toast = document.getElementById('toastNotification');
    document.getElementById('toastTitle').innerText = title;
    document.getElementById('toastMessage').innerText = message;
    
    if(type === 'success') {
        toast.className = 'toast-notification success show';
        document.getElementById('toastIconSvg').innerHTML = '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>';
    } else {
        toast.className = 'toast-notification error show';
        document.getElementById('toastIconSvg').innerHTML = '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>';
    }
    setTimeout(() => closeToast(), 5000);
}
function closeToast() { document.getElementById('toastNotification').classList.remove('show'); }

<?php if(!empty($flash_msg)): ?>
    <?php $type = (strpos(strtolower($flash_msg), 'error') !== false || strpos(strtolower($flash_msg), 'failed') !== false) ? 'error' : 'success'; ?>
    showToast("<?= $type == 'error' ? 'Notification' : 'Success' ?>", "<?= addslashes($flash_msg) ?>", "<?= $type ?>");
<?php endif; ?>

let currentFormToSubmit = null;
function openModal(title, message, formId) {
    document.getElementById('modalTitle').innerText = title;
    document.getElementById('modalMessage').innerText = message;
    currentFormToSubmit = document.getElementById(formId);
    document.getElementById('confirmModal').classList.add('show');
}
function closeModal(modalId) {
    const modal = document.getElementById(modalId || 'confirmModal');
    modal.classList.remove('show');
    if (modal.hasAttribute('aria-hidden')) modal.setAttribute('aria-hidden', 'true');
    currentFormToSubmit = null;
}
document.getElementById('modalConfirmBtn').addEventListener('click', function() {
    if (currentFormToSubmit) currentFormToSubmit.submit();
});
document.getElementById('profileForm').addEventListener('submit', function(e) {
    e.preventDefault();

    if (!this.checkValidity()) {
        this.reportValidity();
        return;
    }

    const contact = document.getElementById('profileContactNumber').value;
    if (!/^09\d{9}$/.test(contact)) {
        showToast('Invalid Contact Number', 'Enter an 11-digit mobile number beginning with 09.', 'error');
        document.getElementById('profileContactNumber').focus();
        return;
    }

    openModal('Save Profile Changes?', 'Confirm these changes to update your account and official beneficiary records.', 'profileForm');
});
document.getElementById('securityForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const pass1 = document.getElementById('newPass').value;
    const pass2 = document.getElementById('confPass').value;
    if(pass1 !== pass2) { showToast("Password Mismatch", "Your new passwords do not match. Please retype them carefully.", "error"); return; }
    if(pass1.length < 8) { showToast("Security Warning", "Your password must be at least 8 characters long for safety.", "error"); return; }
    openModal('Update Password?', 'This will securely change your account password. You will be required to use this new password on your next login.', 'securityForm');
});

const allPrograms = <?php 
    $formatted_programs = array_map(function($p) {
        $p['formatted_date'] = date("M d, Y", strtotime($p['created_at']));
        $p['approval_status_clean'] = strtolower($p['approval_status'] ?? 'pending');
        $p['approval_note'] = $p['approval_note'] ?? 'No specific reason provided.';
        $p['requirements'] = $p['requirements'] ?? 'Please visit the main office for document requirements.';
        $p['venue'] = $p['venue'] ?? 'PESO Main Office';
        return $p;
    }, $availed_programs);
    echo json_encode($formatted_programs); 
?>;

const progItemsPerPage = 5;
let currentProgPage = 1;
const totalProgPages = Math.ceil(allPrograms.length / progItemsPerPage);

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#039;',
        '"': '&quot;'
    })[character]);
}

function getStatusKey(status) {
    const normalized = String(status || '').toLowerCase();
    return normalized === 'approved' || normalized === 'rejected' ? normalized : 'pending';
}

function getStatusLabel(status) {
    return status.charAt(0).toUpperCase() + status.slice(1);
}

function formatRequirements(requirements) {
    const items = String(requirements || '')
        .split(/\r?\n/)
        .map(item => item.replace(/^[-\u2022]\s*/, '').trim())
        .filter(Boolean);

    return items.map(item => `
        <div class="status-requirement-item">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"></path></svg>
            <span>${escapeHtml(item)}</span>
        </div>
    `).join('');
}

function renderPrograms() {
    const container = document.getElementById('availedProgramsContainer');
    const controls = document.getElementById('progPaginationControls');
    if (allPrograms.length === 0) return; 

    if (allPrograms.length > progItemsPerPage) { controls.style.display = 'flex'; }

    container.innerHTML = '';
    const startIdx = (currentProgPage - 1) * progItemsPerPage;
    const endIdx = startIdx + progItemsPerPage;
    const pageItems = allPrograms.slice(startIdx, endIdx);

    pageItems.forEach(item => {
        const statusKey = getStatusKey(item.approval_status_clean);
        const safeTitle = escapeHtml(item.program_name);
        const safeReason = escapeHtml(item.approval_note);
        const safeReqs = escapeHtml(item.requirements);
        const safeVenue = escapeHtml(item.venue);
        const safeDate = escapeHtml(item.formatted_date);
        const spesFormAction = String(item.program_name || '').trim().toUpperCase() === 'SPES'
            ? `<button type="button" class="btn-spes-form" onclick="event.stopPropagation(); openSpesForm(${encodeURIComponent(item.beneficiary_id)})">SPES Form</button>`
            : '';
        const msmeFormAction = String(item.program_name || '').trim().toUpperCase().includes('MSME')
            ? `<button type="button" class="btn-spes-form" onclick="event.stopPropagation(); openMsmeForm(${encodeURIComponent(item.beneficiary_id)})">MSME Form</button>`
            : '';
        const html = `
            <div class="status-card-horizontal" 
                 data-status="${statusKey}"
                 data-title="${safeTitle}"
                 data-reason="${safeReason}"
                 data-reqs="${safeReqs}"
                 data-venue="${safeVenue}"
                 onclick="handleCardClick(this)">
                  
                <div class="card-left">
                    <div class="program-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M9 5h6"></path><path d="M9 9h6"></path><path d="M9 13h4"></path><path d="M7 3h10a2 2 0 0 1 2 2v16H5V5a2 2 0 0 1 2-2Z"></path></svg>
                    </div>
                    <div class="program-copy">
                        <span class="program-kicker">PESO Program</span>
                        <h4>${safeTitle}</h4>
                        <span class="date-applied">Applied on ${safeDate}</span>
                    </div>
                </div>
                <div class="card-right">
                    <div class="status-summary">
                        <span class="status-caption">Approval Status</span>
                        <span class="status-pill ${statusKey}"><span class="status-dot"></span>${getStatusLabel(statusKey)}</span>
                    </div>
                    ${spesFormAction}
                    ${msmeFormAction}
                    <button type="button" class="btn-check-status" onclick="event.stopPropagation(); handleCardClick(this.closest('.status-card-horizontal'))">
                        <span>View details</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg>
                    </button>
                </div>
            </div>
        `;
        container.innerHTML += html;
    });

    document.getElementById('progPageInfo').innerText = `Page ${currentProgPage} of ${totalProgPages}`;
    document.getElementById('progPrevBtn').disabled = currentProgPage === 1;
    document.getElementById('progNextBtn').disabled = currentProgPage === totalProgPages;
}

function changeProgPage(direction) {
    currentProgPage += direction;
    renderPrograms();
}

const allLogs = <?php 
    $formatted_logs = array_map(function($l) {
        $l['formatted_date'] = date("M d, Y • h:i A", strtotime($l['created_at']));
        return $l;
    }, $activity_logs);
    echo json_encode($formatted_logs); 
?>;

const logItemsPerPage = 5; 
let currentLogPage = 1;
const totalLogPages = Math.ceil(allLogs.length / logItemsPerPage);

function renderLogs() {
    const container = document.getElementById('activityLogContainer');
    const controls = document.getElementById('logPaginationControls');
    if (allLogs.length === 0) return; 

    if (allLogs.length > logItemsPerPage) { controls.style.display = 'flex'; }

    container.innerHTML = '';
    const startIdx = (currentLogPage - 1) * logItemsPerPage;
    const endIdx = startIdx + logItemsPerPage;
    const pageItems = allLogs.slice(startIdx, endIdx);

    pageItems.forEach(log => {
        let actionTitle = log.action_type ? log.action_type.toUpperCase() : 'LOG';
        let pillClass = 'pill-gray';
        
        if (actionTitle === 'APPLY' || actionTitle === 'LOGIN') pillClass = 'pill-green';
        else if (actionTitle === 'LOGOUT') pillClass = 'pill-red';
        else if (actionTitle === 'VIEW') pillClass = 'pill-blue';

        const timeParts = log.formatted_date.split(' • ');
        const dateStr = escapeHtml(timeParts[0]);
        const timeStr = escapeHtml(timeParts[1] || '');
        const safeAction = escapeHtml(actionTitle);
        const safeModule = escapeHtml(log.module_name || 'Account');
        const safeDescription = escapeHtml(log.description || 'Account activity recorded.');

        const html = `
            <div class="log-card-premium" data-action="${safeAction.toLowerCase()}">
                <div class="log-event-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7"></path><path d="M3 4v5h5"></path><path d="M12 7v5l3 2"></path></svg>
                </div>
                <div class="log-event-content">
                    <div class="log-event-heading">
                        <span class="action-pill ${pillClass}"><span class="pill-dot"></span>${safeAction}</span>
                        <span class="log-module-name">${safeModule}</span>
                    </div>
                    <p class="log-description">${safeDescription}</p>
                </div>
                <time class="log-event-time">
                    <strong>${dateStr}</strong>
                    <span>${timeStr}</span>
                </time>
            </div>
        `;
        container.innerHTML += html;
    });

    document.getElementById('logPageInfo').innerText = `Page ${currentLogPage} of ${totalLogPages}`;
    document.getElementById('logPrevBtn').disabled = currentLogPage === 1;
    document.getElementById('logNextBtn').disabled = currentLogPage === totalLogPages;
}

function changeLogPage(direction) {
    currentLogPage += direction;
    renderLogs();
}

document.addEventListener('DOMContentLoaded', function() {
    renderPrograms();
    renderLogs();
});

function handleCardClick(element) {
    const status = getStatusKey(element.getAttribute('data-status'));
    const title = element.getAttribute('data-title');
    const reason = element.getAttribute('data-reason');
    const reqs = element.getAttribute('data-reqs');
    const venue = element.getAttribute('data-venue');
    
    const modal = document.getElementById('statusModal');
    const modalTitle = document.getElementById('statusModalTitle');
    const modalBody = document.getElementById('statusModalBody');
    const modalIcon = document.getElementById('statusIcon');

    modalIcon.className = "modal-icon";
    modal.dataset.status = status;

    if (status === 'approved') {
        modalIcon.classList.add("icon-success");
        modalIcon.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><path d="m22 4-10 10.01-3-3"></path></svg>`;
        modalTitle.innerText = "Application Approved";
        modalBody.innerHTML = `
            <p class="status-message">Your application for <strong>${escapeHtml(title)}</strong> has been approved.</p>
            <div class="status-detail-panel">
                <section class="status-detail-section">
                    <h4>Requirements to submit</h4>
                    <div class="status-requirement-list">${formatRequirements(reqs)}</div>
                </section>
                <section class="status-detail-section">
                    <h4>Submission venue</h4>
                    <div class="status-venue">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>
                        <span>${escapeHtml(venue)}</span>
                    </div>
                </section>
            </div>
            <div class="status-note">Bring the listed documents to the venue for the next verification step.</div>
        `;
    } 
    else if (status === 'rejected') {
        modalIcon.classList.add("icon-danger");
        modalIcon.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="m15 9-6 6"></path><path d="m9 9 6 6"></path></svg>`;
        modalTitle.innerText = "Application Not Approved";
        modalBody.innerHTML = `
            <p class="status-message">Your application for <strong>${escapeHtml(title)}</strong> was not approved.</p>
            <div class="status-detail-panel">
                <section class="status-detail-section">
                    <h4>Review note</h4>
                    <p class="status-reason">${escapeHtml(reason)}</p>
                </section>
            </div>
            <div class="status-note">Contact PESO Vinzons if you need clarification about this review decision.</div>
        `;
    } 
    else {
        modalIcon.classList.add("icon-warning");
        modalIcon.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>`;
        modalTitle.innerText = "Application Under Review";
        modalBody.innerHTML = `
            <p class="status-message">Your application for <strong>${escapeHtml(title)}</strong> is being reviewed by the PESO Staff.</p>
            <div class="status-detail-panel">
                <section class="status-detail-section">
                    <h4>What happens next</h4>
                    <p class="status-reason">The Staff will verify your information and update this page once a decision has been recorded.</p>
                </section>
            </div>
            <div class="status-note">Keep your registered email active for official updates.</div>
        `;
    }

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
}
</script>
<script src="spes_form_modal.js?v=20260813y"></script>
<script src="msme_form_modal.js?v=20260820y"></script>
</body>
</html>
