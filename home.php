<?php
require "auth.php"; // This handles session_start() and the routing logic
require "db.php";
check_user_role("user"); // Protects this page for standard users only

// If they pass all checks, they are a user. Continue loading page...
$user_id = (int)$_SESSION["user_id"];
$user_display_name = "User";
$first_char = "U";
$user_profile_src = '';
$home_profile_ready = false;

// Fetching individual name components based on your table structure
$stmt = $conn->prepare("SELECT first_name, middle_name, last_name, ext_name, profile_pic, birthdate, sex, civil_status, contact_no, street_purok_zone, barangay, email FROM users WHERE user_id=? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows === 1) {
    $row = $res->fetch_assoc();
    
    // Constructing the full name string
    $fn = trim($row["first_name"] ?? "");
    $mn = trim($row["middle_name"] ?? "");
    $ln = trim($row["last_name"] ?? "");
    $ex = trim($row["ext_name"] ?? "");

    // Logic: First Last (and Extension if exists)
    $full_name = $fn . ($mn ? " " . substr($mn, 0, 1) . "." : "") . " " . $ln . ($ex ? " " . $ex : "");
    
    if (!empty(trim($full_name))) {
        $user_display_name = $full_name;
    }
    
    // For the avatar icon
    if (!empty($fn)) {
        $first_char = strtoupper(substr($fn, 0, 1));
    }

    $home_required_profile_fields = ['first_name', 'last_name', 'birthdate', 'sex', 'civil_status', 'contact_no', 'street_purok_zone', 'barangay', 'email'];
    $home_profile_ready = count(array_filter($home_required_profile_fields, static fn($field) => trim((string)($row[$field] ?? '')) !== '')) === count($home_required_profile_fields);

    $profile_filename = basename((string)($row['profile_pic'] ?? ''));
    if ($profile_filename !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $profile_filename)) {
        $user_profile_src = 'uploads/' . rawurlencode($profile_filename);
    }
}

$programs = [];
$has_programs_table = true;
$official_advisory = null;

