<?php
require_once __DIR__ . '/auth.php';
require "db.php";
require_once __DIR__ . '/auth_rate_limit.php';

check_user_role('user');

$user_id = (int)$_SESSION["user_id"];
$user_display_name = "User";
$first_char = "U";
$user_barangay = "";
$user_email = "";
$user_profile_src = '';

// 1. Fetch Logged-in User's Data 
$stmt = $conn->prepare("SELECT first_name, middle_name, last_name, ext_name, barangay, email, profile_pic FROM users WHERE user_id=? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows === 1) {
    $user_data = $res->fetch_assoc();
    $user_barangay = $user_data["barangay"];
    $user_email = trim((string)($user_data['email'] ?? ''));
    
    $fn = trim($user_data["first_name"] ?? "");
    $mn = trim($user_data["middle_name"] ?? "");
    $ln = trim($user_data["last_name"] ?? "");
    $ex = trim($user_data["ext_name"] ?? "");

    $full_name = $fn . ($mn ? " " . substr($mn, 0, 1) . "." : "") . " " . $ln . ($ex ? " " . $ex : "");
    $user_display_name = !empty(trim($full_name)) ? $full_name : "User";
    $first_char = !empty($fn) ? strtoupper(substr($fn, 0, 1)) : "U";
    $profile_filename = basename((string)($user_data['profile_pic'] ?? ''));
    if ($profile_filename !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $profile_filename)) {
        $user_profile_src = 'uploads/' . rawurlencode($profile_filename);
    }
}

function verification_user_can_search_program(mysqli $conn, int $programId, int $userId, string $email): bool
{
    if ($programId < 1) return false;

    $accessStmt = $conn->prepare(
        "SELECT 1
           FROM beneficiaries own
           JOIN programs p ON p.program_id = own.program_id
          WHERE own.program_id = ?
            AND (own.user_id = ? OR (own.user_id IS NULL AND own.email = ?))
            AND p.approval_status = 'Approved'
            AND (UPPER(p.program_name) LIKE '%TUPAD%' OR UPPER(p.program_name) LIKE '%SPES%' OR UPPER(p.program_name) LIKE '%MSME%')
          LIMIT 1"
    );
    if (!$accessStmt) return false;
    $accessStmt->bind_param('iis', $programId, $userId, $email);
    $accessStmt->execute();
    $allowed = $accessStmt->get_result()->num_rows === 1;
    $accessStmt->close();
    return $allowed;
}

