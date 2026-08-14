<?php
session_start();
require "db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION["user_id"];
$user_display_name = "User";
$first_char = "U";
$user_barangay = "";

// 1. Fetch Logged-in User's Data 
$stmt = $conn->prepare("SELECT first_name, middle_name, last_name, ext_name, barangay FROM users WHERE user_id=? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows === 1) {
    $user_data = $res->fetch_assoc();
    $user_barangay = $user_data["barangay"];
    
    $fn = trim($user_data["first_name"] ?? "");
    $mn = trim($user_data["middle_name"] ?? "");
    $ln = trim($user_data["last_name"] ?? "");
    $ex = trim($user_data["ext_name"] ?? "");

    $full_name = $fn . ($mn ? " " . substr($mn, 0, 1) . "." : "") . " " . $ln . ($ex ? " " . $ex : "");
    $user_display_name = !empty(trim($full_name)) ? $full_name : "User";
    $first_char = !empty($fn) ? strtoupper(substr($fn, 0, 1)) : "U";
}

// 2. Fetch all Programs for the Dropdown Filter
$programs_list = $conn->query("SELECT program_id, program_name FROM programs WHERE approval_status = 'Approved'");

// 3. Setup Pagination Variables
$results_per_page = 5;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $results_per_page;
$total_pages = 0;
$total_results = 0;

$search_result = null;
$search_query = "";
$filter_program = isset($_GET['program_filter']) ? $_GET['program_filter'] : "";

if (isset($_GET['search']) || !empty($filter_program)) {
    $search_query = trim($_GET['search'] ?? "");
    $search_param = "%$search_query%";
    
    // Base WHERE clause - restricts search strictly to the user's barangay for privacy
    $where_clause = "WHERE b.barangay = ?";
    
    if (!empty($search_query)) {
        // Users can search BY contact number if they know it, but it will NOT be displayed back to them.
        $where_clause .= " AND (b.full_name LIKE ? OR b.email = ? OR b.contact_no = ?)";
    }
    if (!empty($filter_program)) {
        $where_clause .= " AND b.program_id = ?";
    }

    // --- A. Get Total Count for Pagination ---
    $count_sql = "SELECT COUNT(*) as total FROM beneficiaries b JOIN programs p ON b.program_id = p.program_id " . $where_clause;
    $count_stmt = $conn->prepare($count_sql);
    
    if (!empty($search_query) && !empty($filter_program)) {
        $count_stmt->bind_param("ssssi", $user_barangay, $search_param, $search_query, $search_query, $filter_program);
    } elseif (!empty($search_query)) {
        $count_stmt->bind_param("ssss", $user_barangay, $search_param, $search_query, $search_query);
    } elseif (!empty($filter_program)) {
        $count_stmt->bind_param("si", $user_barangay, $filter_program);
    } else {
        $count_stmt->bind_param("s", $user_barangay);
    }
    $count_stmt->execute();
    $total_results = $count_stmt->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_results / $results_per_page);

    // --- B. Fetch the limited data for current page ---
    $sql = "SELECT b.*, p.program_name FROM beneficiaries b JOIN programs p ON b.program_id = p.program_id " . $where_clause . " ORDER BY b.created_at DESC LIMIT ?, ?";
    $stmt = $conn->prepare($sql);
    
    if (!empty($search_query) && !empty($filter_program)) {
        $stmt->bind_param("ssssiii", $user_barangay, $search_param, $search_query, $search_query, $filter_program, $offset, $results_per_page);
    } elseif (!empty($search_query)) {
        $stmt->bind_param("ssssii", $user_barangay, $search_param, $search_query, $search_query, $offset, $results_per_page);
    } elseif (!empty($filter_program)) {
        $stmt->bind_param("siii", $user_barangay, $filter_program, $offset, $results_per_page);
    } else {
        $stmt->bind_param("sii", $user_barangay, $offset, $results_per_page);
    }
    
    $stmt->execute();
    $search_result = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/pesologo.png">
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Verification | BENEPESO</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="home.css?v=10" />
    <link rel="stylesheet" href="verification.css?v=4" />
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

