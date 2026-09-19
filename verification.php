<?php
require_once __DIR__ . '/auth.php';
require "db.php";
require_once __DIR__ . '/auth_rate_limit.php';

check_user_role('user');

$user_id = (int)$_SESSION["user_id"];
$user_display_name = "User";
$first_char = "U";
$user_barangay = "";
$user_profile_src = '';

// 1. Fetch Logged-in User's Data 
$stmt = $conn->prepare("SELECT first_name, middle_name, last_name, ext_name, barangay, profile_pic FROM users WHERE user_id=? LIMIT 1");
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
    $profile_filename = basename((string)($user_data['profile_pic'] ?? ''));
    if ($profile_filename !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $profile_filename)) {
        $user_profile_src = 'uploads/' . rawurlencode($profile_filename);
    }
}

// 2. Fetch approved batches for a clear Program > Batch filter.
$programs_list = $conn->query("SELECT program_id, program_name, program_code, start_date, end_date FROM programs WHERE approval_status = 'Approved' AND (UPPER(program_name) LIKE '%TUPAD%' OR UPPER(program_name) LIKE '%SPES%' OR UPPER(program_name) LIKE '%MSME%') ORDER BY program_name ASC, start_date DESC, program_id DESC");

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
$search_ready = false;
$search_too_short = false;
$batch_required = false;
$rate_limited = false;

