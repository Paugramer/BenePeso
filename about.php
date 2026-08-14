<?php
session_start();
require "db.php";

// Check if user is logged in to show the account dropdown
$is_logged_in = isset($_SESSION["user_id"]);
$user_display_name = "User";
$first_char = "U";

if ($is_logged_in) {
    $user_id = (int)$_SESSION["user_id"];
    
    // Fetching individual name components
    $stmt = $conn->prepare("SELECT first_name, middle_name, last_name, ext_name FROM users WHERE user_id=? LIMIT 1");
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
    $p_query = $conn->query("SELECT COUNT(*) as total FROM programs WHERE approval_status = 'Approved'");
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
    <title>About Us | BENEPESO</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="home.css?v=10">
    <link rel="stylesheet" href="about.css?v=10">
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
      <a class="menu-item active" href="about.php">About</a>

      <div class="account-area" id="accountWrap">
        <?php if($is_logged_in): ?>
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
                    <span class="stat-label">Active Programs</span>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <div class="stat-num-wrap">
                        <span class="stat-num counter" data-target="<?php echo $barangays_reached; ?>">0</span>
                    </div>
                    <span class="stat-label">Barangays Reached</span>
                </div>
            </div>
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
                <span class="section-eyebrow">Community Support</span>
                <h2>Our Core Services</h2>
                <p>Dedicated to supporting the community through actionable employment initiatives.</p>
            </div>
            <div class="grid-three">
                <div class="service-card interactive-service-hover">
                    <div class="service-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                    <h4>Job Referrals</h4>
                    <p>Connecting skilled individuals with local and national employers actively seeking talent.</p>
                </div>
                <div class="service-card interactive-service-hover">
                    <div class="service-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></div>
                    <h4>Livelihood (TUPAD/SPES)</h4>
                    <p>Facilitating emergency employment and student assistance programs to provide financial relief.</p>
                </div>
                <div class="service-card interactive-service-hover">
                    <div class="service-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg></div>
                    <h4>Skills Training</h4>
                    <p>Partnering with TESDA and other agencies to equip residents with in-demand technical skills.</p>
                </div>
            </div>
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
                        <img src="img/rigor.jpg" alt="Rigor S. Brilliantes" onerror="this.src='img/default_user.png'">
                    </div>
                    <h3>Rigor S. Brilliantes</h3>
                    <span class="team-role">PESO Manager, Vinzons</span>
                    
                    <div class="click-hint">
                        <span>View Profile</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </div>
                </button>

                <article class="achievement-panel">
                    <div class="achievement-heading">
                        <div class="achievement-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"></path><path d="m7 16 4-5 4 3 5-7"></path></svg>
                        </div>
                        <div>
                            <span class="achievement-kicker">Measured Service</span>
                            <h3>PESO Vinzons Impact</h3>
                        </div>
                    </div>
                    <p class="achievement-copy">Current milestones recorded through BENEPESO reflect the office's continuing service across Vinzons.</p>
                    <div class="achievement-metrics">
                        <div class="achievement-metric">
                            <strong><?php echo number_format($total_beneficiaries); ?></strong>
                            <span>Beneficiary records</span>
                        </div>
                        <div class="achievement-metric">
                            <strong><?php echo number_format($total_programs); ?></strong>
                            <span>Approved programs</span>
                        </div>
                        <div class="achievement-metric">
                            <strong><?php echo number_format($barangays_reached); ?></strong>
                            <span>Barangays reached</span>
                        </div>
                    </div>
                    <p class="achievement-note">Built around accessible employment support, livelihood opportunities, and reliable beneficiary records.</p>
                </article>
            </div>
        </div>
    </section>

    <!-- CONTACT, GOOGLE MAP & CAPSTONE NOTE -->
    <section class="content-section contact-section stagger-7">
        <div class="content-wrap">
            <div class="contact-glass-panel">
                <div class="contact-info">
                    <span class="contact-eyebrow">PESO Vinzons</span>
                    <h3>Visit Our Office</h3>
                    <p>We are always ready to assist you with your employment and livelihood needs.</p>
                    <ul class="contact-list">
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            Municipality of Vinzons, Camarines Norte
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            Mon - Fri, 8:00 AM to 5:00 PM
                        </li>
                    </ul>
                </div>
                
                <div class="contact-map">
                    <iframe 
                        src="https://maps.google.com/maps?q=Municipal%20Hall,%20Vinzons,%20Camarines%20Norte&t=&z=16&ie=UTF8&iwloc=&output=embed" 
                        width="100%" 
                        height="100%" 
                        style="border:0; border-radius: 20px; min-height: 250px; box-shadow: var(--shadow-soft);" 
                        allowfullscreen="" 
                        loading="lazy" 
                        referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                </div>
            </div>
            
            <div class="capstone-note">
                <p><strong>BENEPESO</strong> was developed as a Capstone Project dedicated to digitalizing and streamlining the services of the Local Government Unit of Vinzons.</p>
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
                <img src="img/rigor.jpg" alt="Rigor S. Brilliantes" onerror="this.src='img/default_user.png'">
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
                menuArea.classList.toggle('open');
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
