<?php
require_once __DIR__ . '/auth.php';
require "db.php";
require_once __DIR__ . '/user_security_metadata_helper.php';
require_once __DIR__ . '/spes_lifecycle_helper.php';
require_once __DIR__ . '/activity_log_helper.php';

check_user_role('user');

function h($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }

$flash_msg = $_SESSION["flash"] ?? "";
unset($_SESSION["flash"]);

$user_id = (int)$_SESSION["user_id"];

$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$security_metadata = fetch_user_security_metadata($conn, $user_id);
$last_successful_login = null;
$login_stmt = $conn->prepare("SELECT login_time FROM login_history WHERE user_id = ? AND status = 'success' ORDER BY login_time DESC LIMIT 1");
if ($login_stmt) {
    $login_stmt->bind_param('i', $user_id);
    $login_stmt->execute();
    $last_successful_login = $login_stmt->get_result()->fetch_assoc()['login_time'] ?? null;
    $login_stmt->close();
}

$profile_required_fields = [
    'first_name', 'last_name', 'birthdate', 'sex', 'civil_status',
    'contact_no', 'street_purok_zone', 'barangay', 'email'
];
$profile_completed_fields = 0;
$profile_missing_fields = [];
$profile_field_labels = [
    'first_name' => 'First name', 'last_name' => 'Last name', 'birthdate' => 'Date of birth',
    'sex' => 'Sex', 'civil_status' => 'Civil status', 'contact_no' => 'Contact number',
    'street_purok_zone' => 'Street / Purok / Zone', 'barangay' => 'Barangay', 'email' => 'Email address',
];
foreach ($profile_required_fields as $profile_field) {
    if (trim((string)($user[$profile_field] ?? '')) !== '') {
        $profile_completed_fields++;
    } else {
        $profile_missing_fields[] = $profile_field_labels[$profile_field] ?? $profile_field;
    }
}
$profile_completion = (int)round(($profile_completed_fields / count($profile_required_fields)) * 100);
$profile_is_ready = $profile_completion === 100;
$spes_lifecycle = spes_user_summary($conn, $user_id, (string)($user['email'] ?? ''));
$spes_profile_label = $spes_lifecycle['classification'] === 'graduate' ? 'SPES Graduate' : ($spes_lifecycle['classification'] === 'baby' ? 'SPES Baby' : '');

$fn = trim($user['first_name'] ?? "");
$mn = trim($user['middle_name'] ?? "");
$ln = trim($user['last_name'] ?? "");
$ex = trim($user['ext_name'] ?? "");
$user_display_name = trim($fn . ($mn ? " " . substr($mn, 0, 1) . "." : "") . " " . $ln . ($ex ? " " . $ex : ""));
$user_display_name = !empty($user_display_name) ? $user_display_name : "User";

$basic_name = trim($fn . " " . $ln);

$profile_pic = !empty($user['profile_pic']) ? "uploads/" . htmlspecialchars($user['profile_pic']) : "img/default_user.svg";
$first_char = !empty($fn) ? strtoupper(substr($fn, 0, 1)) : "U";