if (isset($_GET['search'])) {
    $search_query = trim($_GET['search'] ?? "");
    $valid_program_filter = ctype_digit((string)$filter_program) && (int)$filter_program > 0;
    $batch_required = $search_query !== '' && !$valid_program_filter;
    $search_ready = mb_strlen($search_query) >= 5 && $valid_program_filter;
    $search_too_short = $search_query !== '' && !$search_ready;
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

// A program filter alone must never reveal a barangay-wide beneficiary list.
if ($search_ready) {
    $search_param = $search_query;
    
    // Base WHERE clause - restricts search strictly to the user's barangay for privacy
    $where_clause = "WHERE b.barangay = ? AND p.approval_status = 'Approved'
        AND (UPPER(p.program_name) LIKE '%TUPAD%' OR UPPER(p.program_name) LIKE '%SPES%' OR UPPER(p.program_name) LIKE '%MSME%')";
    
    if (!empty($search_query)) $where_clause .= " AND LOWER(TRIM(b.full_name)) = LOWER(TRIM(?))";
    if (!empty($filter_program)) {
        $where_clause .= " AND b.program_id = ?";
    }

    // --- A. Get Total Count for Pagination ---
    $count_sql = "SELECT COUNT(*) as total FROM beneficiaries b JOIN programs p ON b.program_id = p.program_id " . $where_clause;
    $count_stmt = $conn->prepare($count_sql);
    
    if (!empty($search_query) && !empty($filter_program)) {
        $count_stmt->bind_param("ssi", $user_barangay, $search_param, $filter_program);
    } elseif (!empty($search_query)) {
        $count_stmt->bind_param("ss", $user_barangay, $search_param);
    } elseif (!empty($filter_program)) {
        $count_stmt->bind_param("si", $user_barangay, $filter_program);
    } else {
        $count_stmt->bind_param("s", $user_barangay);
    }
    $count_stmt->execute();
    $total_results = $count_stmt->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_results / $results_per_page);

    // --- B. Fetch the limited data for current page ---
    $sql = "SELECT b.program_id, b.first_name, b.last_name, b.full_name, b.barangay,
                   b.approval_status, b.availment_status, b.created_at, b.updated_at,
                   p.program_name, p.program_code
              FROM beneficiaries b
              JOIN programs p ON b.program_id = p.program_id " . $where_clause . " ORDER BY b.created_at DESC LIMIT ?, ?";
    $stmt = $conn->prepare($sql);
    
    if (!empty($search_query) && !empty($filter_program)) {
        $stmt->bind_param("ssiii", $user_barangay, $search_param, $filter_program, $offset, $results_per_page);
    } elseif (!empty($search_query)) {
        $stmt->bind_param("ssii", $user_barangay, $search_param, $offset, $results_per_page);
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
    <title>BENEPESO | Verification</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="home.css?v=16" />
    <link rel="stylesheet" href="verification.css?v=8" />
    <link rel="stylesheet" href="frontend_polish.css?v=17">
    <link rel="stylesheet" href="beneficiary_responsive.css?v=10">
    <link rel="stylesheet" href="beneficiary_content_enhancements.css?v=1">
    <link rel="stylesheet" href="beneficiary_content_polish.css?v=9">
    <link rel="stylesheet" href="authenticated_experience.css?v=6">
<script src="frontend_polish.js?v=17" defer></script>
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
        <button class="account-button active" id="accountButton" type="button">
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
                    <span>Private status lookup</span>
                </div>
                <h1 class="welcome-title">
                    Record <span class="welcome-highlight">Verification</span>
                </h1>
                <p class="welcome-text centered-text">
                    Confirm whether an exact beneficiary name appears in a specific approved PESO program batch. This lookup does not determine program eligibility or replace official PESO confirmation.
                </p>

                <div class="verification-route-choice" aria-label="Choose how to check an application">
                    <a href="profile.php#my-programs"><strong>Checking your own application?</strong><span>Open My Applications for complete private details.</span></a>
                    <div><strong>Verifying another record?</strong><span>Continue below using the exact batch and resident name.</span></div>
                </div>

                <form id="searchForm" action="verification.php" method="GET" class="v-search-box">
                    <div class="v-search-intro">
                        <span class="v-search-intro-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.5 2.8 7.6 7 9 4.2-1.4 7-4.5 7-9V6l-7-3Z"></path><path d="m9 12 2 2 4-4"></path></svg></span>
                        <div><strong>Find a beneficiary record</strong><small>Select the exact batch, then enter the resident's complete registered name.</small></div>
                    </div>
                    <div class="v-input-wrapper">
                        <label class="v-search-field v-program-field" for="programFilter">
                            <span class="v-search-field-label">Program and batch</span>
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
                                    $batch_period = '';
                                    if (!empty($p_row['start_date'])) {
                                        $batch_period = ' - ' . date('M Y', strtotime((string)$p_row['start_date']));
                                    }
                                    ?>
                                    <option value="<?= (int)$p_row['program_id'] ?>" <?= ((string)$filter_program === (string)$p_row['program_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars('Batch ' . $batch_code . $batch_period) ?>
                                    </option>
                                <?php endwhile; ?>
                                <?php if ($program_group !== null): ?></optgroup><?php endif; ?>
                            <?php endif; ?>
                            </select>
                        </label>
                        <label class="v-search-field v-resident-field" for="searchInput">
                            <span class="v-search-field-label">Resident name</span>
                            <span class="v-search-entry">
                                <svg class="v-search-entry-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
                                <input type="text" name="search" id="searchInput" class="v-input" placeholder="Enter complete registered name" value="<?= htmlspecialchars($search_query) ?>" autocomplete="off" minlength="5" required>
                                <button type="submit" class="v-btn" aria-label="Verify beneficiary record">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                    <span>Verify</span>
                                </button>
                            </span>
                        </label>
                    </div>
                    <div class="privacy-disclaimer" id="verificationUseNotice">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <span>Use this service only for a legitimate beneficiary-status inquiry. Results are intentionally limited; do not copy or redistribute another resident's information.</span>
                    </div>
                    <?php if ($search_query !== '' || $filter_program !== ''): ?>
                        <a class="verification-reset" href="verification.php">Clear lookup and start again</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </section>

    <!-- RESULTS AREA WITH ID FOR AJAX TARGETING -->
    <section id="resultsArea" class="content-wrap results-area stagger-2">
        <?php if ($search_result && $search_result->num_rows > 0): ?>
            <div class="results-header">
                <h3>Verification Results</h3>
                <p>Limited matching records from the selected batch and your registered barangay are shown below.</p>
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
                <p>Choose the resident's program and batch before performing a record lookup.</p>
            </div>
        <?php elseif ($search_too_short): ?>
            <div class="v-no-results">
                <div class="v-no-results-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </div>
                <h3>Enter More Details</h3>
                <p>Enter the resident's complete registered name. Partial names, email addresses, and contact numbers are not accepted in this lookup.</p>
            </div>
        <?php elseif ($search_ready): ?>
            <div class="v-no-results">
                <div class="v-no-results-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </div>
                <h3>No Records Found</h3>
                <p>No matching record was found using the information provided. Check the spelling and selected batch, or contact PESO for official assistance.</p>
                <ul class="verification-no-match-reasons"><li>The selected batch may be different.</li><li>The registered name spelling may not match.</li><li>The record may still be awaiting entry or validation.</li><li>The resident may be registered under another barangay.</li></ul>
            </div>
        <?php else: ?>
            <div class="v-empty-state">
                <div class="v-empty-icon pulse-animation">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 5 6v5c0 4.5 2.8 7.6 7 9 4.2-1.4 7-4.5 7-9V6l-7-3Z"></path><circle cx="11" cy="11" r="3"></circle><path d="m13.5 13.5 2 2"></path></svg>
                </div>
                <span class="v-empty-kicker">Secure barangay lookup</span>
                <h3>Start a private verification</h3>
                <p>Resident records remain hidden until a specific name is entered.</p>
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
            <div><a href="profile.php#my-programs">Track my own application</a><a href="privacy_notice.php">Read Privacy Notice</a><a href="mailto:lguvinzonspeso@gmail.com?subject=BENEPESO%20Record%20Inquiry">Contact PESO</a></div>
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
            const isOpen = menuArea.classList.toggle('open');
            menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    // Submit one deliberate lookup at a time; do not query while the user types.
    const searchInput = document.getElementById('searchInput');
    const programFilter = document.getElementById('programFilter');
    const resultsArea = document.getElementById('resultsArea');
    function fetchResults() {
        const query = searchInput.value.trim();
        const filter = programFilter.value;
        if (query.length < 5 || !filter) return;
        
        resultsArea.style.opacity = '0.5'; // Visual loading cue
        resultsArea.style.pointerEvents = 'none';

        const url = `verification.php?search=${encodeURIComponent(query)}&program_filter=${encodeURIComponent(filter)}`;

        fetch(url, { credentials: 'same-origin' })
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
                resultsArea.style.opacity = '1';
                resultsArea.style.pointerEvents = 'auto';
                history.replaceState(null, '', url);
            })
            .catch(error => {
                console.error('Error fetching search results:', error);
                resultsArea.innerHTML = '<div class="v-no-results" role="alert"><h3>Verification is temporarily unavailable</h3><p>Your request could not be completed. Please try again or contact PESO Vinzons if the problem continues.</p></div>';
                resultsArea.style.opacity = '1';
                resultsArea.style.pointerEvents = 'auto';
            });
    }

    const searchForm = document.getElementById('searchForm');
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
