<?php
require_once __DIR__ . '/auth_session.php';
require "db.php";

// Check if user is logged in to show the account dropdown
$is_logged_in = isset($_SESSION["user_id"]);
$user_display_name = "User";
$first_char = "U";
$user_profile_src = '';

if ($is_logged_in) {
    $user_id = (int)$_SESSION["user_id"];
    
    // Fetching individual name components
    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, ext_name, profile_pic FROM users WHERE user_id=? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res && $res->num_rows === 1) {
        $row = $res->fetch_assoc();
        
        $fn = trim($row["first_name"] ?? "");
        $mn = trim($row["middle_name"] ?? "");
        $ln = trim($row["last_name"] ?? "");
        $ex = trim($row["ext_name"] ?? "");

        $full_name = $fn . ($mn ? " " . substr($mn, 0, 1) . "." : "") . " " . $ln . ($ex ? " " . $ex : "");
        
        if (!empty(trim($full_name))) {
            $user_display_name = $full_name;
        }
        
        if (!empty($fn)) {
            $first_char = strtoupper(substr($fn, 0, 1));
        }
        $profile_filename = basename((string)($row['profile_pic'] ?? ''));
        if ($profile_filename !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $profile_filename)) {
            $user_profile_src = 'uploads/' . rawurlencode($profile_filename);
        }
    }
}

// ==========================================
// FETCH REAL DATA FOR STATS BAR
// ==========================================
$total_beneficiaries = 0;
$total_programs = 0;
$barangays_reached = 0;

$community_activities = [
    [
        'image' => 'img/peso-community-medt-2026.png',
        'program' => 'MSME',
        'title' => 'Employment and Micro-Enterprise Development Training',
        'date' => 'August 2026',
        'location' => 'SB Annex, Vinzons, Camarines Norte',
        'summary' => 'PESO Vinzons brought practical employment and micro-enterprise guidance closer to local residents through an organized community training session.',
        'alt' => 'PESO Vinzons facilitator leading Employment and Micro-Enterprise Development Training at the SB Annex'
    ],
    [
        'image' => 'img/752659025_2053404155260623_314073346839281248_n.jpg',
        'program' => 'SPES',
        'title' => 'SPES Payout',
        'date' => 'July 2026',
        'location' => '2nd Floor, Vinzons Municipal Hall',
        'summary' => 'Student beneficiaries gathered at the Municipal Hall for the scheduled Special Program for Employment of Students payout.',
        'alt' => 'Student beneficiaries attending the SPES payout at Vinzons Municipal Hall'
    ],
    [
        'image' => 'img/738512047_895211810293178_8484532229922374617_n.jpg',
        'program' => 'TUPAD',
        'title' => 'DOLE TUPAD Payout',
        'date' => 'July 2026',
        'location' => 'Vinzons Town Kiosk, Vinzons, Camarines Norte',
        'summary' => 'PESO Vinzons and DOLE coordinated the scheduled release of assistance to qualified TUPAD beneficiaries.',
        'alt' => 'DOLE representative addressing beneficiaries during the July TUPAD payout'
    ],
    [
        'image' => 'img/785363258_1417993690237714_6441540593083810708_n.jpg',
        'program' => 'MSME',
        'title' => 'MEDT Community Participants',
        'date' => 'August 2026',
        'location' => 'SB Annex, Vinzons, Camarines Norte',
        'summary' => 'Participants completed a community-based session focused on financial awareness, employment readiness, and practical livelihood development.',
        'alt' => 'Community participants during the Employment and Micro-Enterprise Development Training'
    ],
    [
        'image' => 'img/725657050_1033155005802300_8541529917400426415_n.jpg',
        'program' => 'SPES',
        'title' => 'SPES Pre-Deployment Orientation',
        'date' => '2026',
        'location' => 'SB Annex, Vinzons, Camarines Norte',
        'summary' => 'Student beneficiaries received workplace guidance and program reminders before beginning their assigned employment activities.',
        'alt' => 'SPES beneficiaries attending a pre-deployment orientation at the SB Annex'
    ],
    [
        'image' => 'img/729964762_1484262739662441_7283136521651950177_n.jpg',
        'program' => 'SPES',
        'title' => 'SPES Workplace Deployment',
        'date' => '2026',
        'location' => 'Vinzons, Camarines Norte',
        'summary' => 'The SPES program gives qualified students supervised work experience while supporting participating local offices and services.',
        'alt' => 'SPES participant completing an assigned office task using a laptop and records'
    ],
    [
        'image' => 'img/717582488_2035524683743332_4339653995246931800_n.jpg',
        'program' => 'TUPAD',
        'title' => 'Brigada TUPAD Community Update',
        'date' => '2026',
        'location' => 'Vinzons, Camarines Norte',
        'summary' => 'Brigada TUPAD participants supported community and school-readiness work as part of the temporary employment program.',
        'alt' => 'Brigada TUPAD participants gathered with their community work tools'
    ],
    [
        'image' => 'img/708502840_2080056692891523_5519123430955868083_n.jpg',
        'program' => 'TUPAD',
        'title' => 'Brigada TUPAD Orientation',
        'date' => 'May 2026',
        'location' => 'Vinzons Town Kiosk, Vinzons, Camarines Norte',
        'summary' => 'Beneficiaries attended an official orientation covering program responsibilities, schedules, and the next steps for their community assignments.',
        'alt' => 'Large group of beneficiaries during the Brigada TUPAD orientation'
    ],
    [
        'image' => 'img/709881308_2080055899558269_2853097079095622055_n.jpg',
        'program' => 'TUPAD',
        'title' => 'Brigada TUPAD Registration and Orientation',
        'date' => 'May 2026',
        'location' => 'Vinzons Town Kiosk, Vinzons, Camarines Norte',
        'summary' => 'PESO personnel assisted participants with attendance, registration, and the orientation process before deployment.',
        'alt' => 'Beneficiaries completing registration during the Brigada TUPAD orientation'
    ],
    [
        'image' => 'img/688108277_2058533188377207_2499809173069909329_n.jpg',
        'program' => 'TUPAD',
        'title' => 'DOLE TUPAD Payout',
        'date' => 'April 2026',
        'location' => 'Vinzons Town Kiosk, Vinzons, Camarines Norte',
        'summary' => 'Qualified TUPAD beneficiaries assembled for the scheduled payout coordinated by DOLE and PESO Vinzons.',
        'alt' => 'TUPAD beneficiaries and program partners during the April payout'
    ]
];

