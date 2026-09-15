<?php
require "auth.php"; // This handles session_start() and the routing logic
require "db.php";
check_user_role("user"); // Protects this page for standard users only

// If they pass all checks, they are a user. Continue loading page...
$user_id = (int)$_SESSION["user_id"];
$user_display_name = "User";
$first_char = "U";
$user_profile_src = '';

// Fetching individual name components based on your table structure
$stmt = $conn->prepare("SELECT first_name, middle_name, last_name, ext_name, profile_pic FROM users WHERE user_id=? LIMIT 1");
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

    $profile_filename = basename((string)($row['profile_pic'] ?? ''));
    if ($profile_filename !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $profile_filename)) {
        $user_profile_src = 'uploads/' . rawurlencode($profile_filename);
    }
}

$programs = [];
$has_programs_table = true;

try {
    $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $q = $conn->prepare("SELECT program_id, program_name, description, start_date, end_date, image_path
        FROM programs
        WHERE approval_status = 'Approved'
          AND LOWER(COALESCE(status, '')) <> 'completed'
          AND (end_date IS NULL OR end_date = '0000-00-00' OR end_date >= ?)
          AND (start_date IS NULL OR end_date IS NULL OR end_date = '0000-00-00' OR end_date >= start_date)
        ORDER BY CASE
                   WHEN start_date IS NULL OR start_date = '0000-00-00' OR start_date <= ? THEN 0
                   ELSE 1
                 END,
                 COALESCE(NULLIF(end_date, '0000-00-00'), '9999-12-31') ASC,
                 program_id DESC
        LIMIT 3");
    if ($q) {
        $q->bind_param('ss', $today, $today);
        $q->execute();
        $result = $q->get_result();
        while ($p = $result->fetch_assoc()) $programs[] = $p;
        $q->close();
    }
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

    <link rel="stylesheet" href="home.css?v=15" />
<link rel="stylesheet" href="frontend_polish.css?v=12">
    <link rel="stylesheet" href="beneficiary_responsive.css?v=10">
    <script src="frontend_polish.js?v=8" defer></script>
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
                    <a href="profile.php">My Profile</a>
                    <a href="verification.php">Verification</a>
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
                <a class="btn-verify" href="verification.php">Verify Status</a>
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

<section class="bp-service-snapshot-shell content-wrap" id="beneficiaryServiceSnapshot" aria-live="polite" hidden>
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

<section class="quick-access-area reveal">
    <div class="content-wrap">
        <div class="quick-area">
            <div class="quick-top">
                <div class="quick-title">Quick Access</div>
                <div class="quick-tag">User</div>
            </div>

            <div class="quick-links">
                <a class="quick-link" href="programs.php">
                    <div class="quick-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="17" rx="2"></rect><path d="M9 4V2h6v2M9 9h6M9 13h6M9 17h4"></path></svg></div>
                    <div>
                        <div class="quick-name">Programs</div>
                        <div class="quick-desc">See active programs</div>
                    </div>
                </a>

                <a class="quick-link" href="verification.php">
                    <div class="quick-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="m8 12 3 3 5-6"></path></svg></div>
                    <div>
                        <div class="quick-name">Verification</div>
                        <div class="quick-desc">Check eligibility</div>
                    </div>
                </a>

                <a class="quick-link" href="profile.php">
                    <div class="quick-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></div>
                    <div>
                        <div class="quick-name">Profile</div>
                        <div class="quick-desc">Update your info</div>
                    </div>
                </a>

                <a class="quick-link" href="about.php">
                    <div class="quick-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v6M12 7h.01"></path></svg></div>
                    <div>
                        <div class="quick-name">About</div>
                        <div class="quick-desc">Learn more</div>
                    </div>
                </a>
            </div>

            <div class="quick-note">
                <b>Tip:</b> Keep your email active for updates.
            </div>
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
                        <img class="program-image" src="<?php echo htmlspecialchars($program_image); ?>" alt="<?php echo htmlspecialchars($program_title); ?>" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='img/pesologo.png';">
                        <div class="program-top">
                            <span class="program-tag">Program</span>
                            <span class="program-date">
                                <?php echo !empty($p["end_date"]) && $p["end_date"] !== '0000-00-00'
                                    ? 'Open until ' . htmlspecialchars(date("M d, Y", strtotime($p["end_date"])))
                                    : (!empty($p["start_date"]) ? 'Starts ' . htmlspecialchars(date("M d, Y", strtotime($p["start_date"]))) : "Schedule available"); ?>
                            </span>
                        </div>

                        <h3 class="program-title"><?php echo htmlspecialchars($program_title); ?></h3>
                        <p class="program-text">
                            <?php
                                $desc = trim($p["description"] ?? "");
                                echo htmlspecialchars(mb_strimwidth($desc, 0, 120, "..."));
                            ?>
                        </p>

                        <div class="program-btn">View details</div>
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
        <div class="area-head">
            <div>
                <h2 class="area-title">Before You Apply</h2>
                <p class="area-sub">Three practical steps help PESO review your application without unnecessary delays.</p>
            </div>
        </div>

        <div class="info-list">
            <div class="info-card reveal" style="transition-delay: 0.1s;">
                <div class="info-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="4" y="3" width="16" height="18" rx="2" />
                        <circle cx="9" cy="9" r="2" />
                        <path d="M7 15c.8-1.4 2.2-2 4-2M14 8h3M14 12h3M14 16h3" />
                    </svg>
                </div>
                <div class="info-title">Review Your Profile</div>
                <div class="info-text">Keep your name, address, contact number, and registered email complete and current.</div>
            </div>
            <div class="info-card reveal" style="transition-delay: 0.2s;">
                <div class="info-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 5h11M9 12h11M9 19h11" />
                        <path d="m4 5 1 1 2-2M4 12l1 1 2-2M4 19l1 1 2-2" />
                    </svg>
                </div>
                <div class="info-title">Check Program Rules</div>
                <div class="info-text">Read the age, residency, household, and program-specific eligibility requirements before submitting.</div>
            </div>
            <div class="info-card reveal" style="transition-delay: 0.3s;">
                <div class="info-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z" />
                        <path d="M10 21h4" />
                    </svg>
                </div>
                <div class="info-title">Follow Official Updates</div>
                <div class="info-text">Use your program progress page for the latest recorded step and keep your email active for PESO instructions.</div>
            </div>
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