$prog_stmt = $conn->prepare("
    SELECT b.beneficiary_id, p.program_id, p.program_code, p.program_name, p.requirements, p.venue,
           p.status AS program_status, p.start_date AS program_start_date, p.end_date AS program_end_date,
           b.availment_status, b.approval_status, b.approval_note,
           b.date_completed, b.date_availed, b.created_at, b.updated_at
    FROM beneficiaries b 
    JOIN programs p ON b.program_id = p.program_id 
    WHERE b.user_id = ? OR (b.user_id IS NULL AND b.email = ?)
    ORDER BY b.created_at DESC
");
$prog_stmt->bind_param("is", $user_id, $user['email']);
$prog_stmt->execute();
$availed_programs_result = $prog_stmt->get_result();

$availed_programs = [];
while ($row = $availed_programs_result->fetch_assoc()) {
    $availed_programs[] = $row;
}

// Activity history is account-owned. Names are display values and are not
// reliable authorization identifiers because multiple residents can share one.
$activity_log_owned = benepeso_activity_log_supports_user_id($conn);
$activity_where = $activity_log_owned
    ? "user_id = ? AND actor_role = 'Registered User'"
    : "actor_name IN (?, ?) AND actor_role = 'Registered User'";
$log_stmt = $conn->prepare(
    "SELECT action_type, module_name, description, created_at
     FROM activity_logs
     WHERE {$activity_where}
     ORDER BY created_at DESC"
);
if ($activity_log_owned) {
    $log_stmt->bind_param("i", $user_id);
} else {
    // Legacy deployments do not yet have activity_logs.user_id. Use the same
    // canonical names written by beneficiary authentication and program events.
    $log_stmt->bind_param("ss", $basic_name, $user_display_name);
}
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
    
    <link rel="stylesheet" href="home.css?v=17">
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
<link rel="stylesheet" href="profile.css?v=17">
    <link rel="stylesheet" href="spes_form_modal.css?v=20260904c">
    <link rel="stylesheet" href="frontend_polish.css?v=20260921">
    <link rel="stylesheet" href="beneficiary_responsive.css?v=10">
    <link rel="stylesheet" href="beneficiary_content_enhancements.css?v=1">
    <link rel="stylesheet" href="beneficiary_content_polish.css?v=9">
    <link rel="stylesheet" href="authenticated_experience.css?v=6">
    <link rel="stylesheet" href="beneficiary_mobile.css?v=14">
<script src="frontend_polish.js?v=20260921" defer></script>
    <script src="beneficiary_content_polish.js?v=1" defer></script>
</head>
<body class="beneficiary-profile-page">

<header class="topbar">
  <div class="topbar-inner">
    <a class="brand-area" href="home.php">
      <img class="brand-logo" src="img/pesologo.png" alt="PESO Logo" onerror="this.style.display='none'">
      <div class="brand-name">
        <div class="brand-title">BENEPESO</div>
        <div class="brand-subtitle">PESO Vinzons</div>
      </div>
    </a>

    <button class="menu-button" id="menuButton" type="button" aria-label="Toggle menu" aria-controls="menuArea" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>

    <nav class="menu-area" id="menuArea">
      <a class="menu-item" href="home.php">Home</a>
      <a class="menu-item" href="programs.php">Programs</a>
      <a class="menu-item" href="about.php">About</a>

      <div class="account-area" id="accountWrap">
        <button class="account-button active" id="accountButton" type="button">
          <span class="account-icon">
              <?php echo htmlspecialchars($first_char); ?>
              <img src="<?= $profile_pic ?>" alt="" onerror="this.remove()">
          </span>
          <span class="account-text"><?php echo htmlspecialchars($user_display_name); ?></span>
          <span class="account-arrow">▾</span>
        </button>

        <div class="account-dropdown" id="accountDropdown">
                    <a class="account-dropdown-link" href="profile.php" aria-current="page"><span class="account-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></span><span>My Profile</span></a>
                    <a class="account-dropdown-link" href="verification.php"><span class="account-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.6 3.1 7.9 7.5 9.5 4.4-1.6 7.5-4.9 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg></span><span>Verification</span></a>
          <div class="dropdown-line"></div>
          <form class="logout-form" action="logout.php" method="POST">
            <?= auth_csrf_input() ?><input type="hidden" name="role" value="user">
            <button class="logout-link" type="submit">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M15 4h3a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3h-3" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <span>Log out</span>
            </button>
          </form>
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
                    <?= auth_csrf_input() ?>
                    <div class="id-avatar">
                        <img id="profileImagePreview" src="<?= $profile_pic ?>" alt="Profile Picture" onerror="this.onerror=null;this.src='img/default_user.svg'">
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
                    <?php if ($spes_profile_label !== ''): ?>
                    <span class="badge-status spes-lifecycle-badge <?= $spes_lifecycle['classification'] === 'graduate' ? 'is-graduate' : 'is-baby' ?>">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 3l2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3z"/></svg>
                        <?= h($spes_profile_label) ?>
                    </span>
                    <?php endif; ?>
                    <span class="badge-status <?= $profile_is_ready ? 'profile-ready' : 'profile-incomplete' ?>">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <?= $profile_is_ready ? 'Profile Ready for Review' : 'Profile Needs Information' ?>
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

    <section class="profile-readiness-panel stagger-2 bp-content-module" aria-labelledby="profileReadinessTitle" style="--profile-completion: <?= $profile_completion ?>%">
        <div>
            <span class="content-enhancement-eyebrow">Application readiness</span>
            <h2 id="profileReadinessTitle"><?= $profile_completion ?>% profile complete</h2>
            <p><?= $profile_is_ready ? 'Your required profile fields are complete. PESO will still validate your information as part of each program application.' : 'Complete the missing required fields before applying so eligibility and household checks can use accurate information.' ?></p>
            <?php if (!$profile_is_ready): ?>
                <div class="profile-missing-fields" aria-label="Missing required profile information">
                    <strong>Still needed:</strong>
                    <?php foreach ($profile_missing_fields as $missing_field): ?><span><?= h($missing_field) ?></span><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="profile-readiness-progress" aria-label="Profile <?= $profile_completion ?> percent complete">
            <span style="width: <?= $profile_completion ?>%"></span>
        </div>
        <?php if (!$profile_is_ready): ?><button type="button" class="profile-readiness-action" onclick="document.getElementById('editToggle').click(); document.getElementById('personal-info').scrollIntoView({behavior:'smooth'})">Complete Profile</button><?php endif; ?>
    </section>

    <nav class="profile-tabs-nav stagger-2" role="tablist" aria-label="Profile sections">
        <button class="tab-link active" id="tab-personal-info" role="tab" aria-selected="true" aria-controls="personal-info" tabindex="0" onclick="switchTab(event, 'personal-info')"><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg><span>Personal Details</span></button>
        <button class="tab-link" id="tab-my-programs" role="tab" aria-selected="false" aria-controls="my-programs" tabindex="-1" onclick="switchTab(event, 'my-programs')"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M8 6V4h8v2M5 7h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"></path><path d="M3 12h18M10 12v2h4v-2"></path></svg><span>My Applications</span></button>
        <button class="tab-link" id="tab-activity-log" role="tab" aria-selected="false" aria-controls="activity-log" tabindex="-1" onclick="switchTab(event, 'activity-log')"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7"></path><path d="M3 4v5h5M12 7v5l3 2"></path></svg><span>Activity Logs</span></button>
        <button class="tab-link" id="tab-security" role="tab" aria-selected="false" aria-controls="security" tabindex="-1" onclick="switchTab(event, 'security')"><svg aria-hidden="true" viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"></path></svg><span>Security</span></button>
    </nav>

    <section class="profile-main stagger-3">
        
        <div id="personal-info" class="tab-content active" role="tabpanel" aria-labelledby="tab-personal-info">
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
            <div class="profile-record-note" role="note">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"></path><path d="M12 8v4M12 16h.01"></path></svg>
                <div><strong>Account details are not an approval badge.</strong><span>Saving updates keeps pending applications aligned with your profile. Approved identity and decision details remain unchanged; request a PESO correction when an official record is inaccurate.</span></div>
            </div>
            
            <form id="profileForm" action="update_profile_process.php" method="POST">
                <?= auth_csrf_input() ?>
                <div class="info-grid">
                    <div class="section-divider">Name Information</div>
                    <div class="info-group">
                        <label for="profileFirstName">First Name</label>
                        <input type="text" id="profileFirstName" name="first_name" value="<?= h($user['first_name']) ?>" maxlength="50" autocomplete="given-name" required readonly class="form-input">
                    </div>
                    <div class="info-group">
                        <label for="profileMiddleName">Middle Name (Optional)</label>
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
                        <label for="profileBirthdate">Date of Birth</label>
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
                            <option value="Legally Separated" <?= $user['civil_status'] == 'Legally Separated' ? 'selected' : '' ?>>Separated</option>
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

                    <div class="info-group full-row profile-email-field">
                        <label for="profileEmail">Email Address</label>
                        <input type="email" id="profileEmail" name="email" value="<?= h($user['email']) ?>" maxlength="120" autocomplete="email" required readonly class="form-input disabled-input" aria-describedby="profileEmailHint">
                        <small class="field-hint persistent-hint" id="profileEmailHint">For account security, request a verified correction before changing your registered email.</small>
                    </div>
                </div>

                <div id="saveAction" class="profile-save-actions" hidden>
                    <button type="button" class="btn-cancel" onclick="cancelProfileEdit()">Cancel</button>
                    <button type="submit" class="btn-save">Save Changes</button>
                </div>
            </form>
        </div>

        <div id="my-programs" class="tab-content" role="tabpanel" aria-labelledby="tab-my-programs" hidden>
            <div class="content-header">
                <div>
                    <h3>My Applications</h3>
                    <p>Track the status of your PESO program applications.</p>
                </div>
                <div class="application-count" aria-label="Total program applications">
                    <strong><?= count($availed_programs) ?></strong>
                    <span><?= count($availed_programs) === 1 ? 'Application' : 'Applications' ?></span>
                </div>
            </div>
            <?php if (count($availed_programs) > 0): ?>
                <div class="profile-filter-bar" role="group" aria-label="Filter applications">
                    <button type="button" class="profile-filter-chip active" data-program-filter="all" aria-pressed="true">All</button>
                    <button type="button" class="profile-filter-chip" data-program-filter="current" aria-pressed="false">Current</button>
                    <button type="button" class="profile-filter-chip" data-program-filter="pending" aria-pressed="false">Under review</button>
                    <button type="button" class="profile-filter-chip" data-program-filter="completed" aria-pressed="false">Completed</button>
                    <button type="button" class="profile-filter-chip" data-program-filter="rejected" aria-pressed="false">Not approved</button>
                </div>
            <?php endif; ?>

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
                    <h4>No Applications Yet</h4>
                    <p>You have not applied for a PESO program yet. Explore the current opportunities to get started.</p>
                    <a class="empty-program-link" href="programs.php">Explore Programs</a>
                </div>
            <?php endif; ?>
        </div>

        <div id="activity-log" class="tab-content" role="tabpanel" aria-labelledby="tab-activity-log" hidden>
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
            <?php if (count($activity_logs) > 0): ?>
                <div class="profile-filter-bar" role="group" aria-label="Filter activity">
                    <button type="button" class="profile-filter-chip active" data-log-filter="all" aria-pressed="true">All activity</button>
                    <button type="button" class="profile-filter-chip" data-log-filter="security" aria-pressed="false">Security</button>
                    <button type="button" class="profile-filter-chip" data-log-filter="application" aria-pressed="false">Applications</button>
                    <button type="button" class="profile-filter-chip" data-log-filter="profile" aria-pressed="false">Profile</button>
                </div>
            <?php endif; ?>

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

        <div id="security" class="tab-content" role="tabpanel" aria-labelledby="tab-security" hidden>
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
                        <li><span>At least 10 characters</span></li>
                        <li><span>Uppercase, lowercase, number, and symbol</span></li>
                        <li><span>Avoid personal information</span></li>
                    </ul>
                    <div class="security-history" aria-label="Recent account security activity">
                        <div><span>Last successful sign-in</span><strong><?= $last_successful_login ? h(date('M d, Y, g:i A', strtotime($last_successful_login))) : 'Not recorded' ?></strong></div>
                        <div><span>Password last changed</span><strong><?= !empty($security_metadata['password_changed_at']) ? h(date('M d, Y, g:i A', strtotime($security_metadata['password_changed_at']))) : 'Not recorded yet' ?></strong></div>
                    </div>
                </div>
                <form id="securityForm" class="security-form-panel" action="update_password_process.php" method="POST">
                    <?= auth_csrf_input() ?>
                    <div class="security-form-heading">
                        <h4>Change Password</h4>
                        <p>Verify your current password, then enter and confirm your new account password.</p>
                    </div>
                    <div class="info-group security-field">
                        <label for="currentPass">Current Password</label>
                        <div class="password-wrap">
                            <input type="password" name="current_pass" id="currentPass" required autocomplete="current-password" class="form-input" placeholder="Enter current password">
                            <button type="button" class="toggle-pass" data-target="currentPass" aria-label="Show current password">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 13.5 2.5 12S6.5 5 12 5Zm0 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8Z"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="info-group security-field">
                        <label for="newPass">New Password</label>
                        <div class="password-wrap">
                            <input type="password" name="new_pass" id="newPass" required minlength="10" autocomplete="new-password" class="form-input" placeholder="Enter new password" aria-describedby="passwordSecurityHint passwordStrengthText">
                            <button type="button" class="toggle-pass" data-target="newPass" aria-label="Show new password">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 13.5 2.5 12S6.5 5 12 5Zm0 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8Z"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="info-group security-field">
                        <label for="confPass">Confirm New Password</label>
                        <div class="password-wrap">
                            <input type="password" name="confirm_new_pass" id="confPass" required minlength="10" autocomplete="new-password" class="form-input" placeholder="Retype new password">
                            <button type="button" class="toggle-pass" data-target="confPass" aria-label="Show confirmed password">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5c5.5 0 9.5 5.5 9.5 7s-4 7-9.5 7S2.5 13.5 2.5 12S6.5 5 12 5Zm0 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8Z"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="password-strength" aria-live="polite">
                        <span class="password-strength-track"><span id="passwordStrengthBar"></span></span>
                        <small id="passwordStrengthText">Use 10+ characters with uppercase, lowercase, a number, and a symbol.</small>
                    </div>
                    <small class="security-hint" id="passwordSecurityHint">You will use this password the next time you sign in.</small>
                    <button type="submit" class="btn-security">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <span>Update Password</span>
                    </button>
                </form>
            </div>
            <div class="profile-data-rights bp-content-module">
                <div>
                    <span class="content-enhancement-eyebrow">Your information</span>
                    <h4>Corrections and privacy requests</h4>
                    <p>Contact PESO Vinzons if a locked identity field, application record, or verification result is inaccurate. You may also ask how your information is used and retained.</p>
                </div>
                <div class="profile-data-rights-actions">
                    <a href="privacy_notice.php">Read Privacy Notice</a>
                    <button type="button" onclick="openCorrectionModal()">Request a correction</button>
                </div>
            </div>
        </div>

    </section>
</main>

<div id="confirmModal" class="modal-overlay modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-describedby="modalMessage" aria-hidden="true">
    <div class="modal-content alert-box profile-confirm-dialog">
        <div class="modal-icon profile-confirm-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="60" height="60"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </div>
        <h2 id="modalTitle">Confirm Action</h2>
        <p id="modalMessage">Are you sure you want to proceed with this update?</p>
        <div class="profile-confirm-actions">
            <button type="button" class="btn-cancel" id="modalCancelBtn" onclick="closeModal('confirmModal')">Cancel</button>
            <button type="button" class="btn-save" id="modalConfirmBtn">Yes, Proceed</button>
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

<div class="modal status-modal profile-correction-modal" id="correctionModal" role="dialog" aria-modal="true" aria-labelledby="correctionModalTitle" aria-describedby="correctionModalDescription" aria-hidden="true">
    <div class="modal-content status-modal-dialog">
        <button type="button" class="modal-close" onclick="closeModal('correctionModal')" aria-label="Close correction guidance">&times;</button>
        <div class="status-modal-header">
            <div class="modal-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4L16.5 3.5Z"></path></svg>
            </div>
            <div class="status-modal-heading">
                <span class="status-modal-eyebrow">Official record support</span>
                <h2 id="correctionModalTitle">Request a correction</h2>
            </div>
        </div>
        <div class="status-modal-body correction-modal-body" id="correctionModalDescription">
            <p class="status-message">Use this service when an approved identity field, application status, batch record, or verification result is inaccurate.</p>
            <div class="correction-guidance">
                <section><span>1</span><div><strong>Identify the record</strong><small>Include the program, exact batch code, and the information that needs correction.</small></div></section>
                <section><span>2</span><div><strong>Protect your information</strong><small>Do not send passwords or unnecessary identity documents by public message.</small></div></section>
                <section><span>3</span><div><strong>Wait for PESO confirmation</strong><small>Your displayed record changes only after authorized staff review the request.</small></div></section>
            </div>
            <div class="correction-contact-card">
                <span>Official contact</span>
                <strong>lguvinzonspeso@gmail.com</strong>
                <small>+63 947 997 1186 &bull; Monday–Friday, 8:00 AM–5:00 PM</small>
            </div>
        </div>
        <div class="correction-modal-actions">
            <button type="button" class="correction-secondary" onclick="closeModal('correctionModal')">Not now</button>
            <a class="status-modal-action" href="https://mail.google.com/mail/?view=cm&amp;fs=1&amp;to=lguvinzonspeso@gmail.com&amp;su=BENEPESO%20Record%20Correction%20Request" target="_blank" rel="noopener noreferrer">Open Gmail</a>
        </div>
    </div>
</div>

<div id="toastNotification" class="toast-notification" role="status" aria-live="polite" aria-atomic="true">
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
      <a href="about.php">About</a>
      <a href="verification.php">Verification</a>
      <a href="profile.php">Profile</a>
      <a href="privacy_notice.php">Privacy Notice</a>
    </div>

    <div class="footer-col">
      <div class="footer-head">Office</div>
      <div class="footer-text">Municipality of Vinzons, Camarines Norte</div>
      <div class="footer-text">Public Employment Service Office (PESO)</div>
      <a class="footer-contact-link" href="https://mail.google.com/mail/?view=cm&amp;fs=1&amp;to=lguvinzonspeso@gmail.com" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-envelope" aria-hidden="true"></i><span>lguvinzonspeso@gmail.com</span></a>
      <a class="footer-contact-link" href="tel:+639479971186"><i class="fa-solid fa-phone" aria-hidden="true"></i><span>+63 947 997 1186</span></a>
      <a class="footer-contact-link" href="https://www.facebook.com/peso.vinzons" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-facebook" aria-hidden="true"></i><span>PESO Vinzons on Facebook</span></a>
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
            const isOpen = menuArea.classList.toggle('open');
            menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    document.querySelectorAll(".toggle-pass").forEach(btn => {
        btn.addEventListener("click", () => {
            const target = document.getElementById(btn.dataset.target);
            if (!target) return;
            target.type = target.type === "password" ? "text" : "password";
            btn.style.color = target.type === "text" ? "var(--green)" : "#9ab0a3";
            btn.setAttribute('aria-label', `${target.type === 'text' ? 'Hide' : 'Show'} ${target.id === 'currentPass' ? 'current' : target.id === 'newPass' ? 'new' : 'confirmed'} password`);
        });
    });

    document.getElementById('newPass')?.addEventListener('input', updatePasswordStrength);

    document.querySelectorAll('[data-program-filter]').forEach(button => {
        button.addEventListener('click', () => setProgramFilter(button.dataset.programFilter, button));
    });

    document.querySelectorAll('[data-log-filter]').forEach(button => {
        button.addEventListener('click', () => setLogFilter(button.dataset.logFilter, button));
    });

    document.querySelectorAll('.modal, .modal-overlay').forEach(modal => {
        modal.addEventListener('mousedown', event => {
            if (event.target === modal && modal.classList.contains('show')) closeModal(modal.id);
        });
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const openDialog = document.querySelector('.modal.show, .modal-overlay.show');
        if (openDialog) closeModal(openDialog.id);
    });

    document.querySelectorAll('[data-numeric-only]').forEach(input => {
        input.addEventListener('input', function() {
            const maxLength = Number(this.maxLength) > 0 ? Number(this.maxLength) : 255;
            this.value = this.value.replace(/\D/g, '').slice(0, maxLength);
        });
    });

    const profileTabs = Array.from(document.querySelectorAll('.profile-tabs-nav [role="tab"]'));
    profileTabs.forEach((tab, index) => {
        tab.addEventListener('keydown', function(event) {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            let nextIndex = index;
            if (event.key === 'ArrowRight') nextIndex = (index + 1) % profileTabs.length;
            if (event.key === 'ArrowLeft') nextIndex = (index - 1 + profileTabs.length) % profileTabs.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = profileTabs.length - 1;
            profileTabs[nextIndex].focus();
            profileTabs[nextIndex].click();
        });
    });

    document.getElementById('profileForm')?.addEventListener('submit', function() {
        profileHasUnsavedChanges = false;
    });

    document.querySelectorAll('#profileForm .form-input:not(.disabled-input)').forEach(field => {
        field.addEventListener('input', updateProfileDirtyState);
        field.addEventListener('change', updateProfileDirtyState);
    });

    document.querySelectorAll('a[href]').forEach(link => {
        link.addEventListener('click', function(event) {
            if (!profileHasUnsavedChanges || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || this.target === '_blank') return;
            const destination = this.href;
            if (!destination || destination === window.location.href) return;
            event.preventDefault();
            openUnsavedChangesModal(() => { window.location.href = destination; });
        });
    });

    document.querySelectorAll('.logout-form').forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!profileHasUnsavedChanges || form.dataset.confirmedLeave === 'true') return;
            event.preventDefault();
            openUnsavedChangesModal(() => {
                form.dataset.confirmedLeave = 'true';
                form.submit();
            });
        });
    });
});