try {
    $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $q = $conn->prepare("SELECT program_id, program_name, description, start_date, end_date, image_path
        FROM programs
        WHERE approval_status = 'Approved'
          AND (UPPER(program_name) LIKE '%TUPAD%' OR UPPER(program_name) LIKE '%SPES%' OR UPPER(program_name) LIKE '%MSME%')
          AND LOWER(COALESCE(status, '')) <> 'completed'
          AND (end_date IS NULL OR end_date = '0000-00-00' OR end_date >= ?)
          AND (start_date IS NULL OR end_date IS NULL OR end_date = '0000-00-00' OR end_date >= start_date)
        ORDER BY CASE
                   WHEN start_date IS NULL OR start_date = '0000-00-00' OR start_date <= ? THEN 0
                   ELSE 1
                 END,
                 COALESCE(NULLIF(end_date, '0000-00-00'), '9999-12-31') ASC,
                 program_id DESC
        LIMIT 12");
    if ($q) {
        $q->bind_param('ss', $today, $today);
        $q->execute();
        $result = $q->get_result();
        $home_program_types = [];
        while ($p = $result->fetch_assoc()) {
            $program_name_upper = strtoupper((string)($p['program_name'] ?? ''));
            $program_type = str_contains($program_name_upper, 'TUPAD') ? 'TUPAD'
                : (str_contains($program_name_upper, 'SPES') ? 'SPES'
                : (str_contains($program_name_upper, 'MSME') ? 'MSME' : ''));
            if ($program_type === '' || isset($home_program_types[$program_type])) continue;
            $home_program_types[$program_type] = true;
            $programs[] = $p;
            if (count($programs) === 3) break;
        }
        $q->close();
    }
    $advisoryQuery = $conn->query("SELECT program_id, program_name, program_code, start_date, end_date, created_at, updated_at
        FROM programs
        WHERE approval_status = 'Approved'
          AND (UPPER(program_name) LIKE '%TUPAD%' OR UPPER(program_name) LIKE '%SPES%' OR UPPER(program_name) LIKE '%MSME%')
          AND LOWER(COALESCE(status, '')) <> 'completed'
          AND end_date >= CURDATE()
        ORDER BY COALESCE(updated_at, created_at) DESC, program_id DESC
        LIMIT 1");
    if ($advisoryQuery) $official_advisory = $advisoryQuery->fetch_assoc() ?: null;
} catch (Throwable $e) {
    $has_programs_table = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/pesologo.png">
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BENEPESO | Home</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="home.css?v=16" />
<link rel="stylesheet" href="frontend_polish.css?v=16">
    <link rel="stylesheet" href="beneficiary_responsive.css?v=10">
    <link rel="stylesheet" href="beneficiary_content_enhancements.css?v=1">
    <link rel="stylesheet" href="beneficiary_content_polish.css?v=9">
    <link rel="stylesheet" href="authenticated_experience.css?v=4">
<script src="frontend_polish.js?v=16" defer></script>
    <script src="beneficiary_content_polish.js?v=1" defer></script>
</head>
<body class="home-page">

<div class="bg-orb orb-1"></div>
<div class="bg-orb orb-2"></div>
<div class="bg-orb orb-3"></div>

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
            <a class="menu-item active" href="home.php">Home</a>
            <a class="menu-item" href="programs.php">Programs</a>
            <a class="menu-item" href="about.php">About</a>

            <div class="account-area" id="accountWrap">
                <button class="account-button" id="accountButton" type="button">
                    <span class="account-icon">
                        <?php echo htmlspecialchars($first_char); ?>
                        <?php if ($user_profile_src !== ''): ?><img src="<?php echo htmlspecialchars($user_profile_src); ?>" alt="" onerror="this.remove()"><?php endif; ?>
                    </span>
                    <span class="account-text"><?php echo htmlspecialchars($user_display_name); ?></span>
                    <span class="account-arrow">▾</span>
                </button>

                <div class="account-dropdown" id="accountDropdown">
                    <a class="account-dropdown-link" href="profile.php"><span class="account-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></span><span>My Profile</span></a>
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

<section class="welcome-area">
    <div class="welcome-inner">

        <div class="welcome-left stagger-1">
            <div class="welcome-badge">
                <span class="badge-dot"></span>
                PESO PROGRAMS • VINZONS, CAMARINES NORTE
            </div>

            <h1 class="welcome-title">
                Beneficiary Profiling, Eligibility, and Verification
                <span class="welcome-highlight">made easier.</span>
            </h1>

            <p class="welcome-text">
                View programs, verify your status, and manage your profile securely using the official BENEPESO platform.
            </p>

            <div class="welcome-actions">
                <a class="btn-explore" href="programs.php">Explore Programs</a>
                <a class="btn-verify" href="profile.php#my-programs">Track My Application</a>
            </div>

            <div class="welcome-stats">
                <div class="stat-box">
                    <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></div>
                    <div>
                        <div class="stat-title">Secure</div>
                        <div class="stat-sub">Protected Access</div>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m13 2-9 12h8l-1 8 9-12h-8l1-8Z"></path></svg></div>
                    <div>
                        <div class="stat-title">Fast</div>
                        <div class="stat-sub">Live Verification</div>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></div>
                    <div>
                        <div class="stat-title">Easy</div>
                        <div class="stat-sub">User Profiling</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="welcome-right stagger-2">
            <div class="hero-graphic">
                <div class="bp-service-orbit" role="img" aria-label="BENEPESO digital service connects beneficiary profiling, eligibility verification, and PESO programs">
                    <div class="bp-orbit-ring bp-orbit-ring-outer" aria-hidden="true"><i></i></div>
                    <div class="bp-orbit-ring bp-orbit-ring-inner" aria-hidden="true"></div>

                    <div class="bp-orbit-core">
                        <span class="bp-orbit-seal"><img src="img/pesologo.png" alt="" onerror="this.style.display='none'"></span>
                        <span class="bp-orbit-eyebrow">Official PESO Vinzons portal</span>
                        <strong>BENEPESO</strong>
                        <small>One secure service path</small>
                        <span class="bp-orbit-live"><i></i> System ready</span>
                    </div>

                    <div class="bp-orbit-node bp-orbit-profile">
                        <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 21a7 7 0 0 1 14 0"></path></svg></span>
                        <div><small>01</small><strong>Profile</strong></div>
                    </div>
                    <div class="bp-orbit-node bp-orbit-verify">
                        <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 2.8 7.6 7 9 4.2-1.4 7-4.5 7-9V6l-7-3Z"></path><path d="m9 12 2 2 4-4"></path></svg></span>
                        <div><small>02</small><strong>Eligibility</strong></div>
                    </div>
                    <div class="bp-orbit-node bp-orbit-program">
                        <span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2"></rect><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18M10 12v2h4v-2"></path></svg></span>
                        <div><small>03</small><strong>Program</strong></div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</section>

<section class="bp-service-snapshot-shell content-wrap" id="beneficiaryServiceSnapshot" data-profile-ready="<?= $home_profile_ready ? 'true' : 'false' ?>" aria-live="polite" hidden>
    <div class="bp-service-snapshot bp-service-snapshot-v2">
        <div class="bp-service-snapshot-mark" aria-hidden="true">
            <span class="bp-snapshot-pulse"></span>
            <svg viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.6 3.1 7.9 7.5 9.5 4.4-1.6 7.5-4.9 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg>
        </div>
        <div class="bp-service-snapshot-copy">
            <span class="bp-service-snapshot-kicker"><i aria-hidden="true"></i>Your BENEPESO action plan</span>
            <h2>Checking your latest application...</h2>
            <p>Please wait while your current service status is prepared.</p>
        </div>
        <div class="bp-snapshot-controls">
            <span class="bp-service-snapshot-status"><i aria-hidden="true"></i><span>Current update</span></span>
            <a class="bp-service-snapshot-action" href="profile.php#my-programs"><span>View program progress</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg></a>
        </div>
    </div>
</section>

<?php if ($official_advisory): ?>
<aside class="home-official-advisory content-wrap bp-content-module" aria-labelledby="homeAdvisoryTitle">
    <div class="home-advisory-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 13V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v7"></path><path d="M7 13h10l2 7H5l2-7Z"></path><path d="M9 8h6"></path></svg></div>
    <div class="home-advisory-copy">
        <span>Official PESO advisory</span>
        <h2 id="homeAdvisoryTitle"><?= htmlspecialchars($official_advisory['program_name']) ?> applications are open</h2>
        <p>Review batch <?= htmlspecialchars($official_advisory['program_code'] ?: ('#' . $official_advisory['program_id'])) ?> before the <?= htmlspecialchars(date('M d, Y', strtotime($official_advisory['end_date']))) ?> application deadline.</p>
    </div>
    <div class="home-advisory-meta"><time datetime="<?= htmlspecialchars(date('Y-m-d', strtotime($official_advisory['updated_at'] ?: $official_advisory['created_at']))) ?>">Updated <?= htmlspecialchars(date('M d, Y', strtotime($official_advisory['updated_at'] ?: $official_advisory['created_at']))) ?></time><span>Published by PESO Vinzons</span></div>
    <a href="programs.php?program_id=<?= (int)$official_advisory['program_id'] ?>">Review advisory <span aria-hidden="true">&rarr;</span></a>
</aside>
<?php endif; ?>

<section class="quick-access-area reveal">
    <div class="content-wrap">
        <div class="quick-area">
            <div class="quick-top">
                <div>
                    <span class="home-section-eyebrow">Resident workspace</span>
                    <div class="quick-title">Your next actions</div>
                </div>
                <div class="quick-tag"><?= $home_profile_ready ? 'Profile ready' : 'Action needed' ?></div>
            </div>

            <div class="quick-links">
                <a class="quick-link" href="profile.php">
                    <div class="quick-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></div>
                    <div>
                        <span class="quick-step">01 &middot; PROFILE</span>
                        <div class="quick-name"><?= $home_profile_ready ? 'Review your profile' : 'Complete your profile' ?></div>
                        <div class="quick-desc"><?= $home_profile_ready ? 'Keep your resident information accurate' : 'Finish the required information before applying' ?></div>
                    </div>
                    <span class="quick-arrow" aria-hidden="true">&rarr;</span>
                </a>

                <a class="quick-link" href="verification.php">
                    <div class="quick-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.6 3.1 7.9 7.5 9.5 4.4-1.6 7.5-4.9 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg></div>
                    <div>
                        <span class="quick-step">02 &middot; VERIFY</span>
                        <div class="quick-name">Check your record</div>
                        <div class="quick-desc">Review your latest beneficiary status securely</div>
                    </div>
                    <span class="quick-arrow" aria-hidden="true">&rarr;</span>
                </a>

                <a class="quick-link" href="programs.php">
                    <div class="quick-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"></rect><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18M10 12v2h4v-2"></path></svg></div>
                    <div>
                        <span class="quick-step">03 &middot; PROGRAMS</span>
                        <div class="quick-name">Find an opportunity</div>
                        <div class="quick-desc">Compare approved TUPAD, SPES, and MSME listings</div>
                    </div>
                    <span class="quick-arrow" aria-hidden="true">&rarr;</span>
                </a>
            </div>

            <div class="quick-note">
                <b>Account reminder:</b> Keep your registered email and mobile number active for official PESO updates.
            </div>
        </div>
    </div>
</section>

<section class="service-guide-section reveal bp-content-module" aria-labelledby="serviceGuideTitle">
    <div class="content-wrap">
        <div class="service-route-shell">
            <div class="content-enhancement-heading">
                <span class="content-enhancement-eyebrow">Your service path</span>
                <h2 id="serviceGuideTitle">From profile to official result</h2>
                <p>One connected service route—from an accurate profile to a completed PESO program.</p>
            </div>
            <ol class="service-step-grid" aria-label="BENEPESO service path">
                <li><span aria-hidden="true">01</span><div><small>PROFILE</small><strong>Complete your profile</strong><p>Confirm your identity, contact, and household information.</p><a href="profile.php">Review profile</a></div></li>
                <li><span aria-hidden="true">02</span><div><small>DISCOVER</small><strong>Review eligibility</strong><p>Check the rules and requirements for the exact batch.</p><a href="programs.php">Browse programs</a></div></li>
                <li><span aria-hidden="true">03</span><div><small>APPLY</small><strong>Submit securely</strong><p>Complete the official form and privacy acknowledgment.</p><a href="programs.php">View open batches</a></div></li>
                <li><span aria-hidden="true">04</span><div><small>FOLLOW</small><strong>See your next action</strong><p>Track validation, requirements, schedules, and completion.</p><a href="profile.php#my-programs">Track progress</a></div></li>
            </ol>
        </div>
    </div>
</section>

<section class="program-area reveal">
    <div class="content-wrap">
        <div class="area-head">
            <div>
                <h2 class="area-title">Open Programs</h2>
                <p class="area-sub">Current and upcoming PESO opportunities that are still accepting beneficiaries.</p>
            </div>
            <a class="see-more" href="programs.php">See all →</a>
        </div>

        <div class="program-list">
            <?php if ($has_programs_table && count($programs) > 0): ?>
                <?php foreach($programs as $p): ?>
                    <a href="programs.php?program_id=<?php echo (int)$p["program_id"]; ?>" class="program-card reveal">
                        <?php
                            $program_title = trim($p["program_name"] ?? "Program");
                            $program_key = strtolower($program_title);
                            $program_image = trim($p["image_path"] ?? "");
                            $program_type = str_contains($program_key, "tupad") ? "TUPAD"
                                : (str_contains($program_key, "spes") ? "SPES"
                                : (str_contains($program_key, "msme") ? "MSME" : "PESO"));
                            $program_is_upcoming = !empty($p["start_date"])
                                && $p["start_date"] !== '0000-00-00'
                                && $p["start_date"] > $today;

                            if ($program_image === "") {
                                if (str_contains($program_key, "tupad") || str_contains($program_key, "emergency")) {
                                    $program_image = "img/tupads.png";
                                } elseif (str_contains($program_key, "spes") || str_contains($program_key, "student")) {
                                    $program_image = "img/spes.png";
                                } elseif (str_contains($program_key, "msme") || str_contains($program_key, "enterprise")) {
                                    $program_image = "img/msme.png";
                                } else {
                                    $program_image = "img/pesologo.png";
                                }
                            }
                        ?>
                        <div class="home-program-media">
                            <img class="program-image" src="<?php echo htmlspecialchars($program_image); ?>" alt="<?php echo htmlspecialchars($program_title); ?>" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='img/pesologo.png';">
                            <span class="home-program-icon" aria-hidden="true">
                                    <?php if ($program_type === 'SPES'): ?>
                                        <svg viewBox="0 0 24 24"><path d="m3 9 9-5 9 5-9 5-9-5Z"></path><path d="M7 12v4c3 2 7 2 10 0v-4M21 9v6"></path></svg>
                                    <?php elseif ($program_type === 'MSME'): ?>
                                        <svg viewBox="0 0 24 24"><path d="M4 10v10h16V10M3 10l2-6h14l2 6"></path><path d="M3 10a3 3 0 0 0 5 2 3 3 0 0 0 4 0 3 3 0 0 0 4 0 3 3 0 0 0 5-2M9 20v-5h6v5"></path></svg>
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24"><path d="M6 8h12l1 12H5L6 8Z"></path><path d="M9 8V6a3 3 0 0 1 6 0v2M9 13h6"></path></svg>
                                    <?php endif; ?>
                            </span>
                            <span class="home-program-status<?= $program_is_upcoming ? ' is-upcoming' : '' ?>"><i aria-hidden="true"></i> <?= $program_is_upcoming ? 'Coming soon' : 'Open now' ?></span>
                        </div>

                        <h3 class="program-title"><?php echo htmlspecialchars($program_title); ?></h3>
                        <p class="program-text">
                            <?php
                                $desc = trim($p["description"] ?? "");
                                echo htmlspecialchars(mb_strimwidth($desc, 0, 120, "..."));
                            ?>
                        </p>

                        <div class="home-program-meta">
                            <span><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M7 3v3M17 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"></path></svg><?php echo !empty($p["end_date"]) && $p["end_date"] !== '0000-00-00'
                                ? 'Open until ' . htmlspecialchars(date("M d, Y", strtotime($p["end_date"])))
                                : (!empty($p["start_date"]) ? 'Starts ' . htmlspecialchars(date("M d, Y", strtotime($p["start_date"]))) : "Schedule available"); ?></span>
                            <span><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 21s7-5 7-12a7 7 0 1 0-14 0c0 7 7 12 7 12Z"></path><circle cx="12" cy="9" r="2"></circle></svg>PESO Vinzons</span>
                        </div>
                        <div class="program-btn">View program details <span aria-hidden="true">&rarr;</span></div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="program-card reveal" style="grid-column:1/-1; text-align:center; padding:40px;">
                    <h3 class="program-title">No current programs</h3>
                    <p class="program-text">There are no active or upcoming approved programs at this time. Please check again later.</p>
                    <a class="program-btn" href="programs.php">View program archive</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="info-area reveal">
    <div class="content-wrap">
        <div class="home-readiness-panel">
        <div class="home-readiness-heading">
            <span class="home-section-eyebrow">Before you apply</span>
            <h2 class="area-title">Application readiness</h2>
            <p class="area-sub">Prepare accurate information before opening an official application form.</p>
            <a href="programs.php">Review program requirements <span aria-hidden="true">&rarr;</span></a>
        </div>

        <div class="info-list home-readiness-list">
            <div class="info-card reveal" style="transition-delay: 0.1s;">
                <div class="info-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="4" y="3" width="16" height="18" rx="2" />
                        <circle cx="9" cy="9" r="2" />
                        <path d="M7 15c.8-1.4 2.2-2 4-2M14 8h3M14 12h3M14 16h3" />
                    </svg>
                </div>
                <span class="readiness-number">01</span>
                <div class="info-title">Match your documents</div>
                <div class="info-text">Use the same name spelling, birthdate, and address shown on your valid supporting documents.</div>
            </div>
            <div class="info-card reveal" style="transition-delay: 0.2s;">
                <div class="info-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 5h11M9 12h11M9 19h11" />
                        <path d="m4 5 1 1 2-2M4 12l1 1 2-2M4 19l1 1 2-2" />
                    </svg>
                </div>
                <span class="readiness-number">02</span>
                <div class="info-title">Prepare originals and copies</div>
                <div class="info-text">Review the selected batch requirements and bring the requested originals or copies only when instructed by PESO.</div>
            </div>
            <div class="info-card reveal" style="transition-delay: 0.3s;">
                <div class="info-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z" />
                        <path d="M10 21h4" />
                    </svg>
                </div>
                <span class="readiness-number">03</span>
                <div class="info-title">Protect your account</div>
                <div class="info-text">Keep your password private and rely on BENEPESO, registered email, or PESO staff for official instructions.</div>
            </div>
        </div>
        </div>
    </div>
</section>

<section class="service-help-section reveal bp-content-module" aria-labelledby="serviceHelpTitle">
    <div class="content-wrap service-help-panel">
        <div>
            <span class="content-enhancement-eyebrow">Official assistance</span>
            <h2 id="serviceHelpTitle">Need help with an application?</h2>
            <p>Contact PESO Vinzons for record corrections, requirement questions, and official schedule confirmation.</p>
        </div>
        <div class="service-help-actions">
            <a href="mailto:lguvinzonspeso@gmail.com">Email PESO</a>
            <a href="tel:+639479971186">Call +63 947 997 1186</a>
            <a href="about.php#peso-office">Office details</a>
        </div>
    </div>
</section>

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
            <a class="footer-contact-link" href="#peso-contact" data-contact-kind="email"><i class="fa-solid fa-envelope" aria-hidden="true"></i><span>lguvinzonspeso@gmail.com</span></a>
            <a class="footer-contact-link" href="#peso-contact" data-contact-kind="phone"><i class="fa-solid fa-phone" aria-hidden="true"></i><span>+63 947 997 1186</span></a>
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
        // Simple Dropdown Toggle
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

        // Mobile Menu Toggle
        const menuBtn = document.getElementById('menuButton');
        const menuArea = document.getElementById('menuArea');
        if(menuBtn && menuArea) {
            menuBtn.addEventListener('click', function() {
                const isOpen = menuArea.classList.toggle('open');
                menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        }

        // Scroll Reveal Animation Logic
        const observerOptions = {
            root: null,
            rootMargin: '0px',
            threshold: 0.15
        };

        const observer = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                    observer.unobserve(entry.target); 
                }
            });
        }, observerOptions);

        document.querySelectorAll('.reveal').forEach(el => {
            observer.observe(el);
        });
    });
</script>
</body>
</html>
