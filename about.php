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

try {
    // Count total beneficiaries
    $b_query = $conn->query("SELECT COUNT(*) as total FROM beneficiaries");
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
    $barangay_query = $conn->query("SELECT COUNT(DISTINCT TRIM(barangay)) as total FROM beneficiaries WHERE barangay IS NOT NULL AND TRIM(barangay) <> ''");
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
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="home.css?v=16">
    <link rel="stylesheet" href="about.css?v=12">
    <link rel="stylesheet" href="frontend_polish.css?v=16">
    <link rel="stylesheet" href="beneficiary_responsive.css?v=10">
    <link rel="stylesheet" href="beneficiary_content_enhancements.css?v=1">
    <link rel="stylesheet" href="beneficiary_content_polish.css?v=9">
    <link rel="stylesheet" href="authenticated_experience.css?v=6">
<script src="frontend_polish.js?v=16" defer></script>
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
                    ESTABLISHED TO SERVE
                </div>
                <h1 class="welcome-title">
                    PESO <span class="welcome-highlight">Vinzons</span>
                </h1>
                <p class="welcome-text">
                    Empowering the local workforce by connecting residents with sustainable government programs, skills training, and meaningful employment opportunities.
                </p>
            </div>
        </div>
    </section>

    <!-- LIVE STATS BAR -->
    <section class="stats-bar-section stagger-3">
        <div class="content-wrap">
            <div class="stats-glass-panel">
                <div class="stat-item">
                    <div class="stat-num-wrap">
                        <span class="stat-num counter" data-target="<?php echo $total_beneficiaries; ?>">0</span>
                        <?php if($total_beneficiaries > 1000): ?><span class="stat-plus">+</span><?php endif; ?>
                    </div>
                    <span class="stat-label">Beneficiaries</span>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <div class="stat-num-wrap">
                        <span class="stat-num counter" data-target="<?php echo $total_programs; ?>">0</span>
                    </div>
                    <span class="stat-label">Active Listings</span>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <div class="stat-num-wrap">
                        <span class="stat-num counter" data-target="<?php echo $barangays_reached; ?>">0</span>
                    </div>
                    <span class="stat-label">Barangays Reached</span>
                </div>
            </div>
            <p class="stats-data-note">Live BENEPESO records as of <?= date('F j, Y') ?>. Figures may change as PESO validates and updates program records.</p>
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

    <!-- COMMUNITY IN ACTION -->
    <section class="content-section community-story-section stagger-6" aria-labelledby="communityStoryTitle">
        <div class="content-wrap">
            <article class="community-story-card">
                <div class="community-story-media">
                    <img src="img/peso-community-medt-2026.png" alt="PESO Vinzons staff facilitating Employment and Micro-Enterprise Development Training with community beneficiaries" loading="lazy" decoding="async">
                    <span class="community-story-live"><i aria-hidden="true"></i> Community in action</span>
                </div>
                <div class="community-story-copy">
                    <span class="community-story-eyebrow">Skills and livelihood support</span>
                    <h2 id="communityStoryTitle">Service that meets residents where they are</h2>
                    <p>PESO Vinzons brings practical employment and micro-enterprise guidance closer to local beneficiaries through coordinated training, clear information, and hands-on assistance.</p>
                    <div class="community-program-family" aria-label="Core PESO Vinzons programs">
                        <span class="community-program-family-label">Three connected programs</span>
                        <div class="community-program-group">
                            <a href="programs.php?search=TUPAD" class="community-program-chip program-tupad">
                                <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 8h12l1 12H5L6 8Z"></path><path d="M9 8V6a3 3 0 0 1 6 0v2M9 13h6"></path></svg></span>
                                <strong>TUPAD</strong><small>Emergency employment</small>
                            </a>
                            <a href="programs.php?search=SPES" class="community-program-chip program-spes">
                                <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 9 9-5 9 5-9 5-9-5Z"></path><path d="M7 12v4c3 2 7 2 10 0v-4M21 9v6"></path></svg></span>
                                <strong>SPES</strong><small>Student employment</small>
                            </a>
                            <a href="programs.php?search=MSME" class="community-program-chip program-msme">
                                <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 10v10h16V10M3 10l2-6h14l2 6"></path><path d="M3 10a3 3 0 0 0 5 2 3 3 0 0 0 4 0 3 3 0 0 0 4 0 3 3 0 0 0 5-2M9 20v-5h6v5"></path></svg></span>
                                <strong>MSME</strong><small>Livelihood support</small>
                            </a>
                        </div>
                    </div>
                    <dl class="community-story-details">
                        <div><dt>Featured activity</dt><dd>Employment/Micro-Enterprise &amp; Development Training</dd></div>
                        <div><dt>Date</dt><dd>August 27, 2026</dd></div>
                        <div><dt>Location</dt><dd>SB Annex, Vinzons, Camarines Norte</dd></div>
                    </dl>
                    <a class="community-story-link" href="https://www.facebook.com/photo.php?fbid=2159020488328476&amp;set=pb.100026616380327.-2207520000&amp;type=3" target="_blank" rel="noopener noreferrer">View the official PESO Vinzons post <span aria-hidden="true">&rarr;</span></a>
                </div>
            </article>
        </div>
    </section>

    <!-- LEADERSHIP (INTERACTIVE MODAL) -->
    <section class="content-section leadership-section stagger-6">
        <div class="content-wrap">
            <div class="section-title-wrap">
                <span class="section-eyebrow">Public Service</span>
                <h2>Leadership</h2>
                <p>Guiding the vision of a prosperous Vinzons.</p>
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
                <h2 id="publicServiceTitle">What You Can Expect from PESO Vinzons</h2>
                <p>BENEPESO supports the office's employment-service mandate by making program information, applications, and recorded updates easier for residents to access.</p>
            </div>
            <div class="public-service-grid">
                <article><h3>Who we serve</h3><p>Residents of the Municipality of Vinzons seeking employment assistance, temporary livelihood opportunities, student employment, skills support, or MSME profiling.</p></article>
                <article><h3>How we assist</h3><p>PESO reviews submitted records, coordinates program requirements and schedules, and provides the official decision or next instruction for each application.</p></article>
                <article><h3>Our service standard</h3><p>Applications are handled using the requirements and schedule of the selected program batch. Processing time may vary when partner-agency validation is required.</p></article>
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
                            <a href="mailto:lguvinzonspeso@gmail.com">lguvinzonspeso@gmail.com</a>
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
                    <div class="map-preview" aria-label="Map preview of the PESO Vinzons office">
                    <iframe
                        src="https://maps.google.com/maps?q=Vinzons%20Municipal%20Hall,%20Vinzons%20Avenue,%20Barangay%20II,%20Poblacion,%20Vinzons,%20Camarines%20Norte%204603&t=m&z=18&ie=UTF8&iwloc=&output=embed"
                        title="Street map showing Vinzons Municipal Hall on Vinzons Avenue"
                        width="100%" 
                        height="100%" 
                        style="border:0;"
                        allowfullscreen="" 
                        loading="lazy" 
                        tabindex="-1"
                        referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                    </div>
                    <div class="map-card-footer">
                        <span>Barangay II (Poblacion), Vinzons</span>
                        <a class="map-directions-link" href="https://www.google.com/maps/search/?api=1&amp;query=Vinzons+Municipal+Hall%2C+Vinzons+Avenue%2C+Barangay+II%2C+Poblacion%2C+Vinzons%2C+Camarines+Norte+4603" target="_blank" rel="noopener noreferrer">Get directions <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M7 17 17 7M8 7h9v9"></path></svg></a>
                    </div>
                </div>
            </div>
            
            <div class="capstone-note service-feedback-note">
                <p><strong>Questions, corrections, or feedback?</strong> Contact PESO Vinzons during office hours so the responsible staff can review your concern. Never send passwords or unnecessary identity documents through public messages.</p>
            </div>
        </div>
    </section>
</main>

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
        // Dropdown Toggle
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

        const managerModal = document.getElementById('managerModal');
        if (managerModal) {
            managerModal.addEventListener('click', function(event) {
                if (event.target === managerModal) closeManagerModal();
            });
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && managerModal && managerModal.classList.contains('show')) {
                closeManagerModal();
            }
        });
    });

    // Modal Control Functions
    function openManagerModal() {
        const modal = document.getElementById('managerModal');
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
    }
</script>
</body>
</html>