let profileHasUnsavedChanges = false;
let profileInitialValues = new Map();

function editableProfileFields() {
    return Array.from(document.querySelectorAll('#profileForm .form-input:not(.disabled-input)'));
}

function updateProfileDirtyState() {
    profileHasUnsavedChanges = editableProfileFields().some(field => String(field.value) !== String(profileInitialValues.get(field) ?? field.defaultValue ?? ''));
}

function activateProfileTab(trigger, tabName, animate = true) {
    const targetPanel = document.getElementById(tabName);
    if (!targetPanel || !trigger) return;
    const tabs = Array.from(document.querySelectorAll('.tab-link'));
    const previousTab = tabs.find(tab => tab.getAttribute('aria-selected') === 'true');
    const direction = Math.sign(tabs.indexOf(trigger) - Math.max(0, tabs.indexOf(previousTab))) || 1;
    document.querySelectorAll('.tab-content').forEach(panel => {
        panel.classList.remove('active');
        panel.hidden = true;
    });
    tabs.forEach(tab => {
        tab.classList.remove('active');
        tab.setAttribute('aria-selected', 'false');
        tab.tabIndex = -1;
    });
    targetPanel.hidden = false;
    targetPanel.classList.add('active');
    trigger.classList.add('active');
    trigger.setAttribute('aria-selected', 'true');
    trigger.tabIndex = 0;
    if (animate && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        targetPanel.animate(
            [{ opacity: 0, transform: `translate3d(${direction * 16}px, 7px, 0)` }, { opacity: 1, transform: 'translate3d(0, 0, 0)' }],
            { duration: 300, easing: 'cubic-bezier(.22,.8,.32,1)' }
        );
    }
    if (history.replaceState) history.replaceState(null, '', '#' + tabName);
}