<main class="page-wrap">
    
    <section class="welcome-area verify-hero stagger-1">
        <div class="content-wrap centered-hero">
            <div class="verify-hero-content">
                <div class="verify-badge">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4.5 6v5.5c0 4.6 3.1 7.9 7.5 9.5 4.4-1.6 7.5-4.9 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg>
                    <span>Private status lookup</span>
                </div>
                <h1 class="welcome-title">
                    Record <span class="welcome-highlight">Verification</span>
                </h1>
                <p class="welcome-text centered-text">
                    Securely verify the application status of residents within your barangay. Filter by program or search directly by name.
                </p>

                <form id="searchForm" action="verification.php" method="GET" class="v-search-box">
                    <div class="v-input-wrapper">
                        <select name="program_filter" id="programFilter" class="v-select">
                            <option value="">All Programs</option>
                            <?php if ($programs_list): ?>
                                <?php while($p_row = $programs_list->fetch_assoc()): ?>
                                    <option value="<?= $p_row['program_id'] ?>" <?= ($filter_program == $p_row['program_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p_row['program_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                        <div class="v-divider"></div>
                        <input type="text" name="search" id="searchInput" class="v-input" placeholder="Search resident by name..." value="<?= htmlspecialchars($search_query) ?>" autocomplete="off">
                        <button type="submit" class="v-btn" aria-label="Search beneficiary records">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        </button>
                    </div>
                </form>
                
                <!-- PROFESSIONAL PRIVACY DISCLAIMER -->
                <div class="privacy-disclaimer">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <span>Data displayed is limited to application status for verification purposes in accordance with local data privacy guidelines.</span>
                </div>
            </div>
        </div>
    </section>

    <!-- RESULTS AREA WITH ID FOR AJAX TARGETING -->
    <section id="resultsArea" class="content-wrap results-area stagger-2">
        <?php if ($search_result && $search_result->num_rows > 0): ?>
            <div class="results-header">
                <h3>Verification Results</h3>
                <p>Showing <?= ($offset + 1) ?> - <?= min($offset + $results_per_page, $total_results) ?> of <?= $total_results ?> match(es) found in Barangay <?= htmlspecialchars($user_barangay) ?>.</p>
            </div>
            
            <div class="results-list">
                <?php while($row = $search_result->fetch_assoc()): ?>
                    <div class="v-card-horizontal">
                        <div class="v-card-left">
                            <div class="v-tags">
                                <span class="v-tag"><?= htmlspecialchars($row['program_name']) ?></span>
                                <span class="v-pill <?= strtolower(str_replace(' ', '-', $row['approval_status'] ?? 'pending')) ?>">
                                    <?= htmlspecialchars($row['approval_status'] ?? 'Pending') ?>
                                </span>
                            </div>
                            <?php
    // DATA PRIVACY ACT COMPLIANCE: Name Masking
    // We display the First Name, but mask the Last Name (e.g., Clara D******)
    $fName = trim($row['first_name'] ?? $row['full_name']); 
    $lName = trim($row['last_name'] ?? '');
    
    if (!empty($lName)) {
        // Get the first letter of the last name, then replace the rest with asterisks
        $maskedLastName = substr($lName, 0, 1) . str_repeat('*', strlen($lName) - 1);
        $secureName = $fName . ' ' . $maskedLastName;
    } else {
        // Fallback if they only have one name string: mask half of the string
        $len = strlen($fName);
        $secureName = substr($fName, 0, ceil($len/2)) . str_repeat('*', floor($len/2));
    }
?>
<h3 class="v-name" title="Name partially hidden for Data Privacy compliance">
    <?= htmlspecialchars($secureName) ?>
    <svg class="v-name-lock" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
    </svg>
</h3>
                            <div class="v-location">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                <span>Brgy. <?= htmlspecialchars($row['barangay'] ?? $user_barangay) ?>, Vinzons</span>
                            </div>
                        </div>
                        <div class="v-card-right">
                            <div class="v-info">
                                <span class="v-label">Availment</span>
                                <span class="v-val <?= strtolower(str_replace(' ', '-', $row['availment_status'] ?? '')) ?>"><?= htmlspecialchars($row['availment_status'] ?? 'Processing') ?></span>
                            </div>
                            <div class="v-info">
                                <span class="v-label">Applied On</span>
                                <span class="v-val date-val"><?= date("M d, Y", strtotime($row['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <?php
                $qs = "";
                if(isset($_GET['program_filter'])) $qs .= "&program_filter=".urlencode($_GET['program_filter']);
                if(isset($_GET['search'])) $qs .= "&search=".urlencode($_GET['search']);
                ?>
                <div class="pagination-wrapper">
                    <?php if($current_page > 1): ?>
                        <a href="?page=<?= $current_page - 1 ?><?= $qs ?>" class="page-btn nav-btn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        </a>
                    <?php endif; ?>

                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?><?= $qs ?>" class="page-btn <?= ($i == $current_page) ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if($current_page < $total_pages): ?>
                        <a href="?page=<?= $current_page + 1 ?><?= $qs ?>" class="page-btn nav-btn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
        <?php elseif (isset($_GET['search']) || !empty($filter_program)): ?>
            <div class="v-no-results">
                <div class="v-no-results-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </div>
                <h3>No Records Found</h3>
                <p>We couldn't find any beneficiaries matching your search in Brgy. <?= htmlspecialchars($user_barangay) ?>.</p>
            </div>
        <?php else: ?>
            <div class="v-empty-state">
                <div class="v-empty-icon pulse-animation">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                </div>
                <h3>Ready to Verify</h3>
                <p>Use the search bar above to look up beneficiaries in your barangay.</p>
            </div>
        <?php endif; ?>
    </section>
</main>

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
    // Dropdown functionality
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

    // =========================================
    // PROFESSIONAL AJAX LIVE SEARCH
    // =========================================
    const searchInput = document.getElementById('searchInput');
    const programFilter = document.getElementById('programFilter');
    const resultsArea = document.getElementById('resultsArea');
    let typingTimer;

    function fetchResults() {
        const query = searchInput.value;
        const filter = programFilter.value;
        
        // Don't search if both are empty (returns to empty state naturally via PHP)
        
        resultsArea.style.opacity = '0.5'; // Visual loading cue
        resultsArea.style.pointerEvents = 'none';

        const url = `verification.php?search=${encodeURIComponent(query)}&program_filter=${encodeURIComponent(filter)}`;

        fetch(url)
            .then(response => response.text())
            .then(html => {
                // Parse the new HTML and extract just the results area
                const parser = new DOMParser();
                const doc = parser.parseDocumentFromString(html, 'text/html');
                const newResults = doc.getElementById('resultsArea').innerHTML;
                
                // Inject the new results seamlessly
                resultsArea.innerHTML = newResults;
                resultsArea.style.opacity = '1';
                resultsArea.style.pointerEvents = 'auto';
            })
            .catch(error => {
                console.error('Error fetching search results:', error);
                resultsArea.style.opacity = '1';
                resultsArea.style.pointerEvents = 'auto';
            });
    }

    // Trigger on typing (with debounce so it doesn't spam the server)
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(fetchResults, 400); // Wait 400ms after user stops typing
        });
    }

    // Trigger immediately on dropdown change
    if (programFilter) {
        programFilter.addEventListener('change', fetchResults);
    }
});
</script>
</body>
</html>
