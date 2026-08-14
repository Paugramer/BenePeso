<?php
session_start();
require "db.php";

/** * SMART REDIRECTION 
 * If Staff or Admin is already logged in, skip the public page.
 */
if (isset($_SESSION["role"])) {
    if ($_SESSION["role"] === "admin") {
        header("Location: admin_dashboard.php");
        exit();
    } elseif ($_SESSION["role"] === "peso_staff") {
        header("Location: peso_staff_dashboard.php");
        exit();
    } elseif ($_SESSION["role"] === "user") {
        header("Location: home.php"); 
        exit();
    }
}

// =========================
// FETCH APPROVED PROGRAMS WITH DYNAMIC SLOTS
// =========================
$limit = 6; 
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : "";

// Only show approved programs that are still open and have available slots.
$where_clauses = [
    "p.approval_status = 'Approved'",
    "LOWER(COALESCE(p.status, '')) <> 'completed'",
    "(NULLIF(p.end_date, '0000-00-00') IS NULL OR p.end_date >= CURDATE())",
    "COALESCE(p.slots, 0) > (SELECT COUNT(*) FROM beneficiaries b_slots WHERE b_slots.program_id = p.program_id AND b_slots.approval_status = 'Approved')"
];
$params = [];
$types = "";

// PHP Server-Side Search Logic
if (!empty($search_query)) {
    $search_param = "%$search_query%";
    // FIX: Now ONLY searches the program title, ignoring the description.
    $where_clauses[] = "(p.program_name LIKE ?)";
    array_push($params, $search_param);
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// Get Total Count for Pagination
$count_sql = "SELECT COUNT(*) as total FROM programs p WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_rows = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, ceil($total_rows / $limit));
$count_stmt->close();

// Keep pagination valid when a previously visible program becomes full or ends.
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;

// Fetch Paginated Programs WITH Dynamic Remaining Slots Calculation
$sql = "SELECT p.*, 
        (p.slots - (SELECT COUNT(*) FROM beneficiaries b2 WHERE b2.program_id = p.program_id AND b2.approval_status = 'Approved')) AS remaining_slots 
        FROM programs p 
        WHERE $where_sql 
        ORDER BY p.created_at DESC 
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$fetch_params = $params;
array_push($fetch_params, $limit, $offset);
$fetch_types = $types . "ii";

$stmt->bind_param($fetch_types, ...$fetch_params);
$stmt->execute();
$programs_result = $stmt->get_result();

// Helper function to build pagination links
function build_page_link($p) {
    $query_params = $_GET;
    $query_params['page'] = $p;
    return '?' . http_build_query($query_params);
}

// Helper to format dates safely
function format_date($date_str) {
    if (empty($date_str)) return "TBA";
    return date("F d, Y", strtotime($date_str));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/pesologo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to BENEPESO</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="home.css?v=10">
    
    <link rel="stylesheet" href="index.css?v=15">
    <link rel="stylesheet" href="frontend_polish.css?v=1">
    <script src="frontend_polish.js?v=1" defer></script>
</head>
<body>

<div class="page-wrap">
    <header class="topbar">
      <div class="topbar-inner">
        <a class="brand-area" href="index.php">
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
          <a class="menu-item active" href="javascript:void(0)" id="programsBtn" onclick="showSection('programs-section')">Programs</a>
          <a class="menu-item" href="javascript:void(0)" id="aboutBtn" onclick="showSection('about-section')">About</a>
          <a class="btn-login" href="login.php">Login / Register</a>
        </nav>
      </div>
    </header>

    <main class="content-container">
        
        <div id="programs-section" class="page-section">
            <section class="search-hero">
                <div class="welcome-inner centered-hero">
                    <a class="welcome-badge stagger-1" href="#available-programs">
                        <span class="badge-dot"></span>
                        EXPLORE OPPORTUNITIES
                    </a>
                    <h1 class="welcome-title stagger-2">Discover PESO <span class="welcome-highlight">Programs</span></h1>
                    <p class="centered-text stagger-3">Browse available government programs, trainings, and employment opportunities in Vinzons.</p>
                    
                    <form action="index.php" method="GET" class="v-search-box-centered stagger-4">
                        <div class="input-wrapper">
                            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px; height:20px; color:var(--muted);"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input type="text" name="search" placeholder="Search for programs (e.g. TUPAD, SPES)..." value="<?= htmlspecialchars($search_query) ?>" style="flex:1;">
                            <button type="submit" class="btn-main">Search</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="content-wrap results-area reveal" id="available-programs">
                <div class="section-heading">
                    <div class="section-heading-copy">
                        <div class="section-heading-meta">
                            <span class="section-kicker">Official PESO Directory</span>
                            <span class="listing-count" aria-label="<?= $total_rows ?> available program listings">
                                <strong><?= $total_rows ?></strong>
                                <span>Open listings</span>
                            </span>
                        </div>
                        <h2>Available Programs</h2>
                        <p>Showing page <?= $page ?> of <?= $total_pages ?></p>
                    </div>
                </div>

                <?php if ($programs_result && $programs_result->num_rows > 0): ?>
                    <div class="program-grid program-count-<?= min(6, (int)$programs_result->num_rows) ?>" id="programGridContainer">
                        <?php 
                        $delay = 0.1;
                        while($row = $programs_result->fetch_assoc()): 
                            $img = !empty($row['image_path']) ? htmlspecialchars($row['image_path']) : 'img/pesologo.png';
                            $remaining_slots = max(0, (int)$row['remaining_slots']);
                            
                            $modalData = htmlspecialchars(json_encode([
                                'title' => $row['program_name'],
                                'code' => $row['program_code'],
                                'slots' => $remaining_slots, 
                                'venue' => $row['venue'],
                                'start' => format_date($row['start_date']),
                                'end' => format_date($row['end_date']),
                                'desc' => $row['description'],
                                'eligibility' => $row['eligibility'],
                                'reqs' => $row['requirements'],
                                'img' => $img
                            ]), ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="program-card reveal" style="transition-delay: <?= $delay ?>s;" data-program="<?= $modalData ?>" onclick="openProgramModal(this)">
                                <div class="card-img-wrapper">
                                    <img src="<?= $img ?>" alt="Program Image" class="card-img" onerror="this.onerror=null; this.src='img/pesologo.png';">
                                    <div class="program-image-badges" aria-hidden="true">
                                        <span class="program-code-badge"><?= htmlspecialchars($row['program_code']) ?></span>
                                        <span class="program-slot-badge <?= $remaining_slots <= 0 ? 'is-full' : '' ?>">
                                            <?= $remaining_slots > 0 ? number_format($remaining_slots) . ' slots' : 'Full' ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="card-content">
                                    <h3 class="card-title"><?= htmlspecialchars($row['program_name']) ?></h3>
                                    <p class="card-excerpt">
                                        <?= htmlspecialchars(mb_strimwidth($row['description'] ?? 'No description provided.', 0, 100, "...")) ?>
                                    </p>
                                    <div class="card-meta">
                                        <span>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                                            Slots: <?= $remaining_slots ?>
                                        </span>
                                        <span>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                            <?= format_date($row['start_date']) ?>
                                        </span>
                                    </div>
                                    <div class="card-footer-info">
                                        <div class="program-btn">View Details</div>
                                    </div>
                                </div>
                            </div>
                        <?php 
                        $delay += 0.1;
                        endwhile; 
                        ?>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-container">
                            <?php if ($page > 1): ?>
                                <a href="<?= build_page_link($page - 1) ?>" class="page-btn prev">← Prev</a>
                            <?php else: ?>
                                <span class="page-btn prev disabled">← Prev</span>
                            <?php endif; ?>

                            <div class="page-numbers">
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="<?= build_page_link($i) ?>" class="page-num <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                            </div>

                            <?php if ($page < $total_pages): ?>
                                <a href="<?= build_page_link($page + 1) ?>" class="page-btn next">Next →</a>
                            <?php else: ?>
                                <span class="page-btn next disabled">Next →</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="no-results-wrapper">
                        <div class="no-results-card">
                            <div class="no-results-icon">
                                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="9" y1="9" x2="13" y2="13"></line><line x1="13" y1="9" x2="9" y2="13"></line></svg>
                            </div>
                            <h3>No Programs Found</h3>
                            <p>We couldn't find any approved programs matching your search.</p>
                            <?php if(!empty($search_query)): ?>
                                <a href="index.php" class="btn-outline" style="margin-top: 15px;">Clear Search</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div id="about-section" class="page-section" style="display:none;">
            <section class="content-wrap about-hero reveal">
                <div class="about-hero-inner">
                    <span class="section-kicker about-kicker">Public Service Platform</span>
                    <h1 class="about-title">About <span class="welcome-highlight">BENEPESO</span></h1>
                    <p class="about-description">
                        The Beneficiary Profiling, Eligibility, and Verification System (BENEPESO) is a dedicated digital initiative for the 
                        <b>Public Employment Service Office (PESO)</b> of Vinzons, Camarines Norte. Our platform ensures that government 
                        programs like TUPAD, SPES, and MSME are distributed fairly and transparently.
                    </p>
                    
                    <div class="about-grid">
                        <div class="about-card reveal" style="transition-delay: 0.1s;">
                            <div class="about-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                            </div>
                            <h4>Transparency</h4>
                            <p>Clear visibility of available programs and standard eligibility tracking.</p>
                        </div>
                        <div class="about-card reveal" style="transition-delay: 0.2s;">
                            <div class="about-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </div>
                            <h4>Inclusivity</h4>
                            <p>Reaching all barangays within Vinzons for fair distribution of services.</p>
                        </div>
                        <div class="about-card reveal" style="transition-delay: 0.3s;">
                            <div class="about-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                            </div>
                            <h4>Efficiency</h4>
                            <p>Fast, reliable, and secure online application process.</p>
                        </div>
                    </div>
                    
                    <button onclick="showSection('programs-section')" class="btn-alt about-back-btn">Back to Programs</button>
                </div>
            </section>
        </div>
    </main>

    <footer class="site-footer">
      <div class="content-wrap footer-grid">
        <div class="footer-brand">
          <img class="footer-logo" src="img/pesologo.png" alt="PESO Logo" onerror="this.style.display='none'">
          <div class="brand-text-footer">
            <div class="footer-title">BENEPESO</div>
            <div class="footer-sub">PESO Vinzons &bull; Beneficiary Profiling &amp; Verification</div>
          </div>
        </div>

        <div class="footer-col">
          <div class="footer-head">Links</div>
          <a href="index.php">Home</a>
          <a href="javascript:void(0)" onclick="showSection('programs-section')">Programs</a>
          <a href="javascript:void(0)" onclick="showSection('about-section')">About</a>
          <a href="login.php">Login / Register</a>
          <a href="privacy_notice.php">Privacy Notice</a>
        </div>

        <div class="footer-col">
          <div class="footer-head">Office</div>
          <div class="footer-text">Municipality of Vinzons, Camarines Norte</div>
          <div class="footer-text">Public Employment Service Office (PESO)</div>
        </div>
      </div>

      <div class="content-wrap footer-bottom">
        <div class="footer-copy">&copy; <?php echo date("Y"); ?> BENEPESO &bull; PESO Vinzons</div>
        <div class="footer-mini">Republic of the Philippines &bull; Province of Camarines Norte</div>
      </div>
    </footer>
</div>

<div class="modal-overlay" id="programModalOverlay">
    <div class="modal-landscape">
        <div class="modal-split">
            <div class="modal-left">
                <div class="modal-left-img-wrapper">
                    <img id="m_img" src="" alt="Program Image" onerror="this.onerror=null; this.src='img/pesologo.png';">
                </div>
                <div class="modal-left-content">
                    <h2 id="m_title">Program Title</h2>
                    <p class="modal-code" id="m_code">Code: ---</p>
                </div>
            </div>
            
            <div class="modal-right">
                <button class="modal-close-btn" onclick="closeModals()">✕</button>
                <div class="m-scroll-area">
                    <div class="m-section">
                        <h4>Description</h4>
                        <p id="m_desc"></p>
                    </div>
                    
                    <div class="m-grid">
                        <div class="m-grid-item">
                            <div class="m-grid-header">
                                <svg class="m-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                                <span class="m-label">Available Slots</span>
                            </div>
                            <span class="m-value" id="m_slots"></span>
                        </div>
                        <div class="m-grid-item">
                            <div class="m-grid-header">
                                <svg class="m-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                <span class="m-label">Venue</span>
                            </div>
                            <span class="m-value" id="m_venue"></span>
                        </div>
                        <div class="m-grid-item">
                            <div class="m-grid-header">
                                <svg class="m-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                <span class="m-label">Start Date</span>
                            </div>
                            <span class="m-value" id="m_start"></span>
                        </div>
                        <div class="m-grid-item">
                            <div class="m-grid-header">
                                <svg class="m-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                <span class="m-label">End Date</span>
                            </div>
                            <span class="m-value" id="m_end"></span>
                        </div>
                    </div>
                    
                    <div class="m-section">
                        <h4>Eligibility</h4>
                        <p id="m_eligibility"></p>
                    </div>
                    <div class="m-section">
                        <h4>Requirements</h4>
                        <p id="m_reqs"></p>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button class="btn-main full-width" onclick="clickApply()">Apply for this Program</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="loginWarningOverlay" style="z-index: 2000;">
    <div class="modal-box small-modal">
        <button class="modal-close-btn" onclick="closeWarning()">✕</button>
        <div class="modal-icon-warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 50px; height: 50px;">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>
        <h3>Authentication Required</h3>
        <p>You need to log in to your account or register a new account before you can apply for programs.</p>
        <div class="modal-actions-centered">
            <button class="btn-outline" onclick="closeWarning()">Cancel</button>
            <button class="btn-main" onclick="window.location.href='login.php'">Go to Login</button>
        </div>
    </div>
</div>

<script>
    function showSection(sectionId) {
        document.querySelectorAll('.page-section').forEach(section => {
            section.style.display = 'none';
        });
        document.getElementById(sectionId).style.display = 'block';
        
        document.querySelectorAll('.menu-item').forEach(btn => {
            btn.classList.remove('active');
        });
        
        if(sectionId === 'about-section') {
            document.getElementById('aboutBtn').classList.add('active');
        } else if (sectionId === 'programs-section') {
            document.getElementById('programsBtn').classList.add('active');
        }
        
        window.scrollTo({top: 0, behavior: 'smooth'});
    }

    // Modal Logic
    const programModal = document.getElementById('programModalOverlay');
    const warningModal = document.getElementById('loginWarningOverlay');

    function openProgramModal(card) {
        const data = JSON.parse(card.getAttribute('data-program'));
        
        document.getElementById('m_img').src = data.img;
        document.getElementById('m_title').textContent = data.title;
        document.getElementById('m_code').textContent = 'Code: ' + (data.code || 'N/A');
        
        document.getElementById('m_desc').textContent = data.desc || 'No description provided.';
        document.getElementById('m_slots').textContent = data.slots || '0';
        document.getElementById('m_venue').textContent = data.venue || 'TBA';
        document.getElementById('m_start').textContent = data.start;
        document.getElementById('m_end').textContent = data.end;
        document.getElementById('m_eligibility').textContent = data.eligibility || 'None specified.';
        document.getElementById('m_reqs').textContent = data.reqs || 'None specified.';

        programModal.classList.add('show');
    }

    function closeModals() {
        programModal.classList.remove('show');
    }

    function clickApply() {
        warningModal.classList.add('show');
    }

    function closeWarning() {
        warningModal.classList.remove('show');
    }

    window.onclick = function(event) {
        if (event.target == programModal) {
            closeModals();
        }
        if (event.target == warningModal) {
            closeWarning();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Mobile Menu Toggle
        const menuBtn = document.getElementById('menuButton');
        const menuArea = document.getElementById('menuArea');
        if(menuBtn && menuArea) {
            menuBtn.addEventListener('click', function() {
                menuArea.classList.toggle('open');
            });
        }

        // Scroll Reveal Animation Logic
        const observerOptions = { root: null, rootMargin: '0px', threshold: 0.15 };
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