function switchTab(evt, tabName) {
    const trigger = evt.currentTarget;
    if (profileHasUnsavedChanges && tabName !== 'personal-info') {
        openUnsavedChangesModal(() => activateProfileTab(trigger, tabName));
        return;
    }
    activateProfileTab(trigger, tabName);
}

function toggleEdit() {
    const inputs = editableProfileFields();
    const saveAction = document.getElementById('saveAction');
    const editBtn = document.getElementById('editToggle');
    
    profileInitialValues = new Map(inputs.map(input => [input, String(input.value)]));
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
    profileHasUnsavedChanges = false;
    document.getElementById('profileFirstName').focus();
}

function cancelProfileEdit() {
    if (!profileHasUnsavedChanges) {
        window.location.reload();
        return;
    }
    openUnsavedChangesModal(() => window.location.reload());
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
let lastModalTrigger = null;
let pendingConfirmAction = null;
function openModal(title, message, formId) {
    document.getElementById('modalTitle').innerText = title;
    document.getElementById('modalMessage').innerText = message;
    currentFormToSubmit = document.getElementById(formId);
    pendingConfirmAction = null;
    document.getElementById('modalCancelBtn').innerText = 'Cancel';
    document.getElementById('modalConfirmBtn').innerText = 'Yes, Proceed';
    const modal = document.getElementById('confirmModal');
    lastModalTrigger = document.activeElement;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('modalConfirmBtn').focus();
}
function openUnsavedChangesModal(action) {
    document.getElementById('modalTitle').innerText = 'Discard unsaved changes?';
    document.getElementById('modalMessage').innerText = 'You changed profile information that has not been saved. Keep editing, or discard those changes and continue.';
    document.getElementById('modalCancelBtn').innerText = 'Keep editing';
    document.getElementById('modalConfirmBtn').innerText = 'Discard & continue';
    currentFormToSubmit = null;
    pendingConfirmAction = action;
    const modal = document.getElementById('confirmModal');
    lastModalTrigger = document.activeElement;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('modalCancelBtn').focus();
}
function closeModal(modalId) {
    const modal = document.getElementById(modalId || 'confirmModal');
    modal.classList.remove('show');
    if (modal.hasAttribute('aria-hidden')) modal.setAttribute('aria-hidden', 'true');
    currentFormToSubmit = null;
    pendingConfirmAction = null;
    if (lastModalTrigger instanceof HTMLElement) lastModalTrigger.focus();
}

function openCorrectionModal() {
    const modal = document.getElementById('correctionModal');
    lastModalTrigger = document.activeElement;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    modal.querySelector('.modal-close')?.focus();
}
document.getElementById('modalConfirmBtn').addEventListener('click', function() {
    const form = currentFormToSubmit;
    const action = pendingConfirmAction;
    if (action) {
        profileHasUnsavedChanges = false;
        pendingConfirmAction = null;
        action();
        return;
    }
    if (form) {
        profileHasUnsavedChanges = false;
        form.submit();
    }
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

    openModal('Save Profile Changes?', 'Confirm these changes to update your account and any pending application records.', 'profileForm');
});
document.getElementById('securityForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const pass1 = document.getElementById('newPass').value;
    const pass2 = document.getElementById('confPass').value;
    if(pass1 !== pass2) { showToast("Password Mismatch", "Your new passwords do not match. Please retype them carefully.", "error"); return; }
    if (!isStrongPassword(pass1)) { showToast("Security Warning", "Use at least 10 characters with uppercase, lowercase, a number, and a symbol.", "error"); return; }
    openModal('Update Password?', 'This will securely change your account password. You will be required to use this new password on your next login.', 'securityForm');
});