try {
    // Count approved beneficiary records only; pending and rejected applications
    // are not presented as residents already served by PESO.
    $b_query = $conn->query("SELECT COUNT(*) as total FROM beneficiaries WHERE approval_status = 'Approved'");
    if ($b_query) {
        $total_beneficiaries = (int)$b_query->fetch_assoc()['total'];
    }

    // Count active approved programs
    $p_query = $conn->query("SELECT COUNT(*) as total FROM programs
        WHERE approval_status = 'Approved'
          AND (UPPER(program_name) LIKE '%TUPAD%' OR UPPER(program_name) LIKE '%SPES%' OR UPPER(program_name) LIKE '%MSME%')
          AND LOWER(COALESCE(status, '')) <> 'completed'
          AND (end_date IS NULL OR end_date = '' OR end_date >= CURDATE())
          AND (start_date IS NULL OR end_date IS NULL OR end_date = '' OR end_date >= start_date)");
    if ($p_query) {
        $total_programs = (int)$p_query->fetch_assoc()['total'];
    }

    // Count the communities represented by beneficiary records.
    $barangay_query = $conn->query("SELECT COUNT(DISTINCT TRIM(barangay)) as total FROM beneficiaries WHERE approval_status = 'Approved' AND barangay IS NOT NULL AND TRIM(barangay) <> ''");
    if ($barangay_query) {
        $barangays_reached = (int)$barangay_query->fetch_assoc()['total'];
    }
} catch (Throwable $e) {
    // Fallback gracefully
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/pesologo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BENEPESO | About Us</title>
    <meta name="description" content="Learn how BENEPESO and PESO Vinzons connect residents to official employment, livelihood, student, and MSME support services.">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="home.css?v=17">
    <link rel="stylesheet" href="about.css?v=18">
    <link rel="stylesheet" href="frontend_polish.css?v=20260921">
    <link rel="stylesheet" href="beneficiary_responsive.css?v=10">
    <link rel="stylesheet" href="beneficiary_content_enhancements.css?v=1">
    <link rel="stylesheet" href="beneficiary_content_polish.css?v=9">
    <link rel="stylesheet" href="authenticated_experience.css?v=7">
    <link rel="stylesheet" href="beneficiary_mobile.css?v=10">
    <script src="frontend_polish.js?v=20260921" defer></script>
    <script src="beneficiary_content_polish.js?v=1" defer></script>
</head>
<body class="about-page">

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
      <a class="menu-item active" href="about.php">About</a>

      <div class="account-area" id="accountWrap">
        <?php if($is_logged_in): ?>
            <button class="account-button" id="accountButton" type="button" aria-expanded="false" aria-controls="accountDropdown">
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
        <?php else: ?>
            <a class="btn-login-nav" href="login.php">Login</a>
        <?php endif; ?>
      </div>
    </nav>
  </div>
</header>

<main class="page-wrap">
    <!-- HERO SECTION -->
    <section class="welcome-area about-hero-custom">
        <div class="welcome-inner content-wrap">
            <div class="welcome-left stagger-1">
                <div class="welcome-badge">
                    <span class="badge-dot"></span>
                    ABOUT BENEPESO &bull; PESO Vinzons
                </div>
                <h1 class="welcome-title">
                    Connecting <span class="welcome-highlight">Vinzons</span> to opportunity
                </h1>
                <p class="welcome-text">
                    One trusted resident portal for official PESO programs, secure applications, validated status updates, and clear guidance from the local employment service office.
                </p>
                <div class="about-hero-actions" aria-label="About page actions">
                    <a class="about-hero-primary" href="programs.php">Explore Programs <span aria-hidden="true">&rarr;</span></a>
                    <a class="about-hero-secondary" href="#peso-office">Visit Our Office</a>
                </div>
                <div class="about-hero-assurance"><span aria-hidden="true"></span> Official PESO Vinzons information and resident services</div>
            </div>
        </div>
    </section>

    <!-- LIVE STATS BAR -->
    <section class="stats-bar-section stagger-3">
        <div class="content-wrap">
            <div class="stats-glass-panel">
                <div class="stat-item">
                    <span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="m16 11 2 2 4-4"></path></svg></span>
                    <div class="stat-num-wrap">
                        <span class="stat-num counter" data-target="<?php echo $total_beneficiaries; ?>">0</span>
                        <?php if($total_beneficiaries > 1000): ?><span class="stat-plus">+</span><?php endif; ?>
                    </div>
                    <span class="stat-label">Approved Beneficiaries</span>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <span class="stat-icon stat-icon-gold" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="15" rx="2"></rect><path d="M8 5V3h8v2M3 11h18M9 11v2h6v-2"></path></svg></span>
                    <div class="stat-num-wrap">
                        <span class="stat-num counter" data-target="<?php echo $total_programs; ?>">0</span>
                    </div>
                    <span class="stat-label">Active Listings</span>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 10c0 5.5-8 11-8 11S4 15.5 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg></span>
                    <div class="stat-num-wrap">
                        <span class="stat-num counter" data-target="<?php echo $barangays_reached; ?>">0</span>
                    </div>
                    <span class="stat-label">Barangays Reached</span>
                </div>
            </div>
            <p class="stats-data-note">Verified service totals based on approved BENEPESO records maintained by PESO Vinzons.</p>
        </div>
    </section>

    <!-- MISSION & VISION (INTERACTIVE) -->
    <section class="content-section mission-section stagger-4">
        <div class="content-wrap">
            <div class="grid-two">
                <div class="about-card interactive-card-hover">
                    <div class="card-icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>
                    </div>
                    <h3>Our Mission</h3>
                    <p>To provide prompt, timely, and efficient delivery of employment services and exchange of labor market information for the residents of Vinzons, ensuring economic stability for every family.</p>
                </div>
                <div class="about-card interactive-card-hover">
                    <div class="card-icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </div>
                    <h3>Our Vision</h3>
                    <p>A community where every individual has access to meaningful employment and the skills necessary to thrive in a global economy through transparent and accessible government support.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CORE SERVICES (INTERACTIVE) -->
    <section class="content-section services-section stagger-5">
        <div class="content-wrap">
            <div class="section-title-wrap">
                <span class="section-eyebrow">One trusted service path</span>
                <h2>How BENEPESO Supports Residents</h2>
                <p>Official information, secure applications, and understandable next steps in one connected experience.</p>
            </div>
            <div class="grid-three">
                <div class="service-card interactive-service-hover">
                    <div class="service-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                    <span class="service-card-kicker">DISCOVER</span>
                    <h4>Find official opportunities</h4>
                    <p>Compare approved TUPAD, SPES, and MSME listings with their schedules, eligibility rules, and requirements.</p>
                </div>
                <div class="service-card interactive-service-hover">
                    <div class="service-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></div>
                    <span class="service-card-kicker">PREPARE</span>
                    <h4>Apply with one profile</h4>
                    <p>Use one registered resident profile to submit accurate information for the exact program batch you select.</p>
                </div>
                <div class="service-card interactive-service-hover">
                    <div class="service-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg></div>
                    <span class="service-card-kicker">FOLLOW</span>
                    <h4>Track validated updates</h4>
                    <p>Review recorded status changes, requirements received, official schedules, and your next required action.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- COMMUNITY ACTIVITY GALLERY -->
    <section class="content-section activity-gallery-section stagger-6" aria-labelledby="communityStoryTitle">
        <div class="content-wrap">
            <div class="activity-gallery-heading">
                <div>
                    <span class="section-eyebrow">Community in action</span>
                    <h2 id="communityStoryTitle">Programs delivered with purpose</h2>
                    <p>Explore documented PESO Vinzons activities. Select any photograph to review its program, date, location, and service context.</p>
                </div>
                <div class="activity-gallery-controls" aria-label="Activity gallery controls">
                    <button type="button" class="activity-gallery-arrow" id="activityPrev" aria-label="Show previous activity"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"></path></svg></button>
                    <button type="button" class="activity-gallery-arrow" id="activityNext" aria-label="Show next activity"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg></button>
                </div>
            </div>

            <div class="activity-gallery-footer">
                <div class="activity-gallery-status-wrap">
                    <span class="activity-gallery-status" id="activityGalleryStatus" aria-live="polite">Activity 1 of <?= count($community_activities) ?></span>
                    <span class="activity-gallery-progress" aria-hidden="true"><i></i></span>
                </div>
            </div>

            <div class="activity-gallery-frame">
                <div class="activity-gallery-track" id="activityTrack" tabindex="0" aria-label="PESO Vinzons activity photographs">
                    <?php foreach ($community_activities as $index => $activity): ?>
                        <button
                            type="button"
                            class="activity-gallery-card<?= $index === 0 ? ' is-active' : '' ?>"
                            data-activity-index="<?= $index ?>"
                            data-program="<?= htmlspecialchars($activity['program'], ENT_QUOTES, 'UTF-8') ?>"
                            data-title="<?= htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8') ?>"
                            data-date="<?= htmlspecialchars($activity['date'], ENT_QUOTES, 'UTF-8') ?>"
                            data-location="<?= htmlspecialchars($activity['location'], ENT_QUOTES, 'UTF-8') ?>"
                            data-summary="<?= htmlspecialchars($activity['summary'], ENT_QUOTES, 'UTF-8') ?>"
                            data-image="<?= htmlspecialchars($activity['image'], ENT_QUOTES, 'UTF-8') ?>"
                            aria-label="View details for <?= htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8') ?>">
                            <span class="activity-gallery-photo">
                                <img src="<?= htmlspecialchars($activity['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($activity['alt'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async" width="1080" height="720">
                                <span class="activity-program-badge"><?= htmlspecialchars($activity['program'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="activity-photo-action" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg> View details</span>
                            </span>
                            <span class="activity-gallery-card-copy">
                                <strong><?= htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <small><span><?= htmlspecialchars($activity['date'], ENT_QUOTES, 'UTF-8') ?></span><span><?= htmlspecialchars($activity['location'], ENT_QUOTES, 'UTF-8') ?></span></small>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </section>

    <!-- LEADERSHIP (INTERACTIVE MODAL) -->
    <section class="content-section leadership-section stagger-6">
        <div class="content-wrap">
            <div class="section-title-wrap">
                <span class="section-eyebrow">Public Service</span>
                <h2>Leadership &amp; Service Access</h2>
                <p>Meet the PESO Vinzons office leadership and review the official ways residents can request assistance.</p>
            </div>
            <div class="leadership-grid">
                <button type="button" class="team-card interactive-card" onclick="openManagerModal()" aria-haspopup="dialog" aria-controls="managerModal">
                    <div class="team-avatar">
                        <img src="img/rigor.jpg" alt="Rigor S. Brilliantes" onerror="this.onerror=null;this.src='img/default_user.svg'">
                    </div>
                    <h3>Rigor S. Brilliantes</h3>
                    <span class="team-role">PESO Manager, Vinzons</span>
                    
                    <div class="click-hint">
                        <span>View Profile</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </div>
                </button>

                <article class="achievement-panel service-access-panel">
                    <div class="achievement-heading">
                        <div class="achievement-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"></path><path d="m7 16 4-5 4 3 5-7"></path></svg>
                        </div>
                        <div>
                            <span class="achievement-kicker">Service Access</span>
                            <h3>How to Access PESO Services</h3>
                        </div>
                    </div>
                    <p class="achievement-copy">Choose the service channel that is practical for you. Never send passwords or unnecessary identity documents through public messages.</p>
                    <div class="achievement-metrics">
                        <div class="achievement-metric">
                            <strong>Online</strong>
                            <span>Profile, applications, and recorded status</span>
                        </div>
                        <div class="achievement-metric">
                            <strong>Call or text</strong>
                            <span>Questions and accessibility assistance</span>
                        </div>
                        <div class="achievement-metric">
                            <strong>Walk in</strong>
                            <span>Validated corrections and instructed submissions</span>
                        </div>
                    </div>
                    <p class="achievement-note">Bring original documents only when PESO instructs you to visit. Residents who cannot use email may call, text, message the official Facebook page, or visit during office hours.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="content-section public-service-section stagger-7 bp-content-module" aria-labelledby="publicServiceTitle">
        <div class="content-wrap">
            <div class="content-enhancement-heading">
                <span class="content-enhancement-eyebrow">Public service commitment</span>
                <h2 id="publicServiceTitle">Our Public Service Commitments</h2>
                <p>BENEPESO supports the PESO mandate through clear information, responsible record handling, and accessible assistance for every resident.</p>
            </div>
            <div class="public-service-grid">
                <article><h3>Clear official information</h3><p>Every listing is connected to an approved program batch, including its schedule, eligibility rules, available slots, and documentary requirements.</p></article>
                <article><h3>Responsible record handling</h3><p>Resident information is used for profiling, eligibility review, application processing, and official PESO follow-up within the authorized service workflow.</p></article>
                <article><h3>Human review and assistance</h3><p>Automated results remain preliminary. PESO personnel validate records, provide the official decision, and assist residents who need corrections or accessible service channels.</p></article>
            </div>
            <div class="partner-note"><strong>Program coordination:</strong> Services may be delivered with DOLE, TESDA, educational institutions, barangays, employers, and other authorized government partners, depending on the program.</div>
            <div class="service-mandate-note"><strong>Official mandate:</strong> PESO operates as a non-fee employment service facility that supports employment information, referral, placement, and related programs under the <a href="https://lawphil.net/statutes/repacts/ra2000/ra_8759_2000.html" target="_blank" rel="noopener noreferrer">Public Employment Service Office Act of 1999 (Republic Act No. 8759)</a>.</div>
        </div>
    </section>

    <!-- CONTACT, GOOGLE MAP & CAPSTONE NOTE -->
    <section class="content-section contact-section stagger-7" id="peso-office">
        <div class="content-wrap">
            <div class="contact-glass-panel">
                <div class="contact-info">
                    <span class="contact-eyebrow">PESO Vinzons</span>
                    <h3>Visit Our Office</h3>
                    <p>We are always ready to assist you with your employment and livelihood needs.</p>
                    <ul class="contact-list">
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            Vinzons Municipal Hall, Vinzons Avenue, Barangay II (Poblacion), Vinzons, Camarines Norte 4603
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            Mon - Fri, 8:00 AM to 5:00 PM
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2.1Z"></path></svg>
                            <a href="tel:+639479971186">Call or text +63 947 997 1186</a>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v16H4z"></path><path d="m4 6 8 7 8-7"></path></svg>
                            <a href="https://mail.google.com/mail/?view=cm&amp;fs=1&amp;to=lguvinzonspeso@gmail.com" target="_blank" rel="noopener noreferrer">lguvinzonspeso@gmail.com</a>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 0-10.4 8.9V14.6H8.3V12h2.3v-2c0-2.3 1.4-3.6 3.5-3.6 1 0 2.1.2 2.1.2v2.3H15c-1.1 0-1.5.7-1.5 1.4V12H16l-.4 2.6h-2.1v6.3A9 9 0 0 0 21 12Z"></path></svg>
                            <a href="https://www.facebook.com/peso.vinzons" target="_blank" rel="noopener noreferrer">Message PESO Vinzons on Facebook</a>
                        </li>
                    </ul>
                </div>
                
                <div class="contact-map">
                    <div class="map-card-header">
                        <span class="map-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 5.5-8 11-8 11S4 15.5 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg></span>
                        <span><strong>PESO Vinzons Office</strong><small>Vinzons Municipal Hall</small></span>
                        <span class="map-official-badge"><i aria-hidden="true"></i> Official location</span>
                    </div>
                    <div class="map-preview" aria-label="Interactive Street View of the PESO Vinzons office. Drag to look around and use the controls to zoom.">
                    <iframe
                        src="https://www.google.com/maps/embed?pb=!4v1726740000000!6m8!1m7!1sOvPqPsazfA3AyGTZSu559g!2m2!1d14.1738456!2d122.9076545!3f34.31473476451902!4f0.09888153299029057!5f0.7820865974627469"
                        title="Interactive Street View of Vinzons Municipal Hall on Vinzons Avenue"
                        width="100%" 
                        height="100%" 
                        style="border:0;"
                        allowfullscreen="" 
                        loading="lazy" 
                        tabindex="0"
                        referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                    </div>
                    <div class="map-card-footer">
                        <span>1 Vinzons Avenue &bull; Barangay II (Poblacion)</span>
                        <span class="map-interaction-hint"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M8 11V7a2 2 0 0 1 4 0v4-6a2 2 0 0 1 4 0v6-3a2 2 0 0 1 4 0v6c0 4-3 7-7 7h-1c-2.5 0-4.2-1.2-5.5-3L3 13.5a2 2 0 0 1 3-2.6L8 13"></path></svg> Drag to explore &bull; use controls to zoom</span>
                    </div>
                </div>
            </div>
            
            <div class="capstone-note service-feedback-note">
                <p><strong>Questions, corrections, or feedback?</strong> Contact PESO Vinzons during office hours so the responsible staff can review your concern. Never send passwords or unnecessary identity documents through public messages.</p>
            </div>
        </div>
    </section>
</main>

<!-- ACTIVITY DETAIL MODAL -->
<div class="activity-modal-overlay" id="activityModal" aria-hidden="true">
    <div class="activity-modal" role="dialog" aria-modal="true" aria-labelledby="activityModalTitle" aria-describedby="activityModalSummary">
        <button type="button" class="activity-modal-close" id="activityModalClose" aria-label="Close activity details">&times;</button>
        <div class="activity-modal-media">
            <span class="activity-modal-image-note">Official PESO Vinzons activity photograph</span>
            <img id="activityModalImage" src="img/peso-community-medt-2026.png" alt="PESO Vinzons community activity">
        </div>
        <div class="activity-modal-content">
            <span class="activity-modal-program" id="activityModalProgram"></span>
            <p class="activity-modal-eyebrow">Community activity record</p>
            <h2 id="activityModalTitle"></h2>
            <p id="activityModalSummary"></p>
            <dl class="activity-modal-meta">
                <div><dt>Date</dt><dd id="activityModalDate"></dd></div>
                <div><dt>Location</dt><dd id="activityModalLocation"></dd></div>
            </dl>
            <div class="activity-modal-footer">
                <span id="activityModalPosition"></span>
                <div>
                    <button type="button" id="activityModalPrev" aria-label="View previous activity"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"></path></svg></button>
                    <button type="button" id="activityModalNext" aria-label="View next activity"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg></button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =========================================
     MANAGER PROFILE MODAL
========================================= -->
<div class="modal-overlay" id="managerModal" aria-hidden="true">
    <div class="modal-box manager-modal" role="dialog" aria-modal="true" aria-labelledby="managerModalTitle">
        <button type="button" class="modal-close" onclick="closeManagerModal()" aria-label="Close manager profile">✕</button>
        <div class="manager-modal-header">
            <div class="manager-modal-avatar">
                <img src="img/rigor.jpg" alt="Rigor S. Brilliantes" onerror="this.onerror=null;this.src='img/default_user.svg'">
            </div>
        </div>
        <div class="manager-modal-body">
            <h3 id="managerModalTitle">Rigor S. Brilliantes</h3>
            <span class="team-role">PESO Manager</span>
            
            <div class="manager-quote">
                <svg class="quote-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/></svg>
                <p>"Dedicated to bridging the gap between the hardworking citizens of Vinzons and meaningful employment opportunities. Our office is open to serve, guide, and empower our local workforce."</p>
            </div>
            
            <button class="btn-primary" style="width: 100%; margin-top: 20px;" onclick="closeManagerModal()">Close Profile</button>
        </div>
    </div>
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
        // Dropdown Toggle
        const btn = document.getElementById('accountButton');
        const drop = document.getElementById('accountDropdown');
        
        if(btn && drop) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = drop.classList.toggle('show');
                btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
            document.addEventListener('click', function(e) {
                if(!drop.contains(e.target) && !btn.contains(e.target)) {
                    drop.classList.remove('show');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        }

        // Mobile Menu
        const menuBtn = document.getElementById('menuButton');
        const menuArea = document.getElementById('menuArea');
        if(menuBtn && menuArea) {
            menuBtn.addEventListener('click', function() {
                const isOpen = menuArea.classList.toggle('open');
                menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        }

        // Animated Counters
        const counters = document.querySelectorAll('.counter');
        const speed = 100; 

        counters.forEach(counter => {
            const updateCount = () => {
                const target = +counter.getAttribute('data-target');
                const count = +counter.innerText;
                const inc = target / speed;

                if (count < target && target > 0) {
                    counter.innerText = Math.ceil(count + inc);
                    setTimeout(updateCount, 20);
                } else {
                    counter.innerText = target;
                }
            };
            
            const observer = new IntersectionObserver((entries) => {
                if(entries[0].isIntersecting) {
                    updateCount();
                    observer.disconnect();
                }
            }, { threshold: 0.5 });
            
            observer.observe(counter);
        });

        const revealTargets = document.querySelectorAll('.stats-bar-section, .content-section');
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (reduceMotion || !('IntersectionObserver' in window)) {
            revealTargets.forEach(target => target.classList.add('is-visible'));
        } else {
            const revealObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.12 });

            revealTargets.forEach(target => {
                target.classList.add('about-reveal');
                revealObserver.observe(target);
            });
        }

        // Activity gallery and detail modal
        const activityTrack = document.getElementById('activityTrack');
        const activitySection = document.querySelector('.activity-gallery-section');
        const activityCards = activityTrack ? Array.from(activityTrack.querySelectorAll('.activity-gallery-card')) : [];
        const activityPrev = document.getElementById('activityPrev');
        const activityNext = document.getElementById('activityNext');
        const activityStatus = document.getElementById('activityGalleryStatus');
        const activityModal = document.getElementById('activityModal');
        const activityModalClose = document.getElementById('activityModalClose');
        let activeActivityIndex = 0;
        let activityModalIndex = 0;
        let activityModalTrigger = null;
        let activityAutoTimer = null;
        let galleryScrollFrame = null;
        const activityAutoDelay = 2600;

        const updateActivityStatus = (index) => {
            activeActivityIndex = (index + activityCards.length) % activityCards.length;
            activityCards.forEach((card, cardIndex) => {
                const isActive = cardIndex === activeActivityIndex;
                card.classList.toggle('is-active', isActive);
                card.classList.toggle('is-before', cardIndex < activeActivityIndex);
                card.classList.toggle('is-after', cardIndex > activeActivityIndex);
                if (isActive) card.setAttribute('aria-current', 'true');
                else card.removeAttribute('aria-current');
            });
            if (activityStatus) activityStatus.textContent = `Activity ${activeActivityIndex + 1} of ${activityCards.length}`;
        };

        const showActivityCard = (index, behavior = 'smooth') => {
            if (!activityCards.length) return;
            const nextIndex = (index + activityCards.length) % activityCards.length;
            updateActivityStatus(nextIndex);
            const targetCard = activityCards[nextIndex];
            const centeredLeft = targetCard.offsetLeft - ((activityTrack.clientWidth - targetCard.offsetWidth) / 2);
            const maximumLeft = Math.max(0, activityTrack.scrollWidth - activityTrack.clientWidth);
            activityTrack.scrollTo({
                left: Math.max(0, Math.min(centeredLeft, maximumLeft)),
                behavior
            });
        };

        const stopActivityAutoPlay = () => {
            if (activityAutoTimer) window.clearInterval(activityAutoTimer);
            activityAutoTimer = null;
            if (activitySection) activitySection.classList.remove('is-autoplaying');
        };

        const startActivityAutoPlay = () => {
            stopActivityAutoPlay();
            if (reduceMotion || activityCards.length < 2 || (activityModal && activityModal.classList.contains('show'))) return;
            if (activitySection) {
                void activitySection.offsetWidth;
                activitySection.classList.add('is-autoplaying');
            }
            activityAutoTimer = window.setInterval(() => showActivityCard(activeActivityIndex + 1), activityAutoDelay);
        };

        const renderActivityModal = (index) => {
            if (!activityCards.length) return;
            activityModalIndex = (index + activityCards.length) % activityCards.length;
            const activity = activityCards[activityModalIndex].dataset;
            const modalImage = document.getElementById('activityModalImage');
            modalImage.src = activity.image;
            modalImage.alt = activityCards[activityModalIndex].querySelector('img').alt;
            document.getElementById('activityModalProgram').textContent = activity.program;
            document.getElementById('activityModalTitle').textContent = activity.title;
            document.getElementById('activityModalSummary').textContent = activity.summary;
            document.getElementById('activityModalDate').textContent = activity.date;
            document.getElementById('activityModalLocation').textContent = activity.location;
            document.getElementById('activityModalPosition').textContent = `${activityModalIndex + 1} of ${activityCards.length}`;
        };

        const openActivityModal = (index, trigger) => {
            if (!activityModal) return;
            activityModalTrigger = trigger;
            renderActivityModal(index);
            activityModal.classList.add('show');
            activityModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            stopActivityAutoPlay();
            activityModalClose.focus();
        };

        const closeActivityModal = () => {
            if (!activityModal) return;
            activityModal.classList.remove('show');
            activityModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            if (activityModalTrigger && document.contains(activityModalTrigger)) activityModalTrigger.focus();
            startActivityAutoPlay();
        };

        if (activityCards.length) {
            updateActivityStatus(0);
            activityCards.forEach((card, index) => card.addEventListener('click', () => openActivityModal(index, card)));
            activityPrev.addEventListener('click', () => { showActivityCard(activeActivityIndex - 1); startActivityAutoPlay(); });
            activityNext.addEventListener('click', () => { showActivityCard(activeActivityIndex + 1); startActivityAutoPlay(); });
            activityTrack.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                    event.preventDefault();
                    showActivityCard(activeActivityIndex + (event.key === 'ArrowRight' ? 1 : -1));
                    startActivityAutoPlay();
                }
            });
            activityTrack.addEventListener('scroll', () => {
                if (galleryScrollFrame) cancelAnimationFrame(galleryScrollFrame);
                galleryScrollFrame = requestAnimationFrame(() => {
                    const trackCenter = activityTrack.scrollLeft + (activityTrack.clientWidth / 2);
                    let nearestIndex = 0;
                    let nearestDistance = Number.POSITIVE_INFINITY;
                    activityCards.forEach((card, index) => {
                        const cardCenter = card.offsetLeft + (card.offsetWidth / 2);
                        const distance = Math.abs(cardCenter - trackCenter);
                        if (distance < nearestDistance) { nearestDistance = distance; nearestIndex = index; }
                    });
                    updateActivityStatus(nearestIndex);
                });
            }, { passive: true });
            activityTrack.addEventListener('mouseenter', stopActivityAutoPlay);
            activityTrack.addEventListener('mouseleave', startActivityAutoPlay);
            activityTrack.addEventListener('focusin', stopActivityAutoPlay);
            activityTrack.addEventListener('focusout', startActivityAutoPlay);
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) stopActivityAutoPlay();
                else startActivityAutoPlay();
            });
            startActivityAutoPlay();
        }

        if (activityModal) {
            activityModalClose.addEventListener('click', closeActivityModal);
            document.getElementById('activityModalPrev').addEventListener('click', () => renderActivityModal(activityModalIndex - 1));
            document.getElementById('activityModalNext').addEventListener('click', () => renderActivityModal(activityModalIndex + 1));
            activityModal.addEventListener('click', (event) => {
                if (event.target === activityModal) closeActivityModal();
            });
        }

        const managerModal = document.getElementById('managerModal');
        if (managerModal) {
            managerModal.addEventListener('click', function(event) {
                if (event.target === managerModal) closeManagerModal();
            });
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && drop && btn && drop.classList.contains('show')) {
                drop.classList.remove('show');
                btn.setAttribute('aria-expanded', 'false');
                btn.focus();
                return;
            }

            if (event.key === 'Escape' && activityModal && activityModal.classList.contains('show')) {
                closeActivityModal();
                return;
            }

            if (event.key === 'Tab' && activityModal && activityModal.classList.contains('show')) {
                const focusable = Array.from(activityModal.querySelectorAll('button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'));
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
                return;
            }

            if (event.key === 'Escape' && managerModal && managerModal.classList.contains('show')) {
                closeManagerModal();
                return;
            }

            if (event.key === 'Tab' && managerModal && managerModal.classList.contains('show')) {
                const focusable = Array.from(managerModal.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'));
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
    });

    // Modal Control Functions
    let managerModalTrigger = null;

    function openManagerModal() {
        const modal = document.getElementById('managerModal');
        managerModalTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        modal.querySelector('.modal-close').focus();
    }
    
    function closeManagerModal() {
        const modal = document.getElementById('managerModal');
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (managerModalTrigger && document.contains(managerModalTrigger)) managerModalTrigger.focus();
    }
</script>
</body>
</html>
