<?php
require "auth.php"; // This handles session_start() and the routing logic
check_user_role("user"); // Protects this page for standard users only

// If they pass all checks, they are a user. Continue loading page...
require "db.php"; 

$user_id = (int)$_SESSION["user_id"];
$user_display_name = "User";
$first_char = "U";

// Fetching individual name components based on your table structure
$stmt = $conn->prepare("SELECT first_name, middle_name, last_name, ext_name FROM users WHERE user_id=? LIMIT 1");
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
}

$programs = [];
$has_programs_table = true;

try {
    // If you don't have programs table yet, it will fallback automatically
    $q = $conn->query("SELECT program_id, title, description, start_date, image_path FROM programs ORDER BY program_id DESC LIMIT 6");
    if ($q) {
        while ($p = $q->fetch_assoc()) $programs[] = $p;
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

    <link rel="stylesheet" href="home.css?v=10" />
    <link rel="stylesheet" href="frontend_polish.css?v=1">
    <script src="frontend_polish.js?v=1" defer></script>
</head>
<body>

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

        <button class="menu-button" id="menuButton" type="button" aria-label="Toggle menu">
            <span></span><span></span><span></span>
        </button>

        <nav class="menu-area" id="menuArea">
            <a class="menu-item active" href="home.php">Home</a>
            <a class="menu-item" href="programs.php">Programs</a>
            <a class="menu-item" href="about.php">About</a>

            <div class="account-area" id="accountWrap">
                <button class="account-button" id="accountButton" type="button">
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
                    <div class="stat-icon" style="color: #f39c12;">🔒</div>
                    <div>
                        <div class="stat-title">Secure</div>
                        <div class="stat-sub">Protected Access</div>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon" style="color: #e67e22;">⚡</div>
                    <div>
                        <div class="stat-title">Fast</div>
                        <div class="stat-sub">Live Verification</div>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon" style="color: #8e44ad;">👤</div>
                    <div>
                        <div class="stat-title">Easy</div>
                        <div class="stat-sub">User Profiling</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="welcome-right stagger-2">
            <div class="hero-graphic">
                <div class="graphic-card">
                    <div class="graphic-avatar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    </div>
                    <div class="graphic-lines">
                        <div class="line line-long"></div>
                        <div class="line line-medium"></div>
                    </div>
                    <div class="graphic-btn">VERIFIED</div>
                </div>
                <div class="floating-bubble bubble-1">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#2ecc71" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </div>
                <div class="floating-bubble bubble-2">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#bdc3c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                </div>
            </div>
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
                    <div class="quick-icon">📋</div>
                    <div>
                        <div class="quick-name">Programs</div>
                        <div class="quick-desc">See active programs</div>
                    </div>
                </a>

                <a class="quick-link" href="verification.php">
                    <div class="quick-icon">✅</div>
                    <div>
                        <div class="quick-name">Verification</div>
                        <div class="quick-desc">Check eligibility</div>
                    </div>
                </a>

                <a class="quick-link" href="profile.php">
                    <div class="quick-icon">👤</div>
                    <div>
                        <div class="quick-name">Profile</div>
                        <div class="quick-desc">Update your info</div>
                    </div>
                </a>

                <a class="quick-link" href="about.php">
                    <div class="quick-icon">ℹ️</div>
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
                <h2 class="area-title">Latest Programs</h2>
                <p class="area-sub">Check ongoing and updated PESO services.</p>
            </div>
            <a class="see-more" href="programs.php">See all →</a>
        </div>

        <div class="program-list">
            <?php if ($has_programs_table && count($programs) > 0): ?>
                <?php foreach($programs as $p): ?>
                    <a href="program_view.php?id=<?php echo (int)$p["program_id"]; ?>" class="program-card reveal">
                        <?php
                            $program_title = trim($p["title"] ?? "Program");
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
                                <?php echo !empty($p["start_date"]) ? htmlspecialchars(date("M d, Y", strtotime($p["start_date"]))) : "Updated"; ?>
                            </span>
                        </div>

                        <h3 class="program-title"><?php echo htmlspecialchars($p["title"]); ?></h3>
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
                <a href="programs.php" class="program-card reveal" style="transition-delay: 0.1s;">
                    <img class="program-image" src="img/tupads.png" alt="TUPAD emergency employment program" loading="lazy" decoding="async">
                    <div class="program-top"><span class="program-tag">Program</span><span class="program-date">TUPAD</span></div>
                    <h3 class="program-title">Emergency Employment</h3>
                    <p class="program-text">DOLE's Tulong Panghanapbuhay sa Ating Disadvantaged/Displaced Workers.</p>
                    <div class="program-btn">View details</div>
                </a>

                <a href="programs.php" class="program-card reveal" style="transition-delay: 0.2s;">
                    <img class="program-image" src="img/spes.png" alt="SPES student employment program" loading="lazy" decoding="async">
                    <div class="program-top"><span class="program-tag">Program</span><span class="program-date">SPES</span></div>
                    <h3 class="program-title">Student Employment</h3>
                    <p class="program-text">Special Program for Employment of Students providing temporary employment.</p>
                    <div class="program-btn">View details</div>
                </a>

                <a href="programs.php" class="program-card reveal" style="transition-delay: 0.3s;">
                    <img class="program-image" src="img/msme.png" alt="MSME profiling program" loading="lazy" decoding="async">
                    <div class="program-top"><span class="program-tag">Program</span><span class="program-date">MSME</span></div>
                    <h3 class="program-title">MSME Profiling</h3>
                    <p class="program-text">Assessing local businesses to provide targeted livelihood assistance and capacity building.</p>
                    <div class="program-btn">View details</div>
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="info-area reveal">
    <div class="content-wrap">
        <div class="area-head">
            <div>
                <h2 class="area-title">Why BENEPESO?</h2>
                <p class="area-sub">Designed for faster processing and less paperwork.</p>
            </div>
        </div>

        <div class="info-list">
            <div class="info-card reveal" style="transition-delay: 0.1s;">
                <div class="info-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" />
                        <path d="m9 12 2 2 4-4" />
                    </svg>
                </div>
                <div class="info-title">Secure Access</div>
                <div class="info-text">Safe login and verification features for your data protection.</div>
            </div>
            <div class="info-card reveal" style="transition-delay: 0.2s;">
                <div class="info-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
                    </svg>
                </div>
                <div class="info-title">Faster Verification</div>
                <div class="info-text">Quickly check eligibility and participation status.</div>
            </div>
            <div class="info-card reveal" style="transition-delay: 0.3s;">
                <div class="info-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                        <path d="M14 2v6h6" />
                        <path d="m9 15 2 2 4-4" />
                    </svg>
                </div>
                <div class="info-title">Less Paperwork</div>
                <div class="info-text">Digital profiling helps reduce manual forms.</div>
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
                menuArea.classList.toggle('open');
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