function isStrongPassword(password) {
    return password.length >= 10
        && /[a-z]/.test(password)
        && /[A-Z]/.test(password)
        && /\d/.test(password)
        && /[^A-Za-z0-9]/.test(password);
}

function updatePasswordStrength() {
    const password = document.getElementById('newPass').value;
    const checks = [password.length >= 10, /[a-z]/.test(password), /[A-Z]/.test(password), /\d/.test(password), /[^A-Za-z0-9]/.test(password)];
    const score = checks.filter(Boolean).length;
    const bar = document.getElementById('passwordStrengthBar');
    const label = document.getElementById('passwordStrengthText');
    bar.style.width = `${score * 20}%`;
    bar.dataset.score = String(score);
    label.textContent = password === '' ? 'Use 10+ characters with uppercase, lowercase, a number, and a symbol.' : (score === 5 ? 'Strong password — all requirements are met.' : `${score} of 5 password requirements met.`);
}

const allPrograms = <?php 
    $formatted_programs = array_map(function($p) {
        $p['formatted_date'] = date("M d, Y", strtotime($p['created_at']));
        $isCompleted = strtolower(trim((string)($p['availment_status'] ?? ''))) === 'completed'
            || !empty($p['date_completed']);
        $p['display_status'] = $isCompleted ? 'completed' : strtolower($p['approval_status'] ?? 'pending');
        if ($isCompleted) $p['availment_status'] = 'Completed';
        $completedDate = $p['date_completed'] ?? '';
        $p['formatted_completed_date'] = $completedDate !== ''
            ? date("M d, Y", strtotime($completedDate))
            : '';
        $p['approval_note'] = $p['approval_note'] ?? 'No specific reason provided.';
        $p['requirements'] = $p['requirements'] ?? 'Please visit the main office for document requirements.';
        $p['venue'] = $p['venue'] ?? 'PESO Main Office';
        $appointmentDate = $p['date_availed'] ?? '';
        if ($appointmentDate === '' && !empty($p['program_start_date']) && strtolower((string)$p['approval_status']) === 'approved') {
            $appointmentDate = $p['program_start_date'];
        }
        $p['formatted_appointment_date'] = $appointmentDate !== '' ? date('M d, Y', strtotime($appointmentDate)) : '';
        return $p;
    }, $availed_programs);
    echo json_encode($formatted_programs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

const progItemsPerPage = 5;
let currentProgPage = 1;
let activeProgramFilter = 'all';

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
    return ['approved', 'rejected', 'completed'].includes(normalized) ? normalized : 'pending';
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

function getApplicationNextAction(item) {
    const approval = String(item.approval_status || 'Pending').toLowerCase();
    const availment = String(item.availment_status || 'Not Yet Availed').toLowerCase();
    if (approval === 'rejected') return 'Review the PESO decision note and contact the office if you need clarification.';
    if (approval !== 'approved') return 'Wait for PESO to complete the eligibility review; keep your registered contact details active.';
    const actions = {
        'not yet availed': 'Review the listed requirements and wait for the official submission instruction.',
        'requirements received': 'Your documents are recorded. Wait for PESO validation and the next schedule.',
        'orientation': 'Attend the recorded orientation schedule and bring the instructed documents.',
        'examination': 'Attend the recorded examination schedule and follow PESO instructions.',
        'exam passed': 'Wait for the official orientation or placement instruction.',
        'exam failed': 'Contact PESO if you need clarification about the examination result.',
        'ongoing': 'Continue following the official program activity schedule.',
        'salary distribution': 'Follow the recorded distribution schedule and identification requirements.',
        'completed': 'No further action is required unless this record needs correction.',
        'not qualified': 'Contact PESO if you need clarification or information about another batch.',
        'cancelled': 'This application is closed; review other open opportunities when ready.'
    };
    return actions[availment] || 'Review the program details and wait for the next official PESO instruction.';
}

function shouldShowApplicationSchedule(item) {
    const availment = String(item.availment_status || '').toLowerCase();
    return Boolean(item.formatted_appointment_date)
        && ['orientation', 'examination', 'ongoing', 'salary distribution'].includes(availment);
}

function getProgramVisual(programName) {
    const program = String(programName || '').toUpperCase();
    if (program.includes('SPES')) {
        return {
            type: 'spes',
            icon: '<svg viewBox="0 0 24 24"><path d="m2 9 10-5 10 5-10 5L2 9Z"></path><path d="M6 11.5V16c3.5 2.7 8.5 2.7 12 0v-4.5M22 9v6"></path></svg>'
        };
    }
    if (program.includes('MSME')) {
        return {
            type: 'msme',
            icon: '<svg viewBox="0 0 24 24"><path d="M4 10v10h16V10"></path><path d="M3 4h18l-1 6a3 3 0 0 1-5 1 3 3 0 0 1-6 0 3 3 0 0 1-5-1L3 4Z"></path><path d="M9 20v-5h6v5"></path></svg>'
        };
    }
    return {
        type: 'tupad',
        icon: '<svg viewBox="0 0 24 24"><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><rect x="3" y="7" width="18" height="13" rx="2"></rect><path d="M3 12h18M10 12v2h4v-2"></path></svg>'
    };
}

function buildProgramProgress(item) {
    const approval = String(item.approval_status || 'Pending').toLowerCase();
    const availment = String(item.availment_status || 'Not Yet Availed').toLowerCase();
    const program = String(item.program_name || '').trim().toUpperCase();
    let activeStep = 1;
    let outcome = '';

    let steps = [
        ['Submitted', 'Application received'],
        ['Eligibility', 'Office review'],
        ['Requirements', 'Document submission'],
        ['Program activity', 'Official schedule'],
        ['Completion', 'Final program record'],
    ];
    let statusStages = {
        'not yet availed': 3,
        'requirements received': 3,
        'orientation': 4,
        'examination': 4,
        'exam passed': 4,
        'exam failed': 4,
        'ongoing': 4,
        'salary distribution': 4,
        'completed': 5,
    };

    if (program.includes('SPES')) {
        steps = [
            ['Submitted', 'Application received'],
            ['Eligibility', 'Office review'],
            ['Requirements', 'Documents recorded'],
            ['Examination', 'Assessment result'],
            ['Orientation', 'Program briefing'],
            ['Employment', 'Placement and work period'],
            ['Completion', 'Final SPES record'],
        ];
        statusStages = {
            'not yet availed': 3,
            'requirements received': 3,
            'examination': 4,
            'exam failed': 4,
            'exam passed': 5,
            'orientation': 5,
            'ongoing': 6,
            'salary distribution': 6,
            'completed': 7,
        };
    } else if (program.includes('TUPAD')) {
        steps = [
            ['Submitted', 'Application received'],
            ['Eligibility', 'Office review'],
            ['Requirements', 'Documents recorded'],
            ['Orientation', 'Safety and work briefing'],
            ['Work assignment', 'Community work period'],
            ['Payout', 'Salary distribution'],
            ['Completion', 'Final TUPAD record'],
        ];
        statusStages = {
            'not yet availed': 3,
            'requirements received': 3,
            'orientation': 4,
            'ongoing': 5,
            'salary distribution': 6,
            'completed': 7,
        };
    } else if (program.includes('MSME')) {
        steps = [
            ['Submitted', 'Business profile received'],
            ['Eligibility', 'Office review'],
            ['Profiling', 'Business information'],
            ['Validation', 'Profile assessment'],
            ['Assistance', 'Referral or support'],
            ['Completion', 'Final MSME record'],
        ];
        statusStages = {
            'not yet availed': 3,
            'requirements received': 3,
            'orientation': 4,
            'examination': 4,
            'ongoing': 5,
            'salary distribution': 5,
            'completed': 6,
        };
    }

    if (approval === 'rejected') {
        activeStep = 2;
        outcome = 'not-approved';
    } else if (approval === 'approved') {
        activeStep = statusStages[availment] || 3;
        if (['exam failed', 'not qualified', 'cancelled'].includes(availment)) {
            outcome = 'not-approved';
        }
    }

    steps[1][1] = approval === 'pending' ? 'Currently being reviewed' : (approval === 'rejected' ? 'Review not approved' : 'Review completed');

    return `<div class="bp-service-journey" aria-label="${escapeHtml(item.program_name)} program progress">
        <div class="bp-service-journey-heading"><span>${escapeHtml(item.program_name)} Program Progress</span><strong>${escapeHtml(item.availment_status || item.approval_status || 'Pending')}</strong></div>
        <ol style="--bp-progress-steps:${steps.length}">${steps.map((step, index) => {
            const number = index + 1;
            const state = number < activeStep ? 'is-complete' : (number === activeStep ? (outcome || 'is-current') : 'is-upcoming');
            return `<li class="${state}"><span class="bp-journey-marker">${number < activeStep ? '&#10003;' : number}</span><div><strong>${step[0]}</strong><small>${escapeHtml(step[1])}</small></div></li>`;
        }).join('')}</ol>
    </div>`;
}

function renderPrograms() {
    const container = document.getElementById('availedProgramsContainer');
    const controls = document.getElementById('progPaginationControls');
    if (allPrograms.length === 0) return; 

    const filteredPrograms = allPrograms.filter(item => {
        const status = getStatusKey(item.display_status);
        if (activeProgramFilter === 'all') return true;
        if (activeProgramFilter === 'current') return status === 'approved';
        return status === activeProgramFilter;
    });
    const totalProgPages = Math.max(1, Math.ceil(filteredPrograms.length / progItemsPerPage));
    currentProgPage = Math.min(currentProgPage, totalProgPages);
    controls.style.display = filteredPrograms.length > progItemsPerPage ? 'flex' : 'none';

    container.innerHTML = '';
    if (filteredPrograms.length === 0) {
        container.innerHTML = '<div class="profile-filter-empty"><strong>No matching applications</strong><span>Choose another status to review your records.</span></div>';
        return;
    }
    const startIdx = (currentProgPage - 1) * progItemsPerPage;
    const endIdx = startIdx + progItemsPerPage;
    const pageItems = filteredPrograms.slice(startIdx, endIdx);

    pageItems.forEach(item => {
        const statusKey = getStatusKey(item.display_status);
        const programVisual = getProgramVisual(item.program_name);
        const safeTitle = escapeHtml(item.program_name);
        const safeBatch = escapeHtml(item.program_code || `Program ${item.program_id}`);
        const safeReason = escapeHtml(item.approval_note);
        const safeReqs = escapeHtml(item.requirements);
        const safeVenue = escapeHtml(item.venue);
        const safeDate = escapeHtml(item.formatted_date);
        const safeCompletedDate = escapeHtml(item.formatted_completed_date);
        const safeAppointmentDate = escapeHtml(item.formatted_appointment_date || '');
        const safeNextAction = escapeHtml(getApplicationNextAction(item));
        const showSchedule = shouldShowApplicationSchedule(item);
        const actionSummary = `
            <div class="application-action-summary${showSchedule ? '' : ' is-next-only'}">
                <div><span>Next action</span><strong>${safeNextAction}</strong></div>
                ${showSchedule ? `<div><span>Next schedule</span><strong>${safeAppointmentDate}</strong></div><div><span>Venue</span><strong>${safeVenue}</strong></div>` : ''}
            </div>`;
        const spesFormAction = String(item.program_name || '').trim().toUpperCase() === 'SPES'
            ? `<button type="button" class="btn-spes-form" onclick="event.stopPropagation(); openSpesForm(${encodeURIComponent(item.beneficiary_id)})">SPES Form</button>`
            : '';
        const msmeFormAction = String(item.program_name || '').trim().toUpperCase().includes('MSME')
            ? `<button type="button" class="btn-spes-form" onclick="event.stopPropagation(); openMsmeForm(${encodeURIComponent(item.beneficiary_id)})">MSME Form</button>`
            : '';
        const html = `
            <div class="status-card-horizontal bp-has-journey"
                 data-status="${statusKey}"
                 data-title="${safeTitle}"
                 data-batch="${safeBatch}"
                 data-reason="${safeReason}"
                 data-reqs="${safeReqs}"
                 data-venue="${safeVenue}"
                 data-completed-date="${safeCompletedDate}"
                 onclick="handleCardClick(this)">
                  
                <div class="card-left">
                    <div class="program-icon program-icon--${programVisual.type}" aria-hidden="true">
                        ${programVisual.icon}
                    </div>
                    <div class="program-copy">
                        <span class="program-kicker">Batch ${safeBatch}</span>
                        <h4>${safeTitle}</h4>
                        <span class="date-applied">${statusKey === 'completed' && safeCompletedDate ? `Completed on ${safeCompletedDate}` : `Applied on ${safeDate}`}</span>
                    </div>
                </div>
                <div class="card-right">
                    <div class="status-summary">
                        <span class="status-caption">${statusKey === 'completed' ? 'Program Status' : 'Approval Status'}</span>
                        <span class="status-pill ${statusKey}"><span class="status-dot"></span>${getStatusLabel(statusKey)}</span>
                    </div>
                    ${spesFormAction}
                    ${msmeFormAction}
                    <button type="button" class="btn-check-status" onclick="event.stopPropagation(); handleCardClick(this.closest('.status-card-horizontal'))">
                        <span>View details</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg>
                    </button>
                </div>
                ${actionSummary}
                ${buildProgramProgress(item)}
            </div>
        `;
        container.innerHTML += html;
    });

    document.getElementById('progPageInfo').innerText = `Page ${currentProgPage} of ${totalProgPages}`;
    document.getElementById('progPrevBtn').disabled = currentProgPage === 1;
    document.getElementById('progNextBtn').disabled = currentProgPage === totalProgPages;
}

function setProgramFilter(filter, button) {
    activeProgramFilter = filter;
    currentProgPage = 1;
    document.querySelectorAll('[data-program-filter]').forEach(chip => {
        const active = chip === button;
        chip.classList.toggle('active', active);
        chip.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    renderPrograms();
}

function changeProgPage(direction) {
    currentProgPage += direction;
    renderPrograms();
}

const allLogs = <?php 
    $formatted_logs = array_map(function($l) use ($user_display_name) {
        $action = strtoupper(trim((string)($l['action_type'] ?? '')));
        $description = trim((string)($l['description'] ?? ''));
        if ($action === 'LOGIN') {
            $description = $user_display_name . (stripos($description, 'Google') !== false ? ' logged in securely using Google.' : ' logged in securely.');
        } elseif ($action === 'LOGOUT') {
            $description = $user_display_name . ' logged out securely.';
        } elseif (preg_match('/^User\b/i', $description)) {
            $description = preg_replace('/^User\b/i', $user_display_name, $description, 1);
        }
        $l['description'] = $description;
        $l['formatted_date'] = date("M d, Y • h:i A", strtotime($l['created_at']));
        return $l;
    }, $activity_logs);
    echo json_encode($formatted_logs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

const logItemsPerPage = 5; 
let currentLogPage = 1;
let activeLogFilter = 'all';

function renderLogs() {
    const container = document.getElementById('activityLogContainer');
    const controls = document.getElementById('logPaginationControls');
    if (allLogs.length === 0) return; 

    const filteredLogs = allLogs.filter(log => {
        if (activeLogFilter === 'all') return true;
        const module = String(log.module_name || '').toLowerCase();
        const action = String(log.action_type || '').toLowerCase();
        if (activeLogFilter === 'security') return module.includes('security') || ['login', 'logout', 'security'].includes(action);
        if (activeLogFilter === 'application') return module.includes('program') || module.includes('application') || action === 'apply';
        return module.includes(activeLogFilter);
    });
    const totalLogPages = Math.max(1, Math.ceil(filteredLogs.length / logItemsPerPage));
    currentLogPage = Math.min(currentLogPage, totalLogPages);
    controls.style.display = filteredLogs.length > logItemsPerPage ? 'flex' : 'none';

    container.innerHTML = '';
    if (filteredLogs.length === 0) {
        container.innerHTML = '<div class="profile-filter-empty"><strong>No matching activity</strong><span>Choose another category to review your account history.</span></div>';
        return;
    }
    const startIdx = (currentLogPage - 1) * logItemsPerPage;
    const endIdx = startIdx + logItemsPerPage;
    const pageItems = filteredLogs.slice(startIdx, endIdx);

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

function setLogFilter(filter, button) {
    activeLogFilter = filter;
    currentLogPage = 1;
    document.querySelectorAll('[data-log-filter]').forEach(chip => {
        const active = chip === button;
        chip.classList.toggle('active', active);
        chip.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    renderLogs();
}

function changeLogPage(direction) {
    currentLogPage += direction;
    renderLogs();
}

document.addEventListener('DOMContentLoaded', function() {
    renderPrograms();
    renderLogs();
    const requestedTab = window.location.hash.slice(1);
    if (['personal-info', 'my-programs', 'activity-log', 'security'].includes(requestedTab)) {
        const trigger = Array.from(document.querySelectorAll('.tab-link')).find(button => button.getAttribute('onclick')?.includes(`'${requestedTab}'`));
        if (trigger) activateProfileTab(trigger, requestedTab, false);
    }
});

function handleCardClick(element) {
    const status = getStatusKey(element.getAttribute('data-status'));
    const title = element.getAttribute('data-title');
    const batch = element.getAttribute('data-batch') || 'Not recorded';
    const reason = element.getAttribute('data-reason');
    const reqs = element.getAttribute('data-reqs');
    const venue = element.getAttribute('data-venue');
    const completedDate = element.getAttribute('data-completed-date');
    
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
            <div class="status-batch-reference"><span>Exact batch</span><strong>${escapeHtml(batch)}</strong></div>
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
    else if (status === 'completed') {
        modalIcon.classList.add("icon-success");
        modalIcon.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10"></path><path d="m8 12 3 3 7-7"></path></svg>`;
        modalTitle.innerText = "Program Completed";
        modalBody.innerHTML = `
            <p class="status-message">Your participation in <strong>${escapeHtml(title)}</strong> is complete.</p>
            <div class="status-batch-reference"><span>Exact batch</span><strong>${escapeHtml(batch)}</strong></div>
            <div class="status-detail-panel">
                <section class="status-detail-section">
                    <h4>Completion status</h4>
                    <p class="status-reason">${completedDate ? `Completed on <strong>${escapeHtml(completedDate)}</strong>.` : 'This program is recorded as completed. Contact PESO if a completion date needs to be added.'}</p>
                </section>
            </div>
            <div class="status-note">Contact PESO Vinzons if the displayed completion information needs correction.</div>
        `;
    }
    else if (status === 'rejected') {
        modalIcon.classList.add("icon-danger");
        modalIcon.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="m15 9-6 6"></path><path d="m9 9 6 6"></path></svg>`;
        modalTitle.innerText = "Application Not Approved";
        modalBody.innerHTML = `
            <p class="status-message">Your application for <strong>${escapeHtml(title)}</strong> was not approved.</p>
            <div class="status-batch-reference"><span>Exact batch</span><strong>${escapeHtml(batch)}</strong></div>
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
            <p class="status-message">Your application for <strong>${escapeHtml(title)}</strong> is being reviewed by PESO staff.</p>
            <div class="status-batch-reference"><span>Exact batch</span><strong>${escapeHtml(batch)}</strong></div>
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
    lastModalTrigger = document.activeElement;
    modal.querySelector('.modal-close')?.focus();
}
</script>
<script src="spes_form_modal.js?v=20260813y"></script>
<script src="msme_form_modal.js?v=20260906e"></script>
</body>
</html>