// Privacy-scoped suggestions: only the user's barangay and a batch that the
// signed-in account has applied to can be searched.
if (isset($_GET['suggest']) && $_GET['suggest'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    $prefix = trim((string)($_GET['q'] ?? ''));
    $suggestProgramId = ctype_digit((string)($_GET['program_filter'] ?? '')) ? (int)$_GET['program_filter'] : 0;

    if (mb_strlen($prefix, 'UTF-8') < 1 || !verification_user_can_search_program($conn, $suggestProgramId, $user_id, $user_email)) {
        echo json_encode(['items' => []]);
        exit;
    }

    $suggestIp = auth_request_ip();
    $suggestLimited = max(
        auth_rate_limit_hit('beneficiary-suggest-account', (string)$user_id, 60, 300, 300),
        auth_rate_limit_hit('beneficiary-suggest-ip', $suggestIp, 160, 300, 300)
    );
    if ($suggestLimited > 0) {
        http_response_code(429);
        echo json_encode(['items' => [], 'message' => 'Please wait before searching again.']);
        exit;
    }

    $prefixLike = $prefix . '%';
    $suggestStmt = $conn->prepare(
        "SELECT b.first_name, b.last_name, b.full_name, u.profile_pic
           FROM beneficiaries b
           JOIN programs p ON p.program_id = b.program_id
           LEFT JOIN users u ON u.user_id = b.user_id
          WHERE b.barangay = ?
            AND b.program_id = ?
            AND p.approval_status = 'Approved'
            AND LOWER(TRIM(b.full_name)) LIKE LOWER(?)
          ORDER BY b.full_name ASC, b.beneficiary_id DESC
          LIMIT 6"
    );
    $suggestStmt->bind_param('sis', $user_barangay, $suggestProgramId, $prefixLike);
    $suggestStmt->execute();
    $suggestResult = $suggestStmt->get_result();
    $suggestions = [];
    while ($suggestRow = $suggestResult->fetch_assoc()) {
        $fullName = trim((string)($suggestRow['full_name'] ?? ''));
        if ($fullName === '') continue;
        $firstName = trim((string)($suggestRow['first_name'] ?? ''));
        $lastName = trim((string)($suggestRow['last_name'] ?? ''));
        if ($firstName !== '' && $lastName !== '') {
            $lastLength = mb_strlen($lastName, 'UTF-8');
            $displayName = $firstName . ' ' . mb_substr($lastName, 0, 1, 'UTF-8') . str_repeat('*', max(0, $lastLength - 1));
        } else {
            $nameLength = mb_strlen($fullName, 'UTF-8');
            $displayName = mb_substr($fullName, 0, (int)ceil($nameLength / 2), 'UTF-8') . str_repeat('*', (int)floor($nameLength / 2));
        }
        $profileFilename = basename((string)($suggestRow['profile_pic'] ?? ''));
        $profileImage = $profileFilename !== '' && $profileFilename !== 'default_user.png' && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $profileFilename)
            ? 'uploads/' . rawurlencode($profileFilename)
            : '';
        $suggestions[] = [
            'value' => $fullName,
            'label' => $displayName,
            'initial' => mb_strtoupper(mb_substr($firstName !== '' ? $firstName : $fullName, 0, 1, 'UTF-8'), 'UTF-8'),
            'photo' => $profileImage,
        ];
    }
    $suggestStmt->close();
    echo json_encode(['items' => $suggestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Show only approved batches that the signed-in beneficiary has applied to.
$programs_stmt = $conn->prepare(
    "SELECT DISTINCT p.program_id, p.program_name, p.program_code, p.start_date, p.end_date
       FROM programs p
       JOIN beneficiaries own ON own.program_id = p.program_id
      WHERE (own.user_id = ? OR (own.user_id IS NULL AND own.email = ?))
        AND p.approval_status = 'Approved'
        AND (UPPER(p.program_name) LIKE '%TUPAD%' OR UPPER(p.program_name) LIKE '%SPES%' OR UPPER(p.program_name) LIKE '%MSME%')
      ORDER BY p.program_name ASC, p.start_date DESC, p.program_id DESC"
);
$programs_stmt->bind_param('is', $user_id, $user_email);
$programs_stmt->execute();
$programs_list = $programs_stmt->get_result();

// 3. Setup Pagination Variables
$results_per_page = 5;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $results_per_page;
$total_pages = 0;
$total_results = 0;

$search_result = null;
$search_query = '';
$filter_program = isset($_GET['program_filter']) ? $_GET['program_filter'] : "";
$search_ready = false;
$search_too_short = false;
$batch_required = false;
$program_access_denied = false;
$rate_limited = false;

if (isset($_GET['search'])) {
    $search_query = trim((string)($_GET['search'] ?? ''));
    $valid_program_filter = ctype_digit((string)$filter_program) && (int)$filter_program > 0;
    $batch_required = $search_query !== '' && !$valid_program_filter;
    $has_program_access = $valid_program_filter
        && verification_user_can_search_program($conn, (int)$filter_program, $user_id, $user_email);
    $program_access_denied = $valid_program_filter && !$has_program_access;
    $search_ready = mb_strlen($search_query, 'UTF-8') >= 1 && $has_program_access;
    $search_too_short = $search_query !== '' && !$search_ready && !$batch_required && !$program_access_denied;
}

if ($search_ready) {
    $lookup_window_seconds = 300;
    $lookup_limit = 30;
    $lookup_now = time();
    $lookup_attempts = array_values(array_filter(
        $_SESSION['beneficiary_lookup_attempts'] ?? [],
        static fn($timestamp) => is_int($timestamp) && $timestamp > ($lookup_now - $lookup_window_seconds)
    ));

    if (count($lookup_attempts) >= $lookup_limit) {
        $rate_limited = true;
        $search_ready = false;
    } else {
        $lookup_attempts[] = $lookup_now;
    }
    $_SESSION['beneficiary_lookup_attempts'] = $lookup_attempts;

    if ($search_ready) {
        $lookupIp = auth_request_ip();
        $accountKey = (string)$user_id;
        $persistentRetryAfter = max(
            auth_rate_limit_retry_after('beneficiary-lookup-account', $accountKey, 30, 300, 300),
            auth_rate_limit_retry_after('beneficiary-lookup-ip', $lookupIp, 100, 300, 300)
        );
        if ($persistentRetryAfter > 0) {
            $rate_limited = true;
            $search_ready = false;
        } else {
            $persistentRetryAfter = max(
                auth_rate_limit_hit('beneficiary-lookup-account', $accountKey, 30, 300, 300),
                auth_rate_limit_hit('beneficiary-lookup-ip', $lookupIp, 100, 300, 300)
            );
            if ($persistentRetryAfter > 0) {
                $rate_limited = true;
                $search_ready = false;
            }
        }
    }
}

// Return an exact-name match only within the user's barangay and a batch that
// their own account has applied to.
if ($search_ready) {
    $search_param = $search_query;
    $where_clause = "WHERE b.barangay = ?
        AND p.approval_status = 'Approved'
        AND (UPPER(p.program_name) LIKE '%TUPAD%' OR UPPER(p.program_name) LIKE '%SPES%' OR UPPER(p.program_name) LIKE '%MSME%')
        AND LOWER(TRIM(b.full_name)) = LOWER(TRIM(?))
        AND b.program_id = ?";

    // --- A. Get Total Count for Pagination ---
    $count_sql = "SELECT COUNT(*) as total FROM beneficiaries b JOIN programs p ON b.program_id = p.program_id " . $where_clause;
    $count_stmt = $conn->prepare($count_sql);
    
    $count_stmt->bind_param("ssi", $user_barangay, $search_param, $filter_program);
    $count_stmt->execute();
    $total_results = $count_stmt->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_results / $results_per_page);

    // --- B. Fetch the limited data for current page ---
    $sql = "SELECT b.program_id, b.first_name, b.last_name, b.full_name, b.barangay,
                   b.approval_status, b.availment_status, b.created_at, b.updated_at,
                   p.program_name, p.program_code, u.profile_pic AS user_profile_pic
              FROM beneficiaries b
              JOIN programs p ON b.program_id = p.program_id
              LEFT JOIN users u ON u.user_id = b.user_id " . $where_clause . " ORDER BY b.created_at DESC LIMIT ?, ?";
    $stmt = $conn->prepare($sql);
    
    $stmt->bind_param("ssiii", $user_barangay, $search_param, $filter_program, $offset, $results_per_page);
    
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
    <title>BENEPESO | Verification</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="home.css?v=17" />
    <link rel="stylesheet" href="frontend_polish.css?v=20260921">
    <link rel="stylesheet" href="beneficiary_responsive.css?v=10">
    <link rel="stylesheet" href="beneficiary_content_enhancements.css?v=1">
    <link rel="stylesheet" href="beneficiary_content_polish.css?v=9">
    <link rel="stylesheet" href="authenticated_experience.css?v=6">
    <link rel="stylesheet" href="verification.css?v=15" />
    <link rel="stylesheet" href="beneficiary_mobile.css?v=18">
    <link rel="stylesheet" href="system_readability.css?v=1">
<script src="frontend_polish.js?v=20260923" defer></script>
    <script src="beneficiary_content_polish.js?v=1" defer></script>
</head>
<body class="verification-page">

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
      <a class="menu-item" href="home.php">Home</a>
      <a class="menu-item" href="programs.php">Programs</a>
      <a class="menu-item" href="about.php">About</a>

      <div class="account-area" id="accountWrap">
        <button class="account-button active" id="accountButton" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="accountDropdown">
          <span class="account-icon">
              <?php echo htmlspecialchars($first_char); ?>
              <?php if ($user_profile_src !== ''): ?><img src="<?php echo htmlspecialchars($user_profile_src); ?>" alt="" onerror="this.remove()"><?php endif; ?>
          </span>
          <span class="account-text"><?php echo htmlspecialchars($user_display_name); ?></span>
          <span class="account-arrow">▾</span>
        </button>

        <div class="account-dropdown" id="accountDropdown">
                    <a class="account-dropdown-link" href="profile.php"><span class="account-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></span><span>My Profile</span></a>
                    <a class="account-dropdown-link" href="verification.php" aria-current="page"><span class="account-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.6 3.1 7.9 7.5 9.5 4.4-1.6 7.5-4.9 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg></span><span>Verification</span></a>
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

<main class="page-wrap">
    
    <section class="welcome-area verify-hero stagger-1">
        <div class="content-wrap centered-hero">
            <div class="verify-hero-content">
                <div class="verify-badge">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4.5 6v5.5c0 4.6 3.1 7.9 7.5 9.5 4.4-1.6 7.5-4.9 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg>
                    <span>Barangay beneficiary lookup</span>
                </div>
                <h1 class="welcome-title">
                    Program <span class="welcome-highlight">Verification</span>
                </h1>
                <p class="welcome-text centered-text">
                    Search for a beneficiary in your registered barangay and in an approved program batch that you have applied to. Results are limited and do not replace official PESO confirmation.
                </p>

                <div class="verification-route-choice" aria-label="Choose how to check an application">
                    <a href="profile.php#my-programs"><strong>Checking your own application?</strong><span>Open My Applications for complete private details.</span></a>
                    <div><strong>Checking your barangay?</strong><span>Search a masked beneficiary record only within one of your applied program batches.</span></div>
                </div>

                <form id="searchForm" action="verification.php" method="GET" class="v-search-box">
                    <div class="v-search-intro">
                        <span class="v-search-intro-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.5 2.8 7.6 7 9 4.2-1.4 7-4.5 7-9V6l-7-3Z"></path><path d="m9 12 2 2 4-4"></path></svg></span>
                        <div><strong>Find a beneficiary record</strong><small>Select one of your applied batches, then enter the resident's registered name.</small></div>
                    </div>
                    <ol class="v-lookup-steps" aria-label="Verification steps">
                        <li><span>1</span><strong>Choose batch</strong></li>
                        <li><span>2</span><strong>Enter exact name</strong></li>
                        <li><span>3</span><strong>Review status</strong></li>
                    </ol>
                    <div class="v-input-wrapper">
                        <div class="v-search-field v-type-field">
                            <label class="v-search-field-label" for="programTypeFilter">Program</label>
                            <span class="v-select-shell">
                                <svg class="v-select-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1"></rect><rect x="14" y="4" width="6" height="6" rx="1"></rect><rect x="4" y="14" width="6" height="6" rx="1"></rect><rect x="14" y="14" width="6" height="6" rx="1"></rect></svg>
                                <select id="programTypeFilter" class="v-select" aria-label="Filter batches by program">
                                    <option value="">All programs</option>
                                    <option value="TUPAD">TUPAD</option>
                                    <option value="SPES">SPES</option>
                                    <option value="MSME">MSME</option>
                                </select>
                            </span>
                            <small class="v-search-help">Filter the schedules before choosing a batch.</small>
                        </div>
                        <label class="v-search-field v-program-field" for="programFilter">
                            <span class="v-search-field-label">Program and batch</span>
                            <span class="v-select-shell">
                            <svg class="v-select-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 3v4M16 3v4M4 10h16"></path></svg>
                            <select name="program_filter" id="programFilter" class="v-select" required aria-describedby="verificationUseNotice">
                            <option value="">Select an exact batch</option>
                            <?php if ($programs_list): ?>
                                <?php $program_group = null; ?>
                                <?php while($p_row = $programs_list->fetch_assoc()): ?>
                                    <?php if ($program_group !== $p_row['program_name']): ?>
                                        <?php if ($program_group !== null): ?></optgroup><?php endif; ?>
                                        <?php $program_group = $p_row['program_name']; ?>
                                        <optgroup label="<?= htmlspecialchars($program_group) ?>">
                                    <?php endif; ?>
                                    <?php
                                    $batch_code = trim((string)($p_row['program_code'] ?? '')) ?: 'Batch ' . (int)$p_row['program_id'];
                                    $program_type = stripos((string)$p_row['program_name'], 'SPES') !== false ? 'SPES' : (stripos((string)$p_row['program_name'], 'MSME') !== false ? 'MSME' : 'TUPAD');
                                    $batch_period = '';
                                    if (!empty($p_row['start_date'])) {
                                        $batch_period = ' - ' . date('M Y', strtotime((string)$p_row['start_date']));
                                    }
                                    ?>
                                    <option value="<?= (int)$p_row['program_id'] ?>" data-program-type="<?= htmlspecialchars($program_type) ?>" <?= ((string)$filter_program === (string)$p_row['program_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars('Batch ' . $batch_code . $batch_period) ?>
                                    </option>
                                <?php endwhile; ?>
                                <?php if ($program_group !== null): ?></optgroup><?php endif; ?>
                            <?php endif; ?>
                            </select>
                            </span>
                            <small class="v-search-help" id="batchFilterHelp">Choose the exact approved schedule.</small>
                        </label>
                        <div class="v-search-field v-resident-field">
                            <label class="v-search-field-label" for="searchInput">Resident name</label>
                            <span class="v-resident-control">
                                <span class="v-search-entry">
                                    <svg class="v-search-entry-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
                                    <input type="text" name="search" id="searchInput" class="v-input" placeholder="Start typing a registered name" value="<?= htmlspecialchars($search_query) ?>" autocomplete="off" minlength="1" required role="combobox" aria-autocomplete="list" aria-controls="nameSuggestions" aria-expanded="false">
                                </span>
                                <button type="submit" class="v-btn" aria-label="Verify beneficiary record">
                                    <span class="v-btn-default"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg><span class="v-btn-label">Verify</span></span>
                                    <span class="v-btn-progress" hidden><i aria-hidden="true"></i>Checking</span>
                                </button>
                            </span>
                            <div class="v-name-suggestions" id="nameSuggestions" role="listbox" aria-label="Matching beneficiary names" hidden></div>
                            <small class="v-search-help">Suggestions show masked matches only from your barangay and selected applied batch.</small>
                        </div>
                    </div>
                    <div class="privacy-disclaimer" id="verificationUseNotice">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <span>Search is limited to your registered barangay and program batches you joined. Do not copy or redistribute another resident's information.</span>
                    </div>
                    <?php if ($search_query !== '' || $filter_program !== ''): ?>
                        <a class="verification-reset" href="verification.php">Clear lookup and start again</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </section>

    <!-- RESULTS AREA WITH ID FOR AJAX TARGETING -->
    <section id="resultsArea" class="content-wrap results-area stagger-2" aria-live="polite" aria-busy="false" tabindex="-1">
        <?php if ($search_result && $search_result->num_rows > 0): ?>
            <div class="results-header">
                <h2>Verification Results</h2>
                <p>Limited matching records from your barangay and selected applied batch are shown below.</p>
            </div>
            
            <div class="results-list">
                <?php while($row = $search_result->fetch_assoc()): ?>
                    <div class="v-card-horizontal">
                        <div class="v-card-left">
                            <div class="v-tags">
                                <span class="v-tag"><?= htmlspecialchars($row['program_name']) ?></span>
                                <span class="v-tag v-tag-batch">Batch: <?= htmlspecialchars($row['program_code'] ?: ('#' . $row['program_id'])) ?></span>
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
        $lastNameLength = mb_strlen($lName, 'UTF-8');
        $maskedLastName = mb_substr($lName, 0, 1, 'UTF-8') . str_repeat('*', max(0, $lastNameLength - 1));
        $secureName = $fName . ' ' . $maskedLastName;
    } else {
        // Fallback if they only have one name string: mask half of the string
        $len = mb_strlen($fName, 'UTF-8');
        $secureName = mb_substr($fName, 0, (int)ceil($len / 2), 'UTF-8') . str_repeat('*', (int)floor($len / 2));
    }
?>
                            <?php
                            $resultProfileFilename = basename((string)($row['user_profile_pic'] ?? ''));
                            $resultProfileImage = $resultProfileFilename !== '' && $resultProfileFilename !== 'default_user.png' && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $resultProfileFilename)
                                ? 'uploads/' . rawurlencode($resultProfileFilename)
                                : '';
                            $resultInitial = mb_strtoupper(mb_substr(trim((string)($row['first_name'] ?? $row['full_name'] ?? 'B')), 0, 1, 'UTF-8'), 'UTF-8');
                            ?>
                            <div class="v-person">
                                <span class="v-person-avatar" aria-hidden="true">
                                    <span><?= htmlspecialchars($resultInitial ?: 'B') ?></span>
                                    <?php if ($resultProfileImage !== ''): ?><img src="<?= htmlspecialchars($resultProfileImage) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?>
                                </span>
                                <h3 class="v-name" title="Name partially hidden for Data Privacy compliance">
                                    <?= htmlspecialchars($secureName) ?>
                                    <svg class="v-name-lock" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                    </svg>
                                </h3>
                            </div>
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
                                <span class="v-label">Record Updated</span>
                                <span class="v-val date-val"><?= date("M d, Y", strtotime($row['updated_at'] ?: $row['created_at'])) ?></span>
                            </div>
                        </div>
                        <?php
                        $approvalKey = strtolower(trim((string)($row['approval_status'] ?? 'pending')));
                        $availmentKey = strtolower(trim((string)($row['availment_status'] ?? 'not yet availed')));
                        $resultNextAction = 'Wait for PESO to complete the application review.';
                        if ($approvalKey === 'rejected') $resultNextAction = 'The applicant may contact PESO for clarification about the recorded decision.';
                        elseif ($approvalKey === 'approved') {
                            $resultActions = [
                                'not yet availed' => 'Review the official requirements and wait for the document-submission instruction.',
                                'requirements received' => 'Submitted documents are recorded; wait for PESO validation and the next schedule.',
                                'orientation' => 'Follow the official orientation schedule issued to the applicant.',
                                'examination' => 'Follow the official examination schedule issued to the applicant.',
                                'exam passed' => 'Wait for the official placement or orientation instruction.',
                                'exam failed' => 'The applicant may contact PESO for clarification about the examination result.',
                                'ongoing' => 'Continue following the official program activity schedule.',
                                'salary distribution' => 'Follow the official distribution schedule and identification instructions.',
                                'completed' => 'No further action is required unless the record needs correction.',
                                'not qualified' => 'The applicant may contact PESO for clarification or future opportunities.',
                                'cancelled' => 'This application is no longer active for the selected batch.',
                            ];
                            $resultNextAction = $resultActions[$availmentKey] ?? 'Wait for the next official PESO instruction.';
                        }
                        ?>
                        <div class="verification-next-action"><span>What happens next</span><strong><?= htmlspecialchars($resultNextAction) ?></strong></div>
                    </div>
                <?php endwhile; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <?php
                $qs = "";
                if(isset($_GET['program_filter'])) $qs .= "&program_filter=".urlencode($_GET['program_filter']);
                if(isset($_GET['search'])) $qs .= "&search=".urlencode($_GET['search']);
                ?>
                <nav class="pagination-wrapper" aria-label="Verification result pages">
                    <?php if($current_page > 1): ?>
                        <a href="?page=<?= $current_page - 1 ?><?= $qs ?>" class="page-btn nav-btn" aria-label="Previous results page">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                            <span>Previous</span>
                        </a>
                    <?php endif; ?>

                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?><?= $qs ?>" class="page-btn <?= ($i == $current_page) ? 'active' : '' ?>" <?= ($i == $current_page) ? 'aria-current="page"' : '' ?> aria-label="Results page <?= $i ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if($current_page < $total_pages): ?>
                        <a href="?page=<?= $current_page + 1 ?><?= $qs ?>" class="page-btn nav-btn" aria-label="Next results page">
                            <span>Next</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
            
        <?php elseif ($rate_limited): ?>
            <div class="v-no-results" role="alert">
                <div class="v-no-results-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v6M12 17h.01"></path></svg>
                </div>
                <h3>Lookup temporarily limited</h3>
                <p>Too many verification requests were made in a short period. Please wait a few minutes before trying again or contact PESO for assistance.</p>
            </div>
        <?php elseif ($batch_required): ?>
            <div class="v-no-results">
                <div class="v-no-results-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 3v4M16 3v4M4 10h16"></path></svg>
                </div>
                <h3>Select the exact batch</h3>
                <p>Choose one of your applied program batches before searching for a resident.</p>
            </div>
        <?php elseif ($program_access_denied): ?>
            <div class="v-no-results" role="alert">
                <div class="v-no-results-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3 5 6v5c0 4.5 2.8 7.6 7 9 4.2-1.4 7-4.5 7-9V6l-7-3Z"></path><path d="M9 9l6 6M15 9l-6 6"></path></svg>
                </div>
                <h3>Program not available for lookup</h3>
                <p>You can search only approved program batches linked to your own application.</p>
            </div>
        <?php elseif ($search_too_short): ?>
            <div class="v-no-results">
                <div class="v-no-results-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </div>
                <h3>Enter a resident name</h3>
                <p>Enter the registered name or choose a masked suggestion from your barangay and selected batch.</p>
            </div>
        <?php elseif ($search_ready): ?>
            <div class="v-no-results">
                <div class="v-no-results-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </div>
                <h3>No Records Found</h3>
                <p>No matching beneficiary was found in your barangay and selected applied batch.</p>
                <ul class="verification-no-match-reasons"><li>The selected batch may be different.</li><li>The registered spelling may not match.</li><li>The record may still be awaiting entry or validation.</li><li>The resident may be registered in another barangay.</li></ul>
            </div>
        <?php else: ?>
            <div class="v-empty-state">
                <div class="v-empty-icon pulse-animation">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 5 6v5c0 4.5 2.8 7.6 7 9 4.2-1.4 7-4.5 7-9V6l-7-3Z"></path><circle cx="11" cy="11" r="3"></circle><path d="m13.5 13.5 2 2"></path></svg>
                </div>
                <span class="v-empty-kicker">Secure barangay lookup</span>
                <h3>Search your program community</h3>
                <p>Select a batch you applied to, then search for a beneficiary registered in <?= htmlspecialchars($user_barangay ?: 'your barangay') ?>.</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="verification-guidance content-wrap bp-content-module" aria-labelledby="verificationGuideTitle">
        <div class="content-enhancement-heading">
            <span class="content-enhancement-eyebrow">Understanding the result</span>
            <h2 id="verificationGuideTitle">Record Status Guide</h2>
            <p>A displayed result confirms only that a matching record exists in the selected batch. Program decisions and schedules must still be confirmed through official PESO instructions.</p>
        </div>
        <div class="verification-status-grid">
            <article><strong>Pending</strong><p>The application has been received and is awaiting or undergoing review.</p></article>
            <article><strong>Approved</strong><p>PESO has approved the application record; review the recorded availment stage for the next action.</p></article>
            <article><strong>Requirements Received</strong><p>Submitted documents have been recorded and may still be undergoing validation.</p></article>
            <article><strong>Ongoing</strong><p>The beneficiary's participation is currently active for the selected program.</p></article>
            <article><strong>Completed</strong><p>The beneficiary's participation has been recorded as completed.</p></article>
            <article><strong>Not approved / Not qualified</strong><p>The record did not proceed for this batch. The applicant may contact PESO for clarification.</p></article>
        </div>
        <div class="verification-correction-panel">
            <div><strong>Is a record missing or inaccurate?</strong><p>The concerned resident should use My Profile or contact PESO Vinzons. Do not submit another application solely to correct an existing record.</p></div>
            <div class="verification-help-actions">
                <a href="profile.php#my-programs"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V9l8-5 8 5v10a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1Z"></path></svg><span>Track my application</span></a>
                <a href="privacy_notice.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 2.8 7.6 7 9 4.2-1.4 7-4.5 7-9V6l-7-3Z"></path><path d="m9 12 2 2 4-4"></path></svg><span>Privacy Notice</span></a>
                <a class="is-primary" href="https://mail.google.com/mail/?view=cm&amp;fs=1&amp;to=lguvinzonspeso@gmail.com&amp;su=BENEPESO%20Record%20Inquiry" target="_blank" rel="noopener noreferrer"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"></path><path d="m4 7 8 6 8-6"></path></svg><span>Contact PESO</span></a>
            </div>
        </div>
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
    // Dropdown functionality
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

    const menuBtn = document.getElementById('menuButton');
    const menuArea = document.getElementById('menuArea');
    if(menuBtn && menuArea) {
        menuBtn.addEventListener('click', function() {
            const isOpen = menuArea.classList.toggle('open');
            menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    // Submit one deliberate lookup at a time; do not query while the user types.
    const searchInput = document.getElementById('searchInput');
    const programFilter = document.getElementById('programFilter');
    const programTypeFilter = document.getElementById('programTypeFilter');
    const batchFilterHelp = document.getElementById('batchFilterHelp');
    const resultsArea = document.getElementById('resultsArea');
    const searchForm = document.getElementById('searchForm');
    const submitButton = searchForm ? searchForm.querySelector('.v-btn') : null;
    const submitDefault = submitButton ? submitButton.querySelector('.v-btn-default') : null;
    const submitLabel = submitButton ? submitButton.querySelector('.v-btn-label') : null;
    const submitProgress = submitButton ? submitButton.querySelector('.v-btn-progress') : null;
    const suggestionList = document.getElementById('nameSuggestions');
    let suggestionTimer = null;
    let suggestionRequest = null;
    let suggestionButtons = [];
    let activeSuggestion = -1;
    const suggestionCache = new Map();
    const initialBatchValue = programFilter.value;
    const batchCatalog = Array.from(programFilter.querySelectorAll('option[value]:not([value=""])')).map(option => ({
        value: option.value,
        label: option.textContent.trim(),
        type: option.dataset.programType || 'TUPAD',
        group: option.closest('optgroup')?.label || option.dataset.programType || 'Program'
    }));
    const verificationMenuIcons = {
        programs: '<rect x="4" y="4" width="6" height="6" rx="1"></rect><rect x="14" y="4" width="6" height="6" rx="1"></rect><rect x="4" y="14" width="6" height="6" rx="1"></rect><rect x="14" y="14" width="6" height="6" rx="1"></rect>',
        TUPAD: '<rect x="3" y="7" width="18" height="12" rx="2"></rect><path d="M8 7V5h8v2M3 12h18M10 12v2h4v-2"></path>',
        SPES: '<path d="m3 9 9-5 9 5-9 5-9-5Z"></path><path d="M7 12v4c3 2 7 2 10 0v-4M21 9v6"></path>',
        MSME: '<path d="M4 10v10h16V10M3 10l2-6h14l2 6"></path><path d="M3 10c1 2 3 2 4 0 1 2 3 2 5 0 2 2 4 2 5 0 1 2 3 2 4 0M9 20v-5h6v5"></path>',
        batch: '<rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 3v4M16 3v4M4 10h16"></path>'
    };

    function decorateVerificationSelectMenu(menu) {
        const trigger = document.querySelector(`[aria-controls="${menu.id}"]`);
        const select = trigger?.closest('.v-select-shell')?.querySelector('select');
        if (!select || !['programTypeFilter', 'programFilter'].includes(select.id)) return;
        menu.classList.add('v-verification-select-menu');
        menu.querySelectorAll('.bp-select-option').forEach(item => {
            if (item.querySelector('.v-menu-option-icon')) return;
            const option = select.options[Number(item.dataset.index)];
            if (!option) return;
            const iconKey = select.id === 'programFilter' ? 'batch' : (option.value || 'programs');
            const icon = document.createElement('span');
            icon.className = 'v-menu-option-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.innerHTML = `<svg viewBox="0 0 24 24">${verificationMenuIcons[iconKey] || verificationMenuIcons.programs}</svg>`;
            item.prepend(icon);
        });
    }

    const verificationSelectMenuObserver = new MutationObserver(records => {
        records.forEach(record => record.addedNodes.forEach(node => {
            if (node instanceof Element && node.matches('.bp-select-menu')) {
                decorateVerificationSelectMenu(node);
            }
        }));
    });
    verificationSelectMenuObserver.observe(document.body, { childList: true });

    function rebuildBatchOptions(programType, selectedValue = '') {
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = programType ? `Select a ${programType} batch` : 'Select an exact batch';
        programFilter.replaceChildren(placeholder);
        const groups = new Map();
        batchCatalog.filter(item => !programType || item.type === programType).forEach(item => {
            if (!groups.has(item.group)) {
                const group = document.createElement('optgroup');
                group.label = item.group;
                groups.set(item.group, group);
                programFilter.appendChild(group);
            }
            const option = document.createElement('option');
            option.value = item.value;
            option.textContent = item.label;
            option.dataset.programType = item.type;
            option.selected = item.value === selectedValue;
            groups.get(item.group).appendChild(option);
        });
        programFilter.dispatchEvent(new Event('bp-select-sync'));
        if (batchFilterHelp) {
            batchFilterHelp.textContent = programType
                ? `Showing ${programType} schedules only. Choose the exact batch.`
                : 'Choose the exact approved schedule.';
        }
    }

    const initiallySelectedBatch = batchCatalog.find(item => item.value === initialBatchValue);
    if (initiallySelectedBatch) programTypeFilter.value = initiallySelectedBatch.type;
    rebuildBatchOptions(programTypeFilter.value, initialBatchValue);

    function closeSuggestions() {
        if (!suggestionList) return;
        suggestionList.hidden = true;
        suggestionList.replaceChildren();
        suggestionButtons = [];
        activeSuggestion = -1;
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.removeAttribute('aria-activedescendant');
    }

    function setActiveSuggestion(index) {
        if (!suggestionButtons.length) return;
        activeSuggestion = (index + suggestionButtons.length) % suggestionButtons.length;
        suggestionButtons.forEach((button, buttonIndex) => {
            const isActive = buttonIndex === activeSuggestion;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        const activeButton = suggestionButtons[activeSuggestion];
        searchInput.setAttribute('aria-activedescendant', activeButton.id);
        activeButton.scrollIntoView({ block: 'nearest' });
    }

    function selectSuggestion(item) {
        searchInput.value = item.value || '';
        closeSuggestions();
        searchInput.focus();
    }

    function showSuggestionMessage(message) {
        if (!suggestionList) return;
        suggestionList.replaceChildren();
        const state = document.createElement('div');
        state.className = 'v-suggestion-state';
        state.textContent = message;
        suggestionList.appendChild(state);
        suggestionList.hidden = false;
        searchInput.setAttribute('aria-expanded', 'true');
    }

    function renderSuggestions(items) {
        if (!suggestionList) return;
        suggestionList.replaceChildren();
        suggestionButtons = [];
        activeSuggestion = -1;
        if (!items.length) {
            showSuggestionMessage('No matching beneficiary in this batch and barangay.');
            return;
        }
        items.forEach((item, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.id = `beneficiarySuggestion${index}`;
            option.className = 'v-suggestion-option';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');

            const avatar = document.createElement('span');
            avatar.className = 'v-suggestion-avatar';
            avatar.textContent = item.initial || 'B';
            if (item.photo) {
                const image = document.createElement('img');
                image.src = item.photo;
                image.alt = '';
                image.loading = 'lazy';
                image.addEventListener('error', () => image.remove());
                avatar.appendChild(image);
            }

            const copy = document.createElement('span');
            copy.className = 'v-suggestion-copy';
            const name = document.createElement('strong');
            name.textContent = item.label || 'Matching beneficiary';
            const context = document.createElement('small');
            context.textContent = 'Matching record in selected applied batch';
            copy.append(name, context);
            option.append(avatar, copy);
            option.addEventListener('mousedown', event => event.preventDefault());
            option.addEventListener('click', () => selectSuggestion(item));
            suggestionList.appendChild(option);
            suggestionButtons.push(option);
        });
        suggestionList.hidden = false;
        searchInput.setAttribute('aria-expanded', 'true');
    }

    function requestSuggestions() {
        const prefix = searchInput.value.trim();
        const batchId = programFilter.value;
        if (!batchId || prefix.length < 1) {
            closeSuggestions();
            return;
        }
        const cacheKey = `${batchId}|${prefix.toLocaleLowerCase()}`;
        if (suggestionCache.has(cacheKey)) {
            renderSuggestions(suggestionCache.get(cacheKey));
            return;
        }
        if (suggestionRequest) suggestionRequest.abort();
        suggestionRequest = new AbortController();
        showSuggestionMessage('Searching your barangay and selected batch...');
        const suggestionUrl = `verification.php?suggest=1&q=${encodeURIComponent(prefix)}&program_filter=${encodeURIComponent(batchId)}`;
        fetch(suggestionUrl, {
            credentials: 'same-origin',
            cache: 'no-store',
            signal: suggestionRequest.signal,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(response => {
                if (!response.ok) throw new Error('Suggestion lookup unavailable');
                return response.json();
            })
            .then(data => {
                const suggestionItems = Array.isArray(data.items) ? data.items : [];
                suggestionCache.set(cacheKey, suggestionItems);
                renderSuggestions(suggestionItems);
            })
            .catch(error => {
                if (error.name !== 'AbortError') showSuggestionMessage('Suggestions are temporarily unavailable. You can still enter the exact name.');
            });
    }

    searchInput.addEventListener('input', () => {
        window.clearTimeout(suggestionTimer);
        suggestionTimer = window.setTimeout(requestSuggestions, 180);
    });
    searchInput.addEventListener('focus', () => {
        if (searchInput.value.trim().length >= 1 && programFilter.value) requestSuggestions();
    });
    searchInput.addEventListener('keydown', event => {
        if (!suggestionList || suggestionList.hidden || !suggestionButtons.length) {
            if (event.key === 'Escape') closeSuggestions();
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveSuggestion(activeSuggestion + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveSuggestion(activeSuggestion - 1);
        } else if (event.key === 'Enter' && activeSuggestion >= 0) {
            event.preventDefault();
            suggestionButtons[activeSuggestion].click();
        } else if (event.key === 'Escape') {
            closeSuggestions();
        }
    });
    programFilter.addEventListener('change', () => {
        closeSuggestions();
        if (searchInput.value.trim().length >= 1) requestSuggestions();
    });
    programTypeFilter.addEventListener('change', () => {
        rebuildBatchOptions(programTypeFilter.value);
        closeSuggestions();
        searchInput.value = '';
        programFilter.dispatchEvent(new Event('change', { bubbles: true }));
        programFilter.focus();
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('.v-resident-field')) closeSuggestions();
    });

    function setLookupLoading(isLoading) {
        resultsArea.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        if (searchForm) searchForm.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        if (submitButton) submitButton.disabled = isLoading;
        if (submitDefault) submitDefault.hidden = isLoading;
        else if (submitLabel) submitLabel.hidden = isLoading;
        if (submitProgress) submitProgress.hidden = !isLoading;
        resultsArea.classList.toggle('is-loading', isLoading);
    }

    function fetchResults() {
        const query = searchInput.value.trim();
        const filter = programFilter.value;
        if (query.length < 1 || !filter) return;
        
        setLookupLoading(true);

        const url = `verification.php?search=${encodeURIComponent(query)}&program_filter=${encodeURIComponent(filter)}`;

        fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(response => {
                if (!response.ok) throw new Error(`Lookup failed with status ${response.status}`);
                return response.text();
            })
            .then(html => {
                // Parse the new HTML and extract just the results area
                const parser = new DOMParser();
                const doc = parser.parseDocumentFromString(html, 'text/html');
                const returnedResults = doc.getElementById('resultsArea');
                if (!returnedResults) throw new Error('Lookup response was incomplete.');
                const newResults = returnedResults.innerHTML;
                
                // Inject the new results seamlessly
                resultsArea.innerHTML = newResults;
                history.replaceState(null, '', url);
                resultsArea.focus({ preventScroll: true });
            })
            .catch(error => {
                console.error('Error fetching search results:', error);
                // Keep the lookup usable if an enhanced in-page request is blocked or interrupted.
                window.location.assign(url);
            })
            .finally(() => setLookupLoading(false));
    }

    if (searchForm) {
        searchForm.addEventListener('submit', function(event) {
            if (!searchForm.checkValidity()) return;
            event.preventDefault();
            fetchResults();
        });
    }
});
</script>
</body>
</html>
