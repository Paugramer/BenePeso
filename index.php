<?php
require_once __DIR__ . '/auth_session.php';
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
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : "";
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : "";
$allowed_status_filters = ['Upcoming', 'Ongoing'];
if (!in_array($status_filter, $allowed_status_filters, true)) {
    $status_filter = "";
}

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
if ($status_filter !== "") {
    $where_clauses[] = "p.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// Fetch all matching batches, then group identical program families for public discovery.
// Exact batches remain available after the beneficiary signs in.
$sql = "SELECT p.*, 
        (p.slots - (SELECT COUNT(*) FROM beneficiaries b2 WHERE b2.program_id = p.program_id AND b2.approval_status = 'Approved')) AS remaining_slots 
        FROM programs p 
        WHERE $where_sql 
        ORDER BY p.created_at DESC, p.program_id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$batch_result = $stmt->get_result();
$program_families = [];

while ($program = $batch_result->fetch_assoc()) {
    $family_key = mb_strtolower(trim($program['program_name']));
    $remaining = max(0, (int)$program['remaining_slots']);

    if (!isset($program_families[$family_key])) {
        $program['batch_count'] = 1;
        $program['remaining_slots'] = $remaining;
        $program['categories'] = array_values(array_filter([trim((string)($program['tupad_category'] ?? ''))]));
        $program_families[$family_key] = $program;
        continue;
    }

    $family = &$program_families[$family_key];
    $family['batch_count']++;
    $family['remaining_slots'] += $remaining;
    $category = trim((string)($program['tupad_category'] ?? ''));
    if ($category !== '' && !in_array($category, $family['categories'], true)) {
        $family['categories'][] = $category;
    }
    if (!empty($program['start_date']) && strtotime($program['start_date']) < strtotime($family['start_date'])) {
        $family['start_date'] = $program['start_date'];
    }
    if (!empty($program['end_date']) && strtotime($program['end_date']) < strtotime($family['end_date'])) {
        $family['end_date'] = $program['end_date'];
    }
    if (($family['venue'] ?? '') !== ($program['venue'] ?? '')) {
        $family['venue'] = 'Multiple venues';
    }
    unset($family);
}
$stmt->close();

$all_programs = array_values($program_families);
$total_rows = count($all_programs);
$total_pages = max(1, (int)ceil($total_rows / $limit));
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;
$programs = array_slice($all_programs, $offset, $limit);

// Helper function to build pagination links
function build_page_link($p) {
    $query_params = $_GET;
    $query_params['page'] = $p;
    return '?' . http_build_query($query_params);
}

// Helper to format dates safely
function format_date($date_str) {
    if (empty($date_str) || $date_str === '0000-00-00') return "TBA";
    $timestamp = strtotime($date_str);
    return $timestamp ? date("F d, Y", $timestamp) : "TBA";
}

$directory_updated_at = null;
$updated_stmt = $conn->prepare("SELECT MAX(COALESCE(updated_at, created_at)) AS updated_at FROM programs WHERE approval_status = 'Approved'");
if ($updated_stmt) {
    $updated_stmt->execute();
    $directory_updated_at = $updated_stmt->get_result()->fetch_assoc()['updated_at'] ?? null;
    $updated_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/pesologo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BENEPESO | PESO Vinzons Programs and Beneficiary Services</title>
    <meta name="description" content="Browse approved PESO Vinzons programs, review eligibility and requirements, and access BENEPESO beneficiary services.">
    <meta name="theme-color" content="#176b49">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="home.css?v=17">
    
    <link rel="stylesheet" href="frontend_polish.css?v=20260921">
    <link rel="stylesheet" href="beneficiary_responsive.css?v=10">
    <link rel="stylesheet" href="index.css?v=31">
    <link rel="stylesheet" href="beneficiary_mobile.css?v=18">
    <link rel="stylesheet" href="system_readability.css?v=1">
<script src="frontend_polish.js?v=20260923" defer></script>
</head>
<body class="public-index-page" data-force-page-loader>
<a class="public-skip-link" href="#mainContent">Skip to main content</a>

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
        
        <button class="menu-button" id="menuButton" type="button" aria-label="Toggle menu" aria-controls="menuArea" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>

        <nav class="menu-area" id="menuArea" aria-label="Public navigation">
          <a class="menu-item active" href="index.php" aria-current="page">Home</a>
          <a class="menu-item" href="#available-programs">Programs</a>
          <a class="menu-item" href="index_about.php">About</a>
          <a class="btn-login" href="login.php"><svg class="public-login-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4.5 21a7.5 7.5 0 0 1 15 0"></path></svg><span>Login / Register</span></a>
        </nav>
      </div>
    </header>

    <main class="content-container" id="mainContent">
        
        <div id="programs-section" class="page-section">
            <section class="search-hero welcome-area public-home-hero" aria-labelledby="publicHeroTitle">
                <div class="public-hero-bubbles" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span></div>
                <div class="welcome-inner public-home-hero-inner">
                    <div class="public-home-hero-grid">
                        <div class="public-hero-copy">
                            <span class="welcome-badge stagger-1">
                                <span class="badge-dot"></span>
                                Official PESO Vinzons portal
                            </span>
                            <h1 class="welcome-title stagger-2" id="publicHeroTitle">Find the right PESO opportunity, <span class="welcome-highlight">all in one place.</span></h1>
                            <p class="centered-text stagger-3">Discover approved programs, understand the requirements, and continue through one secure and trackable service path.</p>

                            <div class="public-hero-actions stagger-3">
                                <a class="btn-main" href="#available-programs">Explore Open Programs</a>
                                <a class="btn-quiet" href="#how-it-works">How BENEPESO Works</a>
                            </div>

                            <div class="public-program-shortcuts stagger-4" aria-label="Browse the three PESO programs">
                                <span>Explore by program</span>
                                <div>
                                    <a href="?search=TUPAD#available-programs">TUPAD</a>
                                    <a href="?search=SPES#available-programs">SPES</a>
                                    <a href="?search=MSME#available-programs">MSME</a>
                                </div>
                            </div>
                        </div>

                        <aside class="public-hero-summary stagger-3" aria-label="Current PESO program summary">
                            <div class="public-summary-head">
                                <span>Current opportunities</span>
                                <span class="public-summary-live"><i aria-hidden="true"></i> Live directory</span>
                            </div>
                            <div class="public-summary-count"><strong><?= number_format($total_rows) ?></strong><span>open program listing<?= $total_rows === 1 ? '' : 's' ?><small>Browse official schedules</small></span></div>
                            <div class="public-summary-programs">
                                <a href="?search=TUPAD#available-programs"><i class="program-symbol program-symbol--tupad" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 18h14M7 15v-2a5 5 0 0 1 10 0v2M9 8.5V7a3 3 0 0 1 6 0v1.5M6 9h12"/></svg></i><span><b>TUPAD</b><small>Community employment</small></span><em aria-hidden="true">&rarr;</em></a>
                                <a href="?search=SPES#available-programs"><i class="program-symbol program-symbol--spes" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 9 9-5 9 5-9 5-9-5Z"/><path d="M7 12v4c3 2 7 2 10 0v-4M21 9v6"/></svg></i><span><b>SPES</b><small>Student employment</small></span><em aria-hidden="true">&rarr;</em></a>
                                <a href="?search=MSME#available-programs"><i class="program-symbol program-symbol--msme" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 10v9h14v-9M4 5h16l1 5a3 3 0 0 1-4 0 3 3 0 0 1-5 0 3 3 0 0 1-5 0 3 3 0 0 1-4 0l1-5Z"/><path d="M9 19v-5h6v5"/></svg></i><span><b>MSME</b><small>Livelihood support</small></span><em aria-hidden="true">&rarr;</em></a>
                            </div>
                            <div class="public-summary-foot">
                                <span><?= $directory_updated_at ? 'Updated ' . htmlspecialchars(date('M d, Y', strtotime($directory_updated_at))) : 'Verified PESO directory' ?></span>
                                <a href="#available-programs">View directory <span aria-hidden="true">&rarr;</span></a>
                            </div>
                        </aside>
                    </div>

                </div>
            </section>

            <section class="public-trust-strip" aria-label="BENEPESO service assurances">
                <div class="content-wrap">
                    <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-5"/></svg><b>Official PESO records</b><small>Published from approved listings</small></span>
                    <span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><b>Secure applications</b><small>Personal records stay protected</small></span>
                    <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 3-6.2"/><path d="M4 4v5h5"/><path d="M12 8v4l3 2"/></svg><b>Trackable updates</b><small>Follow validation and next steps</small></span>
                </div>
            </section>

            <section class="public-impact content-wrap reveal" aria-labelledby="publicImpactTitle">
                <div class="public-impact-copy">
                    <span class="section-kicker">PESO in the community</span>
                    <h2 id="publicImpactTitle">Real programs. Visible local impact.</h2>
                    <p>See how PESO Vinzons turns official opportunities into orientations, training, work experience, and support for residents.</p>
                    <a href="index_about.php#community-in-action">View community activities <span aria-hidden="true">&rarr;</span></a>
                </div>
                <div class="public-impact-gallery" aria-label="Recent PESO Vinzons community activities">
                    <figure class="public-impact-photo public-impact-photo--wide">
                        <img src="img/peso-community-medt-2026.png" alt="PESO Vinzons facilitator leading an employment and micro-enterprise development session" loading="lazy" decoding="async" width="1080" height="720">
                        <figcaption><strong>MSME development</strong><span>Skills and livelihood guidance</span></figcaption>
                    </figure>
                    <figure class="public-impact-photo">
                        <img src="img/752659025_2053404155260623_314073346839281248_n.jpg" alt="Student beneficiaries attending a SPES activity at Vinzons Municipal Hall" loading="lazy" decoding="async" width="1080" height="720">
                        <figcaption><strong>SPES</strong><span>Student employment support</span></figcaption>
                    </figure>
                    <figure class="public-impact-photo">
                        <img src="img/738512047_895211810293178_8484532229922374617_n.jpg" alt="TUPAD beneficiaries attending an official PESO and DOLE activity" loading="lazy" decoding="async" width="1080" height="720">
                        <figcaption><strong>TUPAD</strong><span>Community employment assistance</span></figcaption>
                    </figure>
                </div>
            </section>

            <section class="public-route content-wrap reveal" id="how-it-works" aria-labelledby="publicRouteTitle">
                <div class="public-route-heading">
                    <span>A guided service path</span>
                    <h2 id="publicRouteTitle">From discovery to an official decision</h2>
                    <div class="public-beneficiary-cue">
                        <svg viewBox="0 0 100 54" aria-hidden="true">
                            <g class="beneficiary-person beneficiary-person-one"><circle cx="18" cy="15" r="6"/><path d="M8 36c1-9 5-14 10-14s9 5 10 14"/></g>
                            <g class="beneficiary-person beneficiary-person-two"><circle cx="43" cy="15" r="6"/><path d="M33 36c1-9 5-14 10-14s9 5 10 14"/></g>
                            <g class="beneficiary-document"><rect x="61" y="7" width="28" height="38" rx="5"/><path d="M68 17h14M68 23h10M68 29h7"/><circle cx="83" cy="38" r="9"/><path class="beneficiary-check" d="m79 38 3 3 6-7"/></g>
                        </svg>
                        <span><strong>Built for beneficiaries</strong><small>One profile. Clear application steps.</small></span>
                    </div>
                </div>
                <ol class="public-route-flow">
                    <li><span class="route-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg></span><div><small>Discover</small><strong>Find your program</strong><p>Compare open opportunities.</p></div></li>
                    <li><span class="route-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l3 3v15H6zM14 3v4h4"/><path d="m9 14 2 2 4-5"/></svg></span><div><small>Prepare</small><strong>Review requirements</strong><p>Know what you need first.</p></div></li>
                    <li><span class="route-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><div><small>Submit</small><strong>Apply securely</strong><p>Use one verified profile.</p></div></li>
                    <li><span class="route-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 3-6.2M4 4v5h5"/><path d="m9 12 2 2 4-5"/></svg></span><div><small>Follow</small><strong>See the next action</strong><p>Track PESO validation.</p></div></li>
                </ol>
            </section>

            <section class="content-wrap results-area reveal" id="available-programs">
                <div class="section-heading">
                    <div class="section-heading-copy">
                        <div class="section-heading-meta">
                            <span class="section-kicker">Official PESO Directory</span>
                            <span class="listing-count" aria-label="<?= $total_rows ?> available program listings">
                                <strong><?= $total_rows ?></strong>
                                <span>Open programs</span>
                            </span>
                        </div>
                        <h2>Available Programs</h2>
                        <p>
                            <?php if ($search_query !== '' || $status_filter !== ''): ?>
                                <?= $total_rows ?> result<?= $total_rows === 1 ? '' : 's' ?><?= $search_query !== '' ? ' for “' . htmlspecialchars($search_query) . '”' : '' ?> · Page <?= $page ?> of <?= $total_pages ?>
                            <?php else: ?>
                                Showing page <?= $page ?> of <?= $total_pages ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <form action="index.php#available-programs" method="GET" class="v-search-box-centered public-directory-search">
                    <div class="input-wrapper">
                        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px; height:20px; color:var(--muted);"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <label class="sr-only" for="publicProgramSearch">Search programs by name</label>
                        <input id="publicProgramSearch" type="search" name="search" placeholder="Search TUPAD, SPES, or MSME" value="<?= htmlspecialchars($search_query) ?>" style="flex:1;" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="publicHeroSuggestions">
                        <div class="public-program-suggestions" id="publicHeroSuggestions" role="listbox" aria-label="Program suggestions" hidden>
                            <a href="?search=TUPAD#available-programs" role="option" data-search-value="TUPAD"><span class="program-symbol program-symbol--tupad" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 18h14M7 15v-2a5 5 0 0 1 10 0v2M9 8.5V7a3 3 0 0 1 6 0v1.5M6 9h12"/></svg></span><span><strong>TUPAD</strong><small>Community-based emergency employment</small></span><i aria-hidden="true">&rarr;</i></a>
                            <a href="?search=SPES#available-programs" role="option" data-search-value="SPES"><span class="program-symbol program-symbol--spes" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 9 9-5 9 5-9 5-9-5Z"/><path d="M7 12v4c3 2 7 2 10 0v-4M21 9v6"/></svg></span><span><strong>SPES</strong><small>Employment opportunities for students</small></span><i aria-hidden="true">&rarr;</i></a>
                            <a href="?search=MSME#available-programs" role="option" data-search-value="MSME"><span class="program-symbol program-symbol--msme" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 10v9h14v-9M4 5h16l1 5a3 3 0 0 1-4 0 3 3 0 0 1-5 0 3 3 0 0 1-5 0 3 3 0 0 1-4 0l1-5Z"/><path d="M9 19v-5h6v5"/></svg></span><span><strong>MSME Profiling</strong><small>Business and livelihood support</small></span><i aria-hidden="true">&rarr;</i></a>
                        </div>
                        <input id="publicStatusFilter" type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                        <div class="public-schedule-select" id="publicScheduleSelect" data-public-schedule>
                            <button class="public-schedule-toggle" id="publicScheduleToggle" type="button" aria-haspopup="listbox" aria-expanded="false" aria-controls="publicScheduleMenu">
                                <svg class="schedule-calendar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>
                                <span id="publicScheduleLabel"><?= $status_filter === 'Upcoming' ? 'Coming soon' : ($status_filter === 'Ongoing' ? 'Open now' : 'All schedules') ?></span>
                                <svg class="schedule-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 8 4 4 4-4"></path></svg>
                            </button>
                            <div class="public-schedule-menu" id="publicScheduleMenu" role="listbox" aria-label="Program schedule" hidden>
                                <button type="button" role="option" data-value="" aria-selected="<?= $status_filter === '' ? 'true' : 'false' ?>"><span class="schedule-option-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></span><span><strong>All schedules</strong><small>Show every open listing</small></span><span class="schedule-option-check" aria-hidden="true"></span></button>
                                <button type="button" role="option" data-value="Upcoming" aria-selected="<?= $status_filter === 'Upcoming' ? 'true' : 'false' ?>"><span class="schedule-option-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><span><strong>Coming soon</strong><small>Programs opening next</small></span><span class="schedule-option-check" aria-hidden="true"></span></button>
                                <button type="button" role="option" data-value="Ongoing" aria-selected="<?= $status_filter === 'Ongoing' ? 'true' : 'false' ?>"><span class="schedule-option-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-5"/></svg></span><span><strong>Open now</strong><small>Currently accepting applications</small></span><span class="schedule-option-check" aria-hidden="true"></span></button>
                            </div>
                        </div>
                        <button type="submit" class="btn-main">Search</button>
                    </div>
                </form>

                <div class="public-directory-notice" role="note">
                    <div><strong>Official program information</strong><span>Availability and remaining slots may change after PESO validation. Eligibility and final approval follow the requirements of the selected batch.</span></div>
                    <?php if ($directory_updated_at): ?><time datetime="<?= htmlspecialchars(date('Y-m-d', strtotime($directory_updated_at))) ?>">Directory updated <?= htmlspecialchars(date('M d, Y', strtotime($directory_updated_at))) ?></time><?php endif; ?>
                </div>

                <?php if ($search_query !== '' || $status_filter !== ''): ?>
                    <div class="public-active-filters" aria-label="Active program filters">
                        <span>Active filters</span>
                        <?php if ($search_query !== ''): ?>
                            <a href="<?= $status_filter !== '' ? '?status=' . rawurlencode($status_filter) . '#available-programs' : 'index.php#available-programs' ?>">Search: <?= htmlspecialchars($search_query) ?> <span aria-hidden="true">&times;</span></a>
                        <?php endif; ?>
                        <?php if ($status_filter !== ''): ?>
                            <a href="<?= $search_query !== '' ? '?search=' . rawurlencode($search_query) . '#available-programs' : 'index.php#available-programs' ?>"><?= $status_filter === 'Ongoing' ? 'Open now' : 'Coming soon' ?> <span aria-hidden="true">&times;</span></a>
                        <?php endif; ?>
                        <a class="clear-all" href="index.php#available-programs">Clear all</a>
                    </div>
                <?php endif; ?>

                <?php if (count($programs) > 0): ?>
                    <div class="program-grid program-count-<?= min(6, count($programs)) ?>" id="programGridContainer">
                        <?php 
                        $delay = 0.1;
                        foreach ($programs as $row):
                            $img = !empty($row['image_path']) ? htmlspecialchars($row['image_path']) : 'img/pesologo.png';
                            $remaining_slots = max(0, (int)$row['remaining_slots']);
                            $batch_count = max(1, (int)$row['batch_count']);
                            $batch_label = $batch_count > 1 ? $batch_count . ' open batches' : $row['program_code'];
                            $variant_names = array_values(array_filter(array_map(function ($category) {
                                return trim((string)preg_replace('/\bTUPAD\b/i', '', $category));
                            }, $row['categories'] ?? [])));
                            $status_detail = $batch_count > 1 && count($variant_names) > 0
                                ? implode(' + ', $variant_names)
                                : 'Accepting applications';
                            
                            $modalData = htmlspecialchars(json_encode([
                                'title' => $row['program_name'],
                                'code' => $batch_label,
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
                            <article class="program-card reveal" style="transition-delay: <?= $delay ?>s;" onclick="if (!event.target.closest('button, a')) openProgramModal(this.querySelector('.program-btn'))">
                                <div class="card-img-wrapper">
                                    <img src="<?= $img ?>" alt="<?= htmlspecialchars($row['program_name']) ?> program poster" class="card-img" decoding="async" onerror="this.onerror=null; this.src='img/pesologo.png';">
                                    <div class="program-image-badges" aria-hidden="true">
                                        <span class="program-code-badge"><?= htmlspecialchars($batch_label) ?></span>
                                        <span class="program-slot-badge <?= $remaining_slots <= 0 ? 'is-full' : '' ?>">
                                            <?= $remaining_slots > 0 ? number_format($remaining_slots) . ' slots' : 'Full' ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="card-content">
                                    <h3 class="card-title"><?= htmlspecialchars($row['program_name']) ?></h3>
                                    <span class="card-status"><?= htmlspecialchars($row['status']) ?> &middot; <?= htmlspecialchars($status_detail) ?></span>
                                    <p class="card-excerpt">
                                        <?= htmlspecialchars(mb_strimwidth($row['description'] ?? 'No description provided.', 0, 100, "...")) ?>
                                    </p>
                                    <div class="card-meta">
                                        <span>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                            <span title="<?= htmlspecialchars($row['venue'] ?: 'Venue to be announced') ?>"><?= htmlspecialchars(mb_strimwidth($row['venue'] ?: 'Venue TBA', 0, 28, '…')) ?></span>
                                        </span>
                                        <span>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                            Until <?= format_date($row['end_date']) ?>
                                        </span>
                                    </div>
                                    <div class="card-footer-info">
                                        <button type="button" class="program-btn" data-program="<?= $modalData ?>" aria-label="View <?= htmlspecialchars($row['program_name']) ?><?= $batch_count > 1 ? ' open batches' : ' details' ?>" onclick="openProgramModal(this)">View Program Details</button>
                                    </div>
                                </div>
                            </article>
                        <?php 
                        $delay += 0.1;
                        endforeach;
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
                            <p>We couldn't find an open TUPAD, SPES, or MSME listing matching these filters.</p>
                            <?php if($search_query !== '' || $status_filter !== ''): ?>
                                <a href="index.php#available-programs" class="btn-outline" style="margin-top: 15px;">View All Programs</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
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
          <a href="#available-programs">Programs</a>
          <a href="index_about.php">About</a>
          <a href="login.php">Login / Register</a>
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
        <div class="footer-copy">&copy; <?php echo date("Y"); ?> BENEPESO &bull; PESO Vinzons</div>
        <div class="footer-mini">Republic of the Philippines &bull; Province of Camarines Norte</div>
      </div>
    </footer>
</div>

<div class="modal-overlay" id="programModalOverlay" aria-hidden="true">
    <div class="modal-landscape" role="dialog" aria-modal="true" aria-labelledby="m_title" tabindex="-1">
        <div class="modal-split">
            <div class="modal-left">
                <div class="modal-left-img-wrapper">
                    <img id="m_img" src="" alt="" onerror="this.onerror=null; this.src='img/pesologo.png';">
                </div>
                <div class="modal-left-content">
                    <h2 id="m_title">Program Title</h2>
                    <p class="modal-code" id="m_code">Batch Code: ---</p>
                </div>
            </div>
            
            <div class="modal-right">
                <button type="button" class="modal-close-btn" aria-label="Close program details" onclick="closeModals()">&times;</button>
                <div class="m-scroll-area">
                    <div class="modal-service-heading">
                        <span><i aria-hidden="true"></i> Official PESO program</span>
                        <strong>Review before you apply</strong>
                    </div>
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
                        <ul class="m-detail-list" id="m_eligibility"></ul>
                    </div>
                    <div class="m-section">
                        <h4>Requirements</h4>
                        <ul class="m-detail-list" id="m_reqs"></ul>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <div><strong>Ready to continue?</strong><span>Sign in to use your verified BENEPESO profile.</span></div>
                    <button type="button" class="btn-main" onclick="clickApply()">Sign in to Apply <span aria-hidden="true">&rarr;</span></button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="loginWarningOverlay" aria-hidden="true" style="z-index: 2000;">
    <div class="modal-box small-modal" role="dialog" aria-modal="true" aria-labelledby="loginWarningTitle" tabindex="-1">
        <button type="button" class="modal-close-btn" aria-label="Close sign-in notice" onclick="closeWarning()">&times;</button>
        <div class="modal-icon-warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 50px; height: 50px;">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>
        <h3 id="loginWarningTitle">Authentication Required</h3>
        <p>You need to log in to your account or register a new account before you can apply for programs.</p>
        <div class="modal-actions-centered">
            <button type="button" class="btn-outline" onclick="closeWarning()">Cancel</button>
            <button type="button" class="btn-main" onclick="window.location.href='login.php'">Go to Login</button>
        </div>
    </div>
</div>

<script>
    // Modal Logic
    const programModal = document.getElementById('programModalOverlay');
    const warningModal = document.getElementById('loginWarningOverlay');
    let lastProgramTrigger = null;
    let lastWarningTrigger = null;

    function setModalVisibility(modal, isVisible) {
        modal.classList.toggle('show', isVisible);
        modal.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
        document.body.classList.toggle('modal-open', programModal.classList.contains('show') || warningModal.classList.contains('show'));
    }

    function focusModal(modal) {
        window.setTimeout(() => {
            const target = modal.querySelector('.modal-close-btn, button, [href], [tabindex]:not([tabindex="-1"])');
            (target || modal.querySelector('[role="dialog"]'))?.focus();
        }, 20);
    }

    function renderProgramList(targetId, value, fallback) {
        const list = document.getElementById(targetId);
        const normalized = String(value || '').replace(/\r/g, '').trim();
        const items = normalized
            .split(/\n+|[•●▪]\s*|;\s*|(?:^|\s)[\-–—]\s+/g)
            .map(item => item.trim().replace(/^[\s\-–—•●▪,.:]+|[,;]+$/g, '').trim())
            .filter(item => item && !/^for\s+students?\s*:?$/i.test(item));

        list.replaceChildren();
        (items.length ? items : [fallback]).forEach(item => {
            const listItem = document.createElement('li');
            listItem.textContent = item;
            list.appendChild(listItem);
        });
    }

    function openProgramModal(trigger) {
        const data = JSON.parse(trigger.getAttribute('data-program'));
        lastProgramTrigger = trigger;
        
        document.getElementById('m_img').src = data.img;
        document.getElementById('m_img').alt = data.title + ' program poster';
        document.getElementById('m_title').textContent = data.title;
        document.getElementById('m_code').textContent = 'Batch Code: ' + (data.code || 'N/A');
        
        document.getElementById('m_desc').textContent = data.desc || 'No description provided.';
        document.getElementById('m_slots').textContent = data.slots || '0';
        document.getElementById('m_venue').textContent = data.venue || 'TBA';
        document.getElementById('m_start').textContent = data.start;
        document.getElementById('m_end').textContent = data.end;
        renderProgramList('m_eligibility', data.eligibility, 'No eligibility criteria specified.');
        renderProgramList('m_reqs', data.reqs, 'No requirements specified.');

        setModalVisibility(programModal, true);
        focusModal(programModal);
    }

    function closeModals() {
        setModalVisibility(programModal, false);
        lastProgramTrigger?.focus();
    }

    function clickApply() {
        lastWarningTrigger = document.activeElement;
        setModalVisibility(warningModal, true);
        focusModal(warningModal);
    }

    function closeWarning() {
        setModalVisibility(warningModal, false);
        lastWarningTrigger?.focus();
    }

    window.addEventListener('click', function(event) {
        if (event.target == programModal) {
            closeModals();
        }
        if (event.target == warningModal) {
            closeWarning();
        }
    });

    document.addEventListener('keydown', function(event) {
        const activeModal = warningModal.classList.contains('show') ? warningModal : (programModal.classList.contains('show') ? programModal : null);
        if (!activeModal) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            activeModal === warningModal ? closeWarning() : closeModals();
            return;
        }

        if (event.key === 'Tab') {
            const focusable = Array.from(activeModal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter(element => !element.disabled);
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

    document.addEventListener('DOMContentLoaded', function() {
        const scheduleSelects = Array.from(document.querySelectorAll('[data-public-schedule]'));
        const closeOtherSchedules = activeSelect => scheduleSelects.forEach(select => {
            if (select === activeSelect) return;
            select.classList.remove('is-open');
            select.querySelector('.public-schedule-menu').hidden = true;
            select.querySelector('.public-schedule-toggle').setAttribute('aria-expanded', 'false');
        });

        scheduleSelects.forEach(function(scheduleSelect, scheduleIndex) {
            const scheduleToggle = scheduleSelect.querySelector('.public-schedule-toggle');
            const scheduleMenu = scheduleSelect.querySelector('.public-schedule-menu');
            const scheduleInput = scheduleSelect.closest('form')?.querySelector('input[name="status"]');
            const scheduleLabel = scheduleToggle?.querySelector('#publicScheduleLabel, .public-schedule-label');
            const scheduleOptions = scheduleMenu ? Array.from(scheduleMenu.querySelectorAll('[role="option"]')) : [];
            if (!scheduleToggle || !scheduleMenu || !scheduleInput || !scheduleLabel) return;

            const menuId = scheduleMenu.id || `publicScheduleMenu${scheduleIndex + 1}`;
            scheduleMenu.id = menuId;
            scheduleToggle.setAttribute('aria-controls', menuId);

            function closeScheduleMenu(returnFocus = false) {
                scheduleMenu.hidden = true;
                scheduleSelect.classList.remove('is-open');
                scheduleToggle.setAttribute('aria-expanded', 'false');
                if (returnFocus) scheduleToggle.focus();
            }

            function openScheduleMenu() {
                closeOtherSchedules(scheduleSelect);
                scheduleMenu.hidden = false;
                scheduleSelect.classList.add('is-open');
                scheduleToggle.setAttribute('aria-expanded', 'true');
                const selected = scheduleOptions.find(option => option.getAttribute('aria-selected') === 'true') || scheduleOptions[0];
                selected?.focus();
            }

            scheduleToggle.addEventListener('click', function() {
                scheduleMenu.hidden ? openScheduleMenu() : closeScheduleMenu();
            });

            scheduleOptions.forEach(option => option.addEventListener('click', function() {
                scheduleOptions.forEach(item => item.setAttribute('aria-selected', 'false'));
                option.setAttribute('aria-selected', 'true');
                scheduleInput.value = option.dataset.value || '';
                scheduleLabel.textContent = option.querySelector('strong').textContent;
                closeScheduleMenu(true);
            }));

            scheduleMenu.addEventListener('keydown', function(event) {
                const currentIndex = scheduleOptions.indexOf(document.activeElement);
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    const direction = event.key === 'ArrowDown' ? 1 : -1;
                    scheduleOptions[(currentIndex + direction + scheduleOptions.length) % scheduleOptions.length].focus();
                } else if (event.key === 'Home' || event.key === 'End') {
                    event.preventDefault();
                    scheduleOptions[event.key === 'Home' ? 0 : scheduleOptions.length - 1].focus();
                } else if (event.key === 'Escape' || event.key === 'Tab') {
                    closeScheduleMenu(event.key === 'Escape');
                }
            });

            document.addEventListener('click', function(event) {
                if (!scheduleSelect.contains(event.target)) closeScheduleMenu();
            });
        });

        document.querySelectorAll('input[role="combobox"][aria-controls]').forEach(function(input) {
            const suggestions = document.getElementById(input.getAttribute('aria-controls'));
            if (!suggestions) return;
            const options = Array.from(suggestions.querySelectorAll('[role="option"]'));

            const closeSuggestions = () => {
                suggestions.hidden = true;
                input.setAttribute('aria-expanded', 'false');
            };

            const updateSuggestions = () => {
                const query = input.value.trim().toLowerCase();
                let visible = 0;
                options.forEach(option => {
                    const matches = !query || option.dataset.searchValue.toLowerCase().includes(query) || option.textContent.toLowerCase().includes(query);
                    option.hidden = !matches;
                    if (matches) visible++;
                });
                suggestions.hidden = visible === 0;
                input.setAttribute('aria-expanded', visible ? 'true' : 'false');
            };

            input.addEventListener('focus', updateSuggestions);
            input.addEventListener('input', updateSuggestions);
            input.addEventListener('keydown', function(event) {
                const visibleOptions = options.filter(option => !option.hidden);
                if (event.key === 'ArrowDown' && visibleOptions.length) {
                    event.preventDefault();
                    visibleOptions[0].focus();
                } else if (event.key === 'Escape') {
                    closeSuggestions();
                }
            });
            suggestions.addEventListener('keydown', function(event) {
                const visibleOptions = options.filter(option => !option.hidden);
                const currentIndex = visibleOptions.indexOf(document.activeElement);
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    const direction = event.key === 'ArrowDown' ? 1 : -1;
                    visibleOptions[(currentIndex + direction + visibleOptions.length) % visibleOptions.length]?.focus();
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    closeSuggestions();
                    input.focus();
                }
            });
            document.addEventListener('click', event => {
                if (!suggestions.contains(event.target) && event.target !== input) closeSuggestions();
            });
        });

        // Mobile Menu Toggle
        const menuBtn = document.getElementById('menuButton');
        const menuArea = document.getElementById('menuArea');
        if(menuBtn && menuArea) {
            menuBtn.addEventListener('click', function() {
                const isOpen = menuArea.classList.toggle('open');
                menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
            menuArea.querySelectorAll('a').forEach(link => link.addEventListener('click', function() {
                menuArea.classList.remove('open');
                menuBtn.setAttribute('aria-expanded', 'false');
            }));

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && menuArea.classList.contains('open')) {
                    menuArea.classList.remove('open');
                    menuBtn.setAttribute('aria-expanded', 'false');
                    menuBtn.focus();
                }
            });
        }

        const topbar = document.querySelector('.topbar');
        const updateTopbar = () => topbar?.classList.toggle('is-scrolled', window.scrollY > 12);
        updateTopbar();
        window.addEventListener('scroll', updateTopbar, { passive: true });

        // Scroll Reveal Animation Logic
        const observerOptions = { root: null, rootMargin: '0px', threshold: 0.15 };
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('active');
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
        } else {
            document.querySelectorAll('.reveal').forEach(el => el.classList.add('active'));
        }
    });
</script>
</body>
</html>
