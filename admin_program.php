<?php
require_once __DIR__ . '/auth_session.php';
auth_enable_csrf_form_injection();
require "db.php";
require_once "batch_code_helper.php";
require_once "program_eligibility_helper.php";
require_once "tupad_category_helper.php";
require_once "program_status_helper.php";
ensure_program_eligibility_schema($conn);
ensure_tupad_category_schema($conn);

if (file_exists("functions.php")) { include_once "functions.php"; }
if (file_exists("activity_logger.php") && !function_exists('logActivity')) { include_once "activity_logger.php"; }

// ====== TIME AGO FUNCTION ======
if (!function_exists('time_ago')) {
    function time_ago($datetime, $full = false) {
        $now = new DateTime;
        $ago = new DateTime($datetime ?: 'now');
        $diff = $now->diff($ago);
        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;
        $string = array('y' => 'year', 'm' => 'month', 'w' => 'week', 'd' => 'day', 'h' => 'hour', 'i' => 'minute', 's' => 'second');
        foreach ($string as $k => &$v) {
            if ($diff->$k) { $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : ''); } else { unset($string[$k]); }
        }
        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }
}

if (!isset($_SESSION["admin_id"])) { header("Location: login.php"); exit(); }
sync_program_statuses($conn);

$admin_id = (int)$_SESSION["admin_id"];
$admin_name = "PESO Vinzons";
$admin_position = "Administrator";
$flash = $_SESSION["flash"] ?? "";
$flash_type = $_SESSION["flash_type"] ?? "success";
unset($_SESSION["flash"], $_SESSION["flash_type"]);

// Avatar logic imported from Dashboard
$admin_pic = "default_avatar.png"; 
$pic_path = "uploads/admin_pics/" . $admin_pic;
if (!file_exists($pic_path) || empty($admin_pic)) { $pic_path = "img/default_user.svg"; }

if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8"); }
}
function program_directory_icon(string $programName): string {
    if (stripos($programName, 'SPES') !== false) return 'ph-student';
    if (stripos($programName, 'MSME') !== false) return 'ph-storefront';
    return 'ph-hammer';
}
function tupad_category_icon(string $category): string {
    $category = strtolower($category);
    if (strpos($category, 'brigada') !== false) return 'ph-broom';
    if (strpos($category, 'clean') !== false) return 'ph-leaf';
    if (strpos($category, 'calamity') !== false) return 'ph-first-aid-kit';
    if (strpos($category, 'regular') !== false) return 'ph-briefcase';
    return 'ph-tag';
}

// Nav class helper imported from Dashboard
function navClass($fileName){
    $current = basename($_SERVER["PHP_SELF"]);
    return ($current === $fileName) ? "nav-item active" : "nav-item";
}

function build_query(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $k => $v) { if ($v === null || $v === "") unset($query[$k]); }
    return http_build_query($query);
}

// ROUTING STATE
$active_program = isset($_GET['program']) ? trim($_GET['program']) : null;
$tupadCategoryOptions = available_tupad_categories($conn);
$activeTupadCategoryOptions = active_tupad_categories($conn);

// ====== POST ACTIONS ======
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    auth_require_csrf();
    $action = $_POST["action"] ?? "";

    if ($action === "approve" || $action === "reject") {
        $program_id = (int)$_POST["program_id"];
        $new_status = ($action === "approve") ? "Approved" : "Rejected";
        if ($program_id <= 0) {
            $_SESSION["flash"] = "Select a valid batch.";
            $_SESSION["flash_type"] = "error";
            header("Location: admin_program.php"); exit();
        }
        $stmt = $conn->prepare("UPDATE programs SET approval_status = ?, updated_at = NOW() WHERE program_id = ? AND approval_status = 'Pending'");
        $stmt->bind_param("si", $new_status, $program_id);
        if ($stmt->execute() && $stmt->affected_rows === 1) {
            sync_program_statuses($conn);
            $_SESSION["flash"] = "Batch status updated to " . $new_status . ".";
        } else {
            $_SESSION["flash"] = "The batch was not found or has already been reviewed.";
            $_SESSION["flash_type"] = "error";
        }
        $redirect = $active_program ? "?program=".urlencode($active_program) : "";
        header("Location: admin_program.php" . $redirect); exit();
    }

    if ($action === "add_category") {
        $_SESSION["flash"] = "BENEPESO is configured for the official TUPAD, SPES, and MSME programs. Update an existing directory instead.";
        $_SESSION["flash_type"] = "error";
        header("Location: admin_program.php"); exit();
    }

    if ($action === "edit_category") {
        $cat_id = (int)$_POST["category_id"];
        $old_name = trim($_POST["old_program_name"]);
        $new_name = trim($_POST["program_name"]);
        $desc = trim($_POST["description"]);
        $elig = trim($_POST["eligibility"]);
        $req = trim($_POST["requirements"]);
        [$eligibleSex, $minimumAge, $maximumAge, $onePerHousehold] = clean_eligibility_rules($_POST);
        $current_img = trim($_POST["current_image"] ?? "");
        
        $new_image_path = $current_img;
        if (!empty($_FILES["image_path"]["name"]) && ($_FILES["image_path"]["error"] !== UPLOAD_ERR_OK || $_FILES["image_path"]["size"] > 5 * 1024 * 1024)) {
            $_SESSION["flash"] = "Upload a valid program image no larger than 5 MB.";
            $_SESSION["flash_type"] = "error";
            header("Location: admin_program.php"); exit();
        }
        if (!empty($_FILES["image_path"]["name"]) && $_FILES["image_path"]["error"] === 0 && $_FILES["image_path"]["size"] <= 5 * 1024 * 1024) {
            $file = $_FILES["image_path"];
            $allowed = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file["tmp_name"]);
            if (isset($allowed[$mime])) {
                $dir = __DIR__ . "/uploads/programs/";
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $filename = "prog_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $allowed[$mime];
                if (move_uploaded_file($file["tmp_name"], $dir . $filename)) { $new_image_path = "uploads/programs/" . $filename; }
            }
        }
        $stmt = $conn->prepare("UPDATE program_categories SET program_name=?, description=?, eligibility=?, requirements=?, image_path=?, eligible_sex=?, minimum_age=?, maximum_age=?, one_per_household=? WHERE category_id=?");
        $stmt->bind_param("ssssssiiii", $new_name, $desc, $elig, $req, $new_image_path, $eligibleSex, $minimumAge, $maximumAge, $onePerHousehold, $cat_id);
        if($stmt->execute()) {
            $upd = $conn->prepare("UPDATE programs SET program_name=?, image_path=?, description=?, eligibility=?, requirements=?, eligible_sex=?, minimum_age=?, maximum_age=?, one_per_household=? WHERE program_name=?");
            $upd->bind_param("ssssssiiis", $new_name, $new_image_path, $desc, $elig, $req, $eligibleSex, $minimumAge, $maximumAge, $onePerHousehold, $old_name);
            $upd->execute();
            $_SESSION["flash"] = "Program updated successfully.";
        }
        header("Location: admin_program.php"); exit();
    }

    if ($action === "add_batch") {
        $title = trim($_POST["program_name"] ?? ""); 
        $slots = (int)($_POST["slots"] ?? 0);
        $start_date = $_POST["start_date"];
        $end_date = $_POST["end_date"];
        $venue = trim($_POST["venue"] ?? "PESO Vinzons");
        $tupadCategory = submitted_tupad_category($_POST, $title);
        [$batchEligibleSex, $batchMinimumAge, $batchMaximumAge, $batchOnePerHousehold] = clean_eligibility_rules($_POST);

        if ($title === '' || $slots <= 0) {
            $_SESSION["flash"] = "Select a valid program and enter at least one slot.";
            $_SESSION["flash_type"] = "error";
            header("Location: admin_program.php"); exit();
        }
        if (!valid_batch_date_range($start_date, $end_date)) {
            $_SESSION["flash"] = "The end date must be the same as or later than the start date.";
            $_SESSION["flash_type"] = "error";
            header("Location: admin_program.php?program=".urlencode($title)); exit();
        }
        
        $catRes = $conn->query("SELECT description, eligibility, requirements, image_path, eligible_sex, minimum_age, maximum_age, one_per_household FROM program_categories WHERE program_name = '".$conn->real_escape_string($title)."'");
        $catData = $catRes ? $catRes->fetch_assoc() : null;
        if (!$catData) {
            $_SESSION["flash"] = "The selected program category no longer exists.";
            $_SESSION["flash_type"] = "error";
            header("Location: admin_program.php"); exit();
        }
        
        $batchYear = batch_year_from_date($start_date);
        if (!acquire_batch_code_lock($conn, $batchYear)) {
            $_SESSION["flash"] = "A batch number could not be reserved. Please try again.";
            $_SESSION["flash_type"] = "error";
            header("Location: admin_program.php?program=".urlencode($title)); exit();
        }
        $code = next_batch_code($conn, $start_date, $title, $tupadCategory);
        $stmt = $conn->prepare("INSERT INTO programs (program_code, program_name, tupad_category, description, eligibility, requirements, start_date, end_date, venue, image_path, slots, eligible_sex, minimum_age, maximum_age, one_per_household, status, created_by, approval_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'Ongoing', ?, 'Approved', NOW(), NOW())");
        $stmt->bind_param("ssssssssssisiiii", $code, $title, $tupadCategory, $catData['description'], $catData['eligibility'], $catData['requirements'], $start_date, $end_date, $venue, $catData['image_path'], $slots, $batchEligibleSex, $batchMinimumAge, $batchMaximumAge, $batchOnePerHousehold, $admin_id);
        if ($stmt->execute()) { $_SESSION["flash"] = "Batch $code created successfully."; }
        release_batch_code_lock($conn, $batchYear);
        header("Location: admin_program.php?program=".urlencode($title)); exit();
    }

    if ($action === "edit_batch") {
        $pid = (int)$_POST["program_id"];
        $start_date = $_POST["start_date"];
        $end_date = $_POST["end_date"];
        $venue = trim($_POST["venue"]);
        $slots = (int)$_POST["slots"];

        if ($pid <= 0 || $slots <= 0) {
            $_SESSION["flash"] = "Enter a valid batch and at least one slot.";
            $_SESSION["flash_type"] = "error";
            $redirect = $active_program ? "?program=".urlencode($active_program) : "";
            header("Location: admin_program.php" . $redirect); exit();
        }
        if (!valid_batch_date_range($start_date, $end_date)) {
            $_SESSION["flash"] = "The end date must be the same as or later than the start date.";
            $_SESSION["flash_type"] = "error";
            $redirect = $active_program ? "?program=".urlencode($active_program) : "";
            header("Location: admin_program.php" . $redirect); exit();
        }

        $stmt = $conn->prepare("UPDATE programs SET start_date=?, end_date=?, venue=?, slots=?, updated_at=NOW() WHERE program_id=?");
        $stmt->bind_param("sssii", $start_date, $end_date, $venue, $slots, $pid);
        if ($stmt->execute()) { $_SESSION["flash"] = "Batch updated successfully."; }
        $redirect = $active_program ? "?program=".urlencode($active_program) : "";
        header("Location: admin_program.php" . $redirect); exit();
    }
}

// ====== FILTERS ======
$tabFilter = trim($_GET["tab"] ?? "All");
$allowedTabs = ['All', 'Pending', 'Ongoing', 'Completed'];
if (!in_array($tabFilter, $allowedTabs, true)) $tabFilter = 'All';
$search = trim($_GET["search"] ?? "");
$tupadCategoryFilter = trim($_GET["tupad_category"] ?? "All");
if ($tupadCategoryFilter !== 'All' && !in_array($tupadCategoryFilter, $activeTupadCategoryOptions, true)) {
    $tupadCategoryFilter = 'All';
}
$sort = trim($_GET["sort"] ?? "newest");
$page = max(1, (int)($_GET["p"] ?? 1));
$limit = 5; 
$offset = ($page - 1) * $limit;
$whereParts = ["1=1"];

if ($tabFilter === "Approved") $whereParts[] = "approval_status = 'Approved'";
if ($tabFilter === "Pending") $whereParts[] = "approval_status = 'Pending'";
if ($tabFilter === "Ongoing") $whereParts[] = "status IN ('Ongoing', 'Active')";
if ($tabFilter === "Completed") $whereParts[] = "status = 'Completed'";
if ($search !== "") {
    $s = $conn->real_escape_string($search);
    $whereParts[] = "(program_code LIKE '%$s%' OR program_name LIKE '%$s%')";
}
$activeCategoryRules = ['eligible_sex' => 'Any', 'minimum_age' => 18, 'maximum_age' => '', 'one_per_household' => 0];
if ($active_program) {
    $ruleStmt = $conn->prepare("SELECT eligible_sex, minimum_age, maximum_age, one_per_household FROM program_categories WHERE program_name = ? LIMIT 1");
    $ruleStmt->bind_param('s', $active_program); $ruleStmt->execute();
    $activeCategoryRules = $ruleStmt->get_result()->fetch_assoc() ?: $activeCategoryRules; $ruleStmt->close();
}
if ($active_program && stripos($active_program, 'TUPAD') !== false && $tupadCategoryFilter !== 'All') {
    $categorySafe = $conn->real_escape_string($tupadCategoryFilter);
    $whereParts[] = "COALESCE(NULLIF(TRIM(tupad_category), ''), 'Regular TUPAD') = '$categorySafe'";
}
$whereStr = implode(" AND ", $whereParts);

// STATS
if ($active_program) {
    $statRes = $conn->query("SELECT approval_status, status FROM programs WHERE program_name = '".$conn->real_escape_string($active_program)."'");
    $stats = ['total' => 0, 'approved' => 0, 'pending' => 0, 'ongoing' => 0];
    while($r = $statRes->fetch_assoc()) {
        $stats['total']++;
        if($r['approval_status'] === 'Approved') $stats['approved']++;
        if($r['approval_status'] === 'Pending') $stats['pending']++;
        if(in_array($r['status'], ['Ongoing','Active'])) $stats['ongoing']++;
    }
    $card1 = ["label" => "Total Batches", "val" => $stats['total'], "note" => "Batches in this program"];
    $card2 = ["label" => "Approved", "val" => $stats['approved'], "note" => "Approved batches"];
    $card3 = ["label" => "Pending Review", "val" => $stats['pending'], "note" => "Waiting for your review"];
    $card4 = ["label" => "Ongoing Batches", "val" => $stats['ongoing'], "note" => "Batches currently in progress"];
} else {
    $card1 = ["label" => "Total Programs", "val" => $conn->query("SELECT COUNT(*) as c FROM program_categories")->fetch_assoc()['c'] ?? 0, "note" => "Program categories"];
    $card2 = ["label" => "Total Batches", "val" => $conn->query("SELECT COUNT(*) as c FROM programs")->fetch_assoc()['c'] ?? 0, "note" => "Batches in all programs"];
    $card3 = ["label" => "Pending Review", "val" => $conn->query("SELECT COUNT(*) as c FROM programs WHERE approval_status='Pending'")->fetch_assoc()['c'] ?? 0, "note" => "Waiting for admin review"];
    $card4 = ["label" => "Ongoing Batches", "val" => $conn->query("SELECT COUNT(*) as c FROM programs WHERE status IN ('Ongoing','Active')")->fetch_assoc()['c'] ?? 0, "note" => "Programs currently in progress"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/pesologo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BENEPESO | Admin Programs</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <link rel="stylesheet" href="admin_program.css?v=20260905-card-responsive">
    <link rel="stylesheet" href="shared_sidebar.css">
<link rel="stylesheet" href="program_filter_polish.css?v=8">
    <script src="program_filter_polish.js?v=2" defer></script>
<link rel="stylesheet" href="frontend_polish.css?v=20260921">
<link rel="stylesheet" href="admin_responsive.css?v=24">
<link rel="stylesheet" href="system_search_polish.css?v=1">
<link rel="stylesheet" href="system_mobile.css?v=11">
<link rel="stylesheet" href="system_readability.css?v=1">
<script src="frontend_polish.js?v=20260925" defer></script>
</head>
<body class="admin-mobile-page admin-program-page">
<div class="page-wrap">
    
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="side-area" id="sideArea">
        <div class="side-top">
            <div class="side-brand">
                <img src="img/pesologo.png" alt="PESO Logo" class="side-logo">
                <div>
                    <div class="side-title">BENEPESO</div>
                    <div class="side-sub">Admin Panel</div>
                </div>
            </div>
            <button class="side-close" id="sideClose" type="button" aria-label="Close menu">
                <i class="ph ph-x"></i>
            </button>
        </div>

        <div class="side-user">
            <div class="user-pic-wrap">
                <img src="<?php echo e($pic_path); ?>" alt="Admin" class="user-img-side" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin_name); ?>&background=1f7a54&color=fff';">
            </div>
            <div>
                <div class="user-name"><?php echo e($admin_name); ?></div>
                <div class="user-role"><?php echo e($admin_position); ?></div>
            </div>
        </div>

        <nav class="nav-area">
            <a href="admin_dashboard.php" class="<?php echo navClass('admin_dashboard.php'); ?>"><i class="ph ph-squares-four"></i> Dashboard</a>
            <a href="admin_program.php" class="<?php echo navClass('admin_program.php'); ?>"><i class="ph ph-briefcase"></i> Programs</a>
            <a href="admin_beneficiaries.php" class="<?php echo navClass('admin_beneficiaries.php'); ?>"><i class="ph ph-users"></i> Beneficiaries</a>
            <a href="admin_accounts.php" class="<?php echo navClass('admin_accounts.php'); ?>"><i class="ph ph-user-circle-gear"></i> Manage Accounts</a>
            <a href="admin_activity_log.php" class="<?php echo navClass('admin_activity_log.php'); ?>"><i class="ph ph-clock-counter-clockwise"></i> System Logs</a>
            <form method="POST" action="logout.php" class="sidebar-logout-form"><?php echo auth_csrf_input(); ?><input type="hidden" name="role" value="admin"><button type="submit" class="nav-item logout-item"><i class="ph ph-sign-out"></i> Logout</button></form>
        </nav>
    </aside>
    <main class="main-area">
        <header class="top-area animate-fade-in">
            <div class="top-left">
                <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open menu">
                    <span></span><span></span><span></span>
                </button>
                
                <div style="display:flex; flex-direction:column; gap:4px;">
                    <?php if($active_program): ?>
                        <nav class="breadcrumb">
                            <a href="admin_program.php">
                                <i class="ph ph-folder-open" style="font-size: 1rem; margin-right: 6px;"></i> Directories
                            </a>
                            <span class="separator">/</span>
                            <span class="current"><?php echo e($active_program); ?></span>
                        </nav>
                        <div class="top-big"><?php echo e($active_program); ?> Batches</div>
                        <div class="top-sub">Manage scheduling and logistics for this program.</div>
                    <?php else: ?>
                        <div class="eyebrow">Program Management</div>
                        <div class="top-big">Admin Programs</div>
                        <div class="top-sub">Manage the official TUPAD, SPES, and MSME program directories.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="top-actions">
                <?php if($active_program): ?>
                    <button type="button" class="btn-main" onclick="document.getElementById('addBatchModal').classList.add('show');">
                        <i class="ph ph-plus-circle" style="font-size: 1.2rem; margin-right: 6px;"></i> Add New Batch
                    </button>
                <?php else: ?>
                    <span class="program-scope-badge"><i class="ph ph-seal-check" aria-hidden="true"></i> 3 official programs</span>
                <?php endif; ?>
                <div class="top-chip">
                    <img src="<?php echo e($pic_path); ?>" alt="" class="chip-img" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin_name); ?>&background=1f7a54&color=fff';">
                    <?php echo e($admin_name); ?>
                </div>
            </div>
        </header>

        <section class="stats-grid animate-fade-in" style="animation-delay: 0.1s;">
            <?php foreach([$card1, $card2, $card3, $card4] as $c): ?>
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-label"><?php echo $c['label']; ?></div>
                    <div class="stat-icon" <?php if($c['label']==='Approved') echo 'style="color:#1a6d41; background:#e6f6ec;"'; elseif($c['label']==='Pending Review') echo 'style="color:#b06900; background:#fff4d4;"'; elseif($c['label']==='Ongoing Batches') echo 'style="color:#2a5a8a; background:#e8eff4;"'; ?>>
                        <?php 
                            if($c['label']==='Total Batches' || $c['label']==='Total Programs') echo '<i class="ph-fill ph-briefcase"></i>'; 
                            elseif($c['label']==='Approved') echo '<i class="ph-fill ph-check-circle"></i>'; 
                            elseif($c['label']==='Pending Review') echo '<i class="ph-fill ph-clock-countdown"></i>'; 
                            else echo '<i class="ph-fill ph-play-circle"></i>'; 
                        ?>
                    </div>
                </div>
                <div class="stat-value"><?php echo $c['val']; ?></div>
                <div class="stat-note">
                    <?php if($c['label']==='Pending Review' && $c['val'] > 0): ?>
                        <span style="color:#b06900; font-weight:700;"><i class="ph-bold ph-clock"></i> For review</span>
                    <?php else: ?>
                        <?php echo $c['note']; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </section>

        <section class="panel-card animate-fade-in" style="animation-delay: 0.2s;">
            <div class="panel-head program-directory-head">
                <div>
                    <div class="panel-title"><?php echo $active_program ? e($active_program) . ' Directory' : 'Program Directories'; ?></div>
                </div>
                <?php if ($active_program): ?><a class="directory-beneficiary-link" href="admin_beneficiaries.php?program_name=<?php echo urlencode($active_program); ?>"><i class="ph ph-users-three"></i> Find beneficiaries</a><?php endif; ?>
            </div>

            <div class="custom-tabs">
                <a href="?<?php echo build_query(['tab'=>'All', 'p'=>1]); ?>" class="tab-item <?php echo $tabFilter==='All'?'active':''; ?>">All</a>
                <a href="?<?php echo build_query(['tab'=>'Pending', 'p'=>1]); ?>" class="tab-item <?php echo $tabFilter==='Pending'?'active':''; ?>">Pending Review</a>
                <a href="?<?php echo build_query(['tab'=>'Ongoing', 'p'=>1]); ?>" class="tab-item <?php echo $tabFilter==='Ongoing'?'active':''; ?>">Ongoing</a>
                <a href="?<?php echo build_query(['tab'=>'Completed', 'p'=>1]); ?>" class="tab-item <?php echo $tabFilter==='Completed'?'active':''; ?>">Completed</a>
            </div>

            <form class="filter-form system-search" method="GET">
                <input type="hidden" name="tab" value="<?php echo e($tabFilter); ?>">
                <?php if($active_program): ?><input type="hidden" name="program" value="<?php echo e($active_program); ?>"><?php endif; ?>
                
                <i class="ph ph-magnifying-glass search-icon"></i>
                <input class="search-input" id="liveSearchInput" type="text" name="search" placeholder="<?php echo $active_program ? 'Search batch code or venue...' : 'Search program directories...'; ?>" value="<?php echo e($search); ?>">
                <?php if ($active_program && stripos($active_program, 'TUPAD') !== false): ?>
                <input type="hidden" name="tupad_category" value="<?php echo e($tupadCategoryFilter); ?>" data-category-input>
                <div class="program-category-menu" data-category-menu>
                    <button type="button" class="program-category-trigger" aria-haspopup="listbox" aria-expanded="false"><i class="ph <?php echo $tupadCategoryFilter === 'All' ? 'ph-squares-four' : tupad_category_icon($tupadCategoryFilter); ?>"></i><span><?php echo e($tupadCategoryFilter === 'All' ? 'All TUPAD Categories' : $tupadCategoryFilter); ?></span><i class="ph ph-caret-down program-category-caret"></i></button>
                    <div class="program-category-options" role="listbox" aria-label="Filter by TUPAD category" hidden>
                        <button type="button" role="option" data-category-value="All" aria-selected="<?php echo $tupadCategoryFilter === 'All' ? 'true' : 'false'; ?>"><span><i class="ph ph-squares-four"></i>All TUPAD Categories</span><?php if ($tupadCategoryFilter === 'All'): ?><i class="ph-bold ph-check"></i><?php endif; ?></button>
                        <?php foreach ($activeTupadCategoryOptions as $category): ?><button type="button" role="option" data-category-value="<?php echo e($category); ?>" aria-selected="<?php echo $tupadCategoryFilter === $category ? 'true' : 'false'; ?>"><span><i class="ph <?php echo tupad_category_icon($category); ?>"></i><?php echo e($category); ?></span><?php if ($tupadCategoryFilter === $category): ?><i class="ph-bold ph-check"></i><?php endif; ?></button><?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <input type="hidden" name="sort" value="<?php echo e($sort); ?>" data-sort-input>
                <div class="program-sort-menu" data-sort-menu>
                    <button type="button" class="program-sort-trigger" aria-haspopup="listbox" aria-expanded="false">
                        <i class="ph ph-sort-ascending" aria-hidden="true"></i>
                        <span data-sort-label><?php echo $sort === 'oldest' ? 'Oldest First' : 'Newest First'; ?></span>
                        <i class="ph ph-caret-down program-sort-caret" aria-hidden="true"></i>
                    </button>
                    <div class="program-sort-options" role="listbox" aria-label="Sort programs" hidden>
                        <button type="button" role="option" data-sort-value="newest" aria-selected="<?php echo $sort === 'newest' ? 'true' : 'false'; ?>">
                            <span><i class="ph ph-sort-descending"></i> Newest First</span><?php if ($sort === 'newest'): ?><i class="ph-bold ph-check"></i><?php endif; ?>
                        </button>
                        <button type="button" role="option" data-sort-value="oldest" aria-selected="<?php echo $sort === 'oldest' ? 'true' : 'false'; ?>">
                            <span><i class="ph ph-sort-ascending"></i> Oldest First</span><?php if ($sort === 'oldest'): ?><i class="ph-bold ph-check"></i><?php endif; ?>
                        </button>
                    </div>
                </div>
            </form>

            <?php if (!$active_program): 
                $sql = "SELECT c.*, 
                        (SELECT COUNT(*) FROM programs p WHERE p.program_name = c.program_name AND $whereStr) as batch_count,
                        (SELECT SUM(IF(approval_status='Pending',1,0)) FROM programs p WHERE p.program_name = c.program_name) as pending_count,
                        (SELECT COUNT(*) FROM beneficiaries b JOIN programs bp ON bp.program_id = b.program_id WHERE bp.program_name = c.program_name AND b.approval_status = 'Pending') as pending_application_count
                        FROM program_categories c";
                $res = $conn->query($sql);
            ?>
                <div class="program-grid">
                    <?php if($res): while ($dir = $res->fetch_assoc()): if ($tabFilter !== 'All' && $dir['batch_count'] == 0) continue; ?>
                    <article class="program-card-shell<?php echo (int)$dir['pending_application_count'] > 0 ? ' has-pending-applications' : ''; ?>">
                        <div class="program-image-wrap">
                            <div class="program-image-bg" style="background-image: url('<?php echo $dir['image_path'] ?: 'img/pesobgs.jpg'; ?>');"></div>
                            <div class="program-image-overlay"></div>
                            <span class="program-icon-badge" title="<?php echo e($dir['program_name']); ?> program"><i class="ph <?php echo program_directory_icon($dir['program_name']); ?>" aria-hidden="true"></i></span>
                            
                            <?php if($dir['pending_count'] > 0): ?>
                                <div class="pill pending" style="position: absolute; top: 16px; right: 16px; z-index: 2; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
                                    <?php echo $dir['pending_count']; ?> Pending Review
                                </div>
                            <?php endif; ?>

                            <h3 class="program-card-title"><?php echo e($dir['program_name']); ?></h3>
                        </div>
                        <div class="program-body">
                            <div class="program-meta-clean">
                                <div class="meta-item">
                                    <i class="ph ph-stack"></i>
                                    <span><strong><?php echo $dir['batch_count']; ?></strong> Total Batches</span>
                                </div>
                                <?php if($dir['pending_count'] > 0): ?>
                                <div class="meta-item text-warning">
                                    <i class="ph-fill ph-warning-circle"></i>
                                    <span><strong><?php echo $dir['pending_count']; ?></strong> Awaiting Review</span>
                                </div>
                                <?php else: ?>
                                <div class="meta-item" style="color: var(--muted); opacity: 0.7;">
                                    <i class="ph-fill ph-check-circle" style="color: var(--green);"></i>
                                    <span>All batches updated</span>
                                </div>
                                <?php endif; ?>
                                <?php if((int)$dir['pending_application_count'] > 0): ?>
                                <div class="meta-item directory-application-queue">
                                    <i class="ph-fill ph-clipboard-text"></i>
                                    <span><strong><?php echo (int)$dir['pending_application_count']; ?></strong> Applications to Review</span>
                                </div>
                                <?php else: ?>
                                <div class="meta-item directory-application-clear">
                                    <i class="ph-fill ph-users-three"></i>
                                    <span>Application queues clear</span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div style="display:flex; gap:8px; width:100%; margin-top: auto;">
                                <a href="?program=<?php echo urlencode($dir['program_name']); ?>" class="btn-main" style="flex:1;">
                                    <i class="ph ph-folder-open" style="font-size: 1.1rem; margin-right: 6px;"></i> Directory
                                </a>
                                <button class="btn-light open-edit-category" data-id="<?php echo $dir['category_id']; ?>" data-name="<?php echo e($dir['program_name']); ?>" data-desc="<?php echo e($dir['description']); ?>" data-elig="<?php echo e($dir['eligibility']); ?>" data-req="<?php echo e($dir['requirements']); ?>" data-img="<?php echo e($dir['image_path']); ?>" data-sex="<?php echo e($dir['eligible_sex'] ?? 'Any'); ?>" data-min-age="<?php echo (int)($dir['minimum_age'] ?? 18); ?>" data-max-age="<?php echo e($dir['maximum_age'] ?? ''); ?>" data-one-household="<?php echo !empty($dir['one_per_household']) ? '1' : '0'; ?>">
                                    <i class="ph ph-pencil-simple" style="font-size: 1.1rem;"></i> Edit
                                </button>
                            </div>
                        </div>
                    </article>
                    <?php endwhile; endif; ?>
                </div>
            <?php else: 
                $safe_prog = $conn->real_escape_string($active_program);
                $batchWhereParts = $whereParts; 
                $batchWhereParts[] = "program_name = '$safe_prog'"; 
                $batchWhereStr = implode(" AND ", $batchWhereParts);

                $countSql = "SELECT COUNT(*) as total FROM programs WHERE $batchWhereStr";
                $cRes = $conn->query($countSql);
                $totalBatches = $cRes ? $cRes->fetch_assoc()['total'] : 0;
                $totalPages = max(1, ceil($totalBatches / $limit));

                $orderByClause = ($sort === 'oldest') ? "created_at ASC" : "created_at DESC";

                $sql = "SELECT p.*, 
                        (SELECT COUNT(*) FROM beneficiaries b WHERE b.program_id = p.program_id AND b.approval_status = 'Approved') as beneficiary_count,
                        (SELECT COUNT(*) FROM beneficiaries b WHERE b.program_id = p.program_id AND b.approval_status = 'Pending') as pending_beneficiary_count,
                        (SELECT NULLIF(TRIM(al.actor_name), '')
                           FROM activity_logs al
                          WHERE al.actor_role = 'PESO Staff'
                            AND al.module_name = 'Programs'
                            AND al.action_type = 'CREATE'
                            AND al.staff_id = p.created_by
                            AND (
                                al.description LIKE CONCAT('%Code: ', p.program_code, '%')
                                OR (
                                    al.target_name = p.program_name
                                    AND ABS(TIMESTAMPDIFF(SECOND, al.created_at, p.created_at)) <= 5
                                )
                            )
                          ORDER BY al.created_at ASC
                          LIMIT 1) as staff_creator
                        FROM programs p 
                        WHERE $batchWhereStr 
                        ORDER BY $orderByClause LIMIT $limit OFFSET $offset";
                $res = $conn->query($sql);
            ?>
                <div class="table-wrap">
                    <table class="data-table batch-table">
                        <thead>
                            <tr>
                                <th><i class="ph ph-identification-card"></i> Batch Details</th>
                                <th><i class="ph ph-users-three"></i> Capacity Details</th>
                                <th><i class="ph ph-calendar-blank"></i> Schedule & Details</th>
                                <th class="col-status"><i class="ph ph-activity"></i> Status</th>
                                <th class="col-action"><i class="ph ph-cursor-click"></i> Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($res && $res->num_rows > 0): while($b = $res->fetch_assoc()): 
                                
                                $pct = $b['slots'] > 0 ? min(100, round(($b['beneficiary_count'] / $b['slots']) * 100)) : 0;
                                $isPending = $b['approval_status'] === 'Pending';
                                
                                // SINGLE STATUS BADGE LOGIC
                                if ($isPending) {
                                    $display_status = 'Pending Approval';
                                    $status_class = 'pill warning';
                                    $dot_class = 'pending';
                                } elseif ((int)$b['slots'] > 0 && (int)$b['beneficiary_count'] >= (int)$b['slots'] && $b['status'] !== 'Completed') {
                                    $display_status = 'Full';
                                    $status_class = 'pill neutral';
                                    $dot_class = 'completed';
                                } else {
                                    $display_status = trim((string)($b['status'] ?? ''));
                                    if ($display_status === '') {
                                        $display_status = 'Upcoming';
                                    }
                                    $status_str = strtolower($display_status);
                                    if (in_array($status_str, ['ongoing', 'active'])) { 
                                        $status_class = 'pill success'; 
                                        $dot_class = 'ongoing'; 
                                    } elseif (in_array($status_str, ['completed', 'closed'])) { 
                                        $status_class = 'pill neutral'; 
                                        $dot_class = 'completed'; 
                                    } else { 
                                        $status_class = 'pill warning'; 
                                        $dot_class = 'upcoming'; 
                                    }
                                }
                                
                                $creatorName = trim($b['staff_creator'] ?? '');
                                if (empty($creatorName)) { $creatorName = 'Administrator'; }
                            ?>
                            <tr class="clickable-row" onclick="openRowDetails(event, this)" 
                                data-title="<?php echo e($b['program_name']); ?>" 
                                data-code="<?php echo e($b['program_code']); ?>" 
                                data-start="<?php echo date('M j, Y', strtotime($b['start_date'])); ?>" 
                                data-end="<?php echo date('M j, Y', strtotime($b['end_date'])); ?>" 
                                data-venue="<?php echo e($b['venue']); ?>" 
                                data-slots="<?php echo e($b['slots']); ?>"
                                data-eligibility="<?php echo e($b['eligibility'] ?? ''); ?>"
                                data-requirements="<?php echo e($b['requirements'] ?? ''); ?>"
                                title="Click to view full details">
                                
                                <td>
                                    <?php if(!$isPending): ?>
                                        <a href="admin_beneficiaries.php?program_name=<?php echo urlencode($b['program_name']); ?>&program_id=<?php echo (int)$b['program_id']; ?>" class="batch-title-link" title="Open Applicants">
                                            <div class="batch-title-td" style="color: var(--green); display:flex; align-items:center; gap:6px;">
                                                <i class="ph <?php echo program_directory_icon($b['program_name']); ?>" aria-hidden="true"></i><?php echo e($b['program_name']); ?> <i class="ph-bold ph-link" style="font-size: 14px;"></i>
                                            </div>
                                            <div class="batch-code-td" style="color: var(--green-dark);">Batch Code: <?php echo e($b['program_code']); ?></div>
                                            <?php if (stripos($b['program_name'], 'TUPAD') !== false): ?><div class="batch-code-td" style="color:var(--green); margin-top:3px;"><i class="ph ph-tag"></i> <?php echo e($b['tupad_category'] ?: 'Regular TUPAD'); ?></div><?php endif; ?>
                                        </a>
                                    <?php else: ?>
                                        <div style="cursor: not-allowed; opacity: 0.7;" title="Pending Approval">
                                            <div class="batch-title-td" style="color: var(--muted); display:flex; align-items:center; gap:6px;">
                                                <i class="ph <?php echo program_directory_icon($b['program_name']); ?>" aria-hidden="true"></i><?php echo e($b['program_name']); ?> <i class="ph-bold ph-lock-key" style="font-size: 14px;"></i>
                                            </div>
                                            <div class="batch-code-td">Batch Code: <?php echo e($b['program_code']); ?></div>
                                            <?php if (stripos($b['program_name'], 'TUPAD') !== false): ?><div class="batch-code-td" style="margin-top:3px;"><i class="ph ph-tag"></i> <?php echo e($b['tupad_category'] ?: 'Regular TUPAD'); ?></div><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size: 13px; font-weight: 600; margin-bottom: 6px; white-space: nowrap;">
                                            <span style="color:var(--green-dark); font-weight:800; font-size:14px;"><?php echo (int)$b['beneficiary_count']; ?></span> / <?php echo e($b['slots']); ?> Approved
                                    </div>
                                    <div class="slim-progress">
                                        <div class="slim-fill <?php echo $pct >= 100 ? 'full' : ($pct >= 80 ? 'warning' : 'safe'); ?>" style="width: <?php echo $pct; ?>%;"></div>
                                    </div>
                                    <?php if ((int)$b['pending_beneficiary_count'] > 0): ?>
                                        <a class="application-queue-link" href="admin_beneficiaries.php?program_name=<?php echo urlencode($b['program_name']); ?>&amp;program_id=<?php echo (int)$b['program_id']; ?>&amp;approval=Pending" title="Review pending applications for this batch"><i class="ph ph-hourglass-medium" aria-hidden="true"></i><?php echo (int)$b['pending_beneficiary_count']; ?> awaiting review</a>
                                    <?php else: ?>
                                        <span class="application-queue-clear"><i class="ph ph-check-circle" aria-hidden="true"></i>No pending applications</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:13px; font-weight:600; display:flex; align-items:center; gap:6px; margin-bottom:4px; white-space: nowrap;">
                                        <i class="ph ph-calendar-blank text-muted"></i> <?php echo date('M j, Y', strtotime($b['start_date'])); ?> - <?php echo date('M j, Y', strtotime($b['end_date'])); ?>
                                    </div>
                                    <div style="font-size: 11.5px; color: var(--muted); display:flex; align-items:center; gap:6px; white-space: nowrap;">
                                        <i class="ph ph-user-circle"></i> Created by <?php echo e($creatorName); ?>
                                    </div>
                                </td>
                                <td class="col-status">
                                    <div style="display:flex; justify-content:center; align-items:center;">
                                        <span class="<?php echo $status_class; ?>" style="background: #f4f8f5; border: 1px solid var(--line); color: var(--text);">
                                            <span class="pulse-dot <?php echo $dot_class; ?>"></span> <?php echo e($display_status); ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="col-action" style="white-space: nowrap;">
                                    
                                    <div class="action-flex-wrapper">
                                        <?php if($isPending): ?>
                                            <form method="POST" style="margin:0; padding:0; display:flex;">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="program_id" value="<?php echo $b['program_id']; ?>">
                                                <button type="submit" class="btn-action-view" style="color: var(--green); border-color: rgba(31,122,84,0.3);">
                                                    <i class="ph-bold ph-check"></i> Approve
                                                </button>
                                            </form>
                                            <div class="dropdown-wrapper">
                                                <button class="btn-icon" onclick="toggleDropdown(this)" type="button" title="More Options">
                                                    <i class="ph-bold ph-dots-three-vertical"></i>
                                                </button>
                                                <div class="dropdown-menu">
                                                    <form method="POST" style="margin:0;">
                                                        <input type="hidden" name="action" value="reject">
                                                        <input type="hidden" name="program_id" value="<?php echo $b['program_id']; ?>">
                                                        <button type="submit" class="dropdown-item" style="color: #dc2626;">
                                                            <i class="ph-bold ph-x"></i> Reject Batch
                                                        </button>
                                                    </form>
                                                    <button type="button" class="dropdown-item open-edit-trigger" data-id="<?php echo $b['program_id']; ?>" data-code="<?php echo e($b['program_code']); ?>" data-start="<?php echo e($b['start_date']); ?>" data-end="<?php echo e($b['end_date']); ?>" data-venue="<?php echo e($b['venue']); ?>" data-slots="<?php echo e($b['slots']); ?>">
                                                        <i class="ph-bold ph-pencil-simple"></i> Edit Batch
                                                    </button>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <a href="admin_beneficiaries.php?program_name=<?php echo urlencode($b['program_name']); ?>&amp;program_id=<?php echo (int)$b['program_id']; ?><?php echo (int)$b['pending_beneficiary_count'] > 0 ? '&amp;approval=Pending' : ''; ?>" class="btn-action-view">
                                                <i class="ph-bold <?php echo (int)$b['pending_beneficiary_count'] > 0 ? 'ph-clipboard-text' : 'ph-users'; ?>"></i> <?php echo (int)$b['pending_beneficiary_count'] > 0 ? 'Review ' . (int)$b['pending_beneficiary_count'] : 'Applicants'; ?>
                                            </a>
                                            <div class="dropdown-wrapper">
                                                <button class="btn-icon" onclick="toggleDropdown(this)" type="button" title="More Options">
                                                    <i class="ph-bold ph-dots-three-vertical"></i>
                                                </button>
                                                <div class="dropdown-menu">
                                                    <button type="button" class="dropdown-item open-edit-trigger" data-id="<?php echo $b['program_id']; ?>" data-code="<?php echo e($b['program_code']); ?>" data-start="<?php echo e($b['start_date']); ?>" data-end="<?php echo e($b['end_date']); ?>" data-venue="<?php echo e($b['venue']); ?>" data-slots="<?php echo e($b['slots']); ?>">
                                                        <i class="ph-bold ph-pencil-simple"></i> Edit Batch
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state" style="border:none; box-shadow:none; padding:40px;">
                                            <i class="ph ph-folder-open empty-icon" style="font-size: 48px;"></i>
                                            <h4>No batches found</h4>
                                            <p>Try adjusting your search or filters.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if($totalPages > 1): ?>
                <div class="pagination-wrapper">
                    <span class="page-info">Showing Page <strong><?php echo $page; ?></strong> of <strong><?php echo $totalPages; ?></strong></span>
                    <div class="pagination-controls">
                        <a href="?<?php echo build_query(['p'=>max(1, $page-1)]); ?>" class="page-btn <?php echo $page<=1?'disabled':''; ?>"><i class="ph ph-caret-left"></i> Prev</a>
                        <a href="?<?php echo build_query(['p'=>min($totalPages, $page+1)]); ?>" class="page-btn <?php echo $page>=$totalPages?'disabled':''; ?>">Next <i class="ph ph-caret-right"></i></a>
                    </div>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        </section>
    </main>
</div>

<div class="modal" id="editCategoryModal">
    <div class="modal-backdrop" data-close-modal="editCategoryModal"></div>
    <div class="modal-dialog landscape-modal">
        <div class="modal-head">
            <div class="program-modal-heading"><span class="program-modal-icon"><i class="ph ph-pencil-simple" aria-hidden="true"></i></span><div><div class="modal-title">Edit Program</div><div class="modal-sub">Update program category details.</div></div></div>
            <button type="button" class="modal-close" data-close-modal="editCategoryModal"><i class="ph ph-x"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="modal-form">
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="category_id" id="edit_cat_id">
            <input type="hidden" name="old_program_name" id="edit_cat_old_name">
            <input type="hidden" name="current_image" id="edit_cat_current_img">
            
            <div class="landscape-form-grid">
                <div class="form-col">
                    <div class="form-group"><label>Name *</label><input type="text" name="program_name" id="edit_cat_name" required></div>
                    <div class="form-group"><label>Description *</label><textarea name="description" id="edit_cat_desc" rows="2" required></textarea></div>
                    <div class="form-group"><label>Eligibility *</label><textarea name="eligibility" id="edit_cat_elig" rows="2" required></textarea></div>
                    <div class="form-group"><label>Eligible Sex *</label><select name="eligible_sex" id="edit_cat_sex" required><option value="Any">Any sex</option><option value="Male">Male only</option><option value="Female">Female only</option></select></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div class="form-group"><label>Minimum Age *</label><input type="number" name="minimum_age" id="edit_cat_min_age" min="0" max="120" required></div><div class="form-group"><label>Maximum Age</label><input type="number" name="maximum_age" id="edit_cat_max_age" min="0" max="120" placeholder="No maximum"></div></div>
                    <label style="display:flex;gap:9px;align-items:flex-start;font-size:13px"><input type="checkbox" name="one_per_household" id="edit_cat_one_household" value="1" style="width:auto;margin-top:3px"> Allow only one pending or active beneficiary per household</label>
                    <div class="form-group"><label>Requirements *</label><textarea name="requirements" id="edit_cat_req" rows="2" required></textarea></div>
                </div>
                <div class="form-col">
                    <div class="form-group" style="height: 100%; display: flex; flex-direction: column;">
                        <label>New Cover Image (Optional)</label>
                        <label class="upload-zone" id="uploadZoneEdit">
                            <input type="file" name="image_path" id="imageInputEdit" accept=".jpg,.jpeg,.png,.webp" style="display: none;">
                            
                            <div id="uploadContentEdit" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; width: 100%;">
                                <div class="upload-icon-wrap"><i class="ph ph-image"></i></div>
                                <span class="upload-text-main">UPDATE COVER IMAGE</span>
                                <span class="upload-text-sub">Leave empty to keep current</span>
                            </div>
                            
                            <div id="imagePreviewEdit" style="display: none; width: 100%; height: 100%; position: relative;">
                                <img id="previewImgEdit" src="" alt="Preview" style="width: 100%; height: 100%; object-fit: cover; border-radius: 12px;">
                                <div class="preview-overlay">
                                    <i class="ph ph-arrows-clockwise"></i> Click to change
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-light" data-close-modal="editCategoryModal">Cancel</button>
                <button type="submit" class="btn-main">Update Program</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="addBatchModal">
    <div class="modal-backdrop" data-close-modal="addBatchModal"></div>
    <div class="modal-dialog landscape-modal" style="max-width: 700px;">
        <div class="modal-head">
            <div class="program-modal-heading"><span class="program-modal-icon"><i class="ph ph-calendar-plus" aria-hidden="true"></i></span><div><div class="modal-title">Add Batch</div><div class="modal-sub">Schedule a rollout for <?php echo e($active_program); ?></div></div></div>
            <button type="button" class="modal-close" data-close-modal="addBatchModal"><i class="ph ph-x"></i></button>
        </div>
        <form method="POST" class="modal-form">
            <input type="hidden" name="action" value="add_batch">
            <input type="hidden" name="program_name" value="<?php echo e($active_program); ?>">
            
            <div class="form-grid">
                <div class="form-group span-2"><label>Batch Number</label><input type="text" value="Generated automatically from the program, category, year, and sequence" readonly aria-describedby="batchNumberHelp"><small id="batchNumberHelp">Examples: TUPAD-BRIGADA-<?php echo date('Y'); ?>-001, SPES-<?php echo date('Y'); ?>-001, or MSME-PROFILING-<?php echo date('Y'); ?>-0001.</small></div>
                <?php if ($active_program && stripos($active_program, 'TUPAD') !== false): ?>
                <div class="form-group span-2"><label>TUPAD Category *</label><select name="tupad_category" id="tupadCategorySelect" required onchange="document.getElementById('otherTupadCategory').style.display=this.value==='Other TUPAD Initiative'?'block':'none'; document.getElementById('otherTupadCategory').required=this.value==='Other TUPAD Initiative';"><?php foreach ($tupadCategoryOptions as $category): ?><option value="<?php echo e($category); ?>"><?php echo e($category); ?></option><?php endforeach; ?></select><input type="text" name="other_tupad_category" id="otherTupadCategory" style="display:none;margin-top:8px" maxlength="120" placeholder="Enter the TUPAD category"><small>Select the specific TUPAD initiative for this batch.</small></div>
                <?php endif; ?>
                <div class="form-group"><label>Start Date *</label><input type="date" name="start_date" class="batch-start-date" required></div>
                <div class="form-group"><label>End Date *</label><input type="date" name="end_date" class="batch-end-date" required><small class="date-range-help">Must be the same as or later than the start date.</small></div>
                <div class="form-group"><label>Venue *</label><input type="text" name="venue" value="PESO Vinzons" required></div>
                <div class="form-group"><label>Total Slots *</label><input type="number" name="slots" min="1" placeholder="e.g. 100" required></div>
                <div class="form-group"><label>Eligible Sex *</label><select name="eligible_sex" required><option value="Any" <?php echo ($activeCategoryRules['eligible_sex'] ?? 'Any') === 'Any' ? 'selected' : ''; ?>>Any sex</option><option value="Male" <?php echo ($activeCategoryRules['eligible_sex'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male only</option><option value="Female" <?php echo ($activeCategoryRules['eligible_sex'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female only</option></select></div>
                <div class="form-group"><label>Minimum Age *</label><input type="number" name="minimum_age" min="0" max="120" value="<?php echo (int)($activeCategoryRules['minimum_age'] ?? 18); ?>" required></div>
                <div class="form-group"><label>Maximum Age</label><input type="number" name="maximum_age" min="0" max="120" value="<?php echo e($activeCategoryRules['maximum_age'] ?? ''); ?>" placeholder="No maximum"></div>
                <label class="form-group span-2" style="display:flex;gap:9px;align-items:center"><input type="checkbox" name="one_per_household" value="1" style="width:auto" <?php echo !empty($activeCategoryRules['one_per_household']) ? 'checked' : ''; ?>> One pending or active beneficiary per household</label>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-light" data-close-modal="addBatchModal">Cancel</button>
                <button type="submit" class="btn-main">Create Batch</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="editBatchModal">
    <div class="modal-backdrop" data-close-modal="editBatchModal"></div>
    <div class="modal-dialog landscape-modal" style="max-width: 700px;">
        <div class="modal-head">
            <div class="program-modal-heading"><span class="program-modal-icon"><i class="ph ph-calendar-check" aria-hidden="true"></i></span><div><div class="modal-title">Edit Batch</div><div class="modal-sub">Update batch scheduling details.</div></div></div>
            <button type="button" class="modal-close" data-close-modal="editBatchModal"><i class="ph ph-x"></i></button>
        </div>
        <form method="POST" class="modal-form">
            <input type="hidden" name="action" value="edit_batch">
            <input type="hidden" name="program_id" id="edit_pid">
            
            <div class="form-grid">
                <div class="form-group span-2"><label>Batch Number</label><input type="text" id="edit_code" readonly title="Official batch numbers cannot be changed after creation."></div>
                <div class="form-group"><label>Start Date *</label><input type="date" name="start_date" id="edit_start" class="batch-start-date" required></div>
                <div class="form-group"><label>End Date *</label><input type="date" name="end_date" id="edit_end" class="batch-end-date" required><small class="date-range-help">Must be the same as or later than the start date.</small></div>
                <div class="form-group"><label>Venue *</label><input type="text" name="venue" id="edit_venue" required></div>
                <div class="form-group"><label>Total Slots *</label><input type="number" name="slots" id="edit_slots" min="1" required></div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-light" data-close-modal="editBatchModal">Cancel</button>
                <button type="submit" class="btn-main">Update Batch</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="viewBatchModal">
    <div class="modal-backdrop" data-close-modal="viewBatchModal"></div>
    <div class="modal-dialog" style="max-width: 500px;">
        <div class="modal-head">
            <div class="program-modal-heading"><span class="program-modal-icon"><i class="ph ph-info" aria-hidden="true"></i></span><div><div class="modal-title">Batch Details</div><div class="modal-sub">Complete scheduling and capacity info.</div></div></div>
            <button type="button" class="modal-close" data-close-modal="viewBatchModal"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-form">
            <div class="form-grid" style="gap: 12px; padding-bottom: 24px;">
                <div class="form-group span-2"><label><i class="ph ph-briefcase"></i> Program Name</label><div class="view-data" id="view_title"></div></div>
                <div class="form-group span-2"><label><i class="ph ph-barcode"></i> Batch Code</label><div class="view-data" id="view_code"></div></div>
                <div class="form-group"><label><i class="ph ph-calendar"></i> Start Date</label><div class="view-data" id="view_start"></div></div>
                <div class="form-group"><label><i class="ph ph-calendar-check"></i> End Date</label><div class="view-data" id="view_end"></div></div>
                <div class="form-group"><label><i class="ph ph-map-pin"></i> Venue</label><div class="view-data" id="view_venue"></div></div>
                <div class="form-group"><label><i class="ph ph-users"></i> Total Slots</label><div class="view-data" id="view_slots"></div></div>
            </div>
            <div class="program-bullet-section"><label><i class="ph ph-seal-check"></i> Eligibility</label><ul class="program-detail-list" id="view_eligibility"></ul></div>
            <div class="program-bullet-section"><label><i class="ph ph-files"></i> Requirements</label><ul class="program-detail-list" id="view_requirements"></ul></div>
            <div class="modal-actions">
                <button type="button" class="btn-light" data-close-modal="viewBatchModal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php if ($flash): ?>
<div class="flash <?php echo $flash_type; ?>">
    <i class="ph-fill ph-check-circle" style="color: var(--green); margin-right: 8px; font-size: 1.2rem;"></i> 
    <?php echo e($flash); ?>
</div>
<?php endif; ?>

<script>
const menuToggle = document.getElementById('menuToggle');
const sideArea = document.getElementById('sideArea');
const sideClose = document.getElementById('sideClose');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function toggleMenu() {
    sideArea.classList.toggle('open');
    sidebarOverlay.classList.toggle('show');
}
if(menuToggle) menuToggle.addEventListener('click', toggleMenu);
if(sideClose) sideClose.addEventListener('click', toggleMenu);
if(sidebarOverlay) sidebarOverlay.addEventListener('click', toggleMenu);

function toggleDropdown(btn) {
    let menu = btn.nextElementSibling;
    document.querySelectorAll('.dropdown-menu').forEach(m => { if(m !== menu) m.classList.remove('show'); });
    menu.classList.toggle('show');
}
document.addEventListener('click', (e) => {
    if(!e.target.closest('.dropdown-wrapper')) {
        document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
    }
});

document.querySelectorAll('[data-close-modal]').forEach(b => b.onclick = (e) => {
    e.preventDefault();
    const modal = document.getElementById(b.dataset.closeModal);
    if (modal?.contains(document.activeElement)) document.activeElement.blur();
    modal?.classList.remove('show');
});

const programModals = Array.from(document.querySelectorAll('.modal'));
const syncProgramModalState = () => {
    const openModal = programModals.find(modal => modal.classList.contains('show'));
    programModals.forEach(modal => modal.setAttribute('aria-hidden', modal === openModal ? 'false' : 'true'));
    document.body.style.overflow = openModal ? 'hidden' : '';
    if (openModal) {
        const focusTarget = openModal.querySelector('input:not([type="hidden"]), select, textarea, button');
        window.setTimeout(() => focusTarget?.focus({ preventScroll: true }), 80);
    }
};
programModals.forEach(modal => new MutationObserver(syncProgramModalState).observe(modal, { attributes: true, attributeFilter: ['class'] }));

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const openModal = [...programModals].reverse().find(modal => modal.classList.contains('show'));
    if (openModal) {
        if (openModal.contains(document.activeElement)) document.activeElement.blur();
        openModal.classList.remove('show');
    }
});

document.querySelectorAll('.program-card-shell, .batch-table tbody tr').forEach((element, index) => {
    element.classList.add('program-item-enter');
    element.style.animationDelay = `${Math.min(index * 65, 390)}ms`;
});

if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.querySelectorAll('.stat-value').forEach(element => {
        const target = Number.parseInt(element.textContent.trim(), 10);
        if (Number.isNaN(target)) return;
        const start = performance.now();
        element.textContent = '0';
        const animate = now => {
            const progress = Math.min((now - start) / 700, 1);
            element.textContent = Math.round(target * (1 - Math.pow(1 - progress, 3))).toLocaleString();
            if (progress < 1) requestAnimationFrame(animate);
        };
        requestAnimationFrame(animate);
    });
}

// ==========================================
// ROW CLICKABILITY TO OPEN BATCH DETAILS
// ==========================================
function renderProgramDetailList(targetId, value, fallback) {
    const target = document.getElementById(targetId);
    const items = String(value || '').replace(/\r/g, '').trim()
        .split(/\n+|[•●▪]\s*|;\s*|(?:^|\s)[\-–—]\s+/g)
        .map(item => item.trim().replace(/^[\s\-–—•●▪,.:]+|[,;]+$/g, '').trim())
        .filter(item => item && !/^for\s+students?\s*:?$/i.test(item));
    target.replaceChildren();
    (items.length ? items : [fallback]).forEach(item => {
        const li = document.createElement('li');
        li.textContent = item;
        target.appendChild(li);
    });
}

function openRowDetails(e, row) {
    if(e.target.closest('.col-action') || e.target.closest('a')) return; 
    
    document.getElementById('view_title').innerText = row.dataset.title;
    document.getElementById('view_code').innerText = row.dataset.code;
    document.getElementById('view_start').innerText = row.dataset.start;
    document.getElementById('view_end').innerText = row.dataset.end;
    document.getElementById('view_venue').innerText = row.dataset.venue;
    document.getElementById('view_slots').innerText = row.dataset.slots;
    renderProgramDetailList('view_eligibility', row.dataset.eligibility, 'No eligibility information specified.');
    renderProgramDetailList('view_requirements', row.dataset.requirements, 'No requirements specified.');
    document.getElementById('viewBatchModal').classList.add('show');
}

document.querySelectorAll('.open-edit-category').forEach(btn => {
    btn.onclick = () => {
        document.getElementById('edit_cat_id').value = btn.dataset.id;
        document.getElementById('edit_cat_old_name').value = btn.dataset.name;
        document.getElementById('edit_cat_name').value = btn.dataset.name;
        document.getElementById('edit_cat_desc').value = btn.dataset.desc;
        document.getElementById('edit_cat_elig').value = btn.dataset.elig;
        document.getElementById('edit_cat_sex').value = btn.dataset.sex || 'Any';
        document.getElementById('edit_cat_min_age').value = btn.dataset.minAge || '18';
        document.getElementById('edit_cat_max_age').value = btn.dataset.maxAge || '';
        document.getElementById('edit_cat_one_household').checked = btn.dataset.oneHousehold === '1';
        document.getElementById('edit_cat_req').value = btn.dataset.req;
        document.getElementById('edit_cat_current_img').value = btn.dataset.img;
        
        // Reset image preview on edit open
        const imgPath = btn.dataset.img;
        const uploadContentEdit = document.getElementById('uploadContentEdit');
        const imagePreviewEdit = document.getElementById('imagePreviewEdit');
        const previewImgEdit = document.getElementById('previewImgEdit');
        const uploadZoneEdit = document.getElementById('uploadZoneEdit');
        
        if (imgPath && imgPath.trim() !== '') {
            previewImgEdit.src = imgPath;
            uploadContentEdit.style.display = 'none';
            imagePreviewEdit.style.display = 'block';
            uploadZoneEdit.classList.add('has-image');
        } else {
            previewImgEdit.src = '';
            uploadContentEdit.style.display = 'flex';
            imagePreviewEdit.style.display = 'none';
            uploadZoneEdit.classList.remove('has-image');
        }

        document.getElementById('editCategoryModal').classList.add('show');
    };
});

document.querySelectorAll('.open-edit-trigger').forEach(btn => {
    btn.onclick = (e) => {
        e.preventDefault(); // Prevent accidental form submissions if placed inside form
        document.getElementById('edit_pid').value = btn.dataset.id;
        document.getElementById('edit_code').value = btn.dataset.code;
        document.getElementById('edit_start').value = btn.dataset.start;
        document.getElementById('edit_end').value = btn.dataset.end;
        document.getElementById('edit_venue').value = btn.dataset.venue;
        document.getElementById('edit_slots').value = btn.dataset.slots;
        document.getElementById('editBatchModal').classList.add('show');
    };
});

setTimeout(() => { const f = document.querySelector('.flash'); if(f) { f.style.opacity = '0'; setTimeout(() => f.remove(), 300); } }, 4000);

// ==========================================
// LIVE SEARCH WITH DEBOUNCE
// ==========================================
const searchInput = document.getElementById('liveSearchInput');
if(searchInput) {
    let searchTimeout;
    
    const valLen = searchInput.value.length;
    if (valLen > 0) {
        searchInput.focus();
        searchInput.setSelectionRange(valLen, valLen);
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            this.form.submit();
        }, 600); 
    });
}

// ==========================================
// INTERACTIVE IMAGE UPLOAD LOGIC
// ==========================================
function setupUploadZone(inputId, contentId, previewContainerId, previewImgId, zoneId) {
    const imageInput = document.getElementById(inputId);
    const uploadContent = document.getElementById(contentId);
    const imagePreview = document.getElementById(previewContainerId);
    const previewImg = document.getElementById(previewImgId);
    const uploadZone = document.getElementById(zoneId);

    if(imageInput && uploadZone) {
        imageInput.addEventListener('change', function(e) {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    uploadContent.style.display = 'none';
                    imagePreview.style.display = 'block';
                    uploadZone.classList.add('has-image');
                }
                reader.readAsDataURL(file);
            }
        });

        // Drag and Drop styling
        uploadZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadZone.style.borderColor = 'var(--green)';
            uploadZone.style.background = '#f0fdf4';
        });
        uploadZone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            uploadZone.style.borderColor = '#cbd5e1';
            uploadZone.style.background = '#f8fafc';
        });
        uploadZone.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadZone.style.borderColor = '#cbd5e1';
            uploadZone.style.background = '#f8fafc';
            if (e.dataTransfer.files.length) {
                imageInput.files = e.dataTransfer.files;
                imageInput.dispatchEvent(new Event('change'));
            }
        });
    }
}

// The existing official program artwork can be updated by administrators.
setupUploadZone('imageInputEdit', 'uploadContentEdit', 'imagePreviewEdit', 'previewImgEdit', 'uploadZoneEdit');

document.querySelectorAll('form').forEach(form => {
    const start = form.querySelector('.batch-start-date');
    const end = form.querySelector('.batch-end-date');
    if (!start || !end) return;
    const validateRange = () => {
        end.min = start.value || '';
        end.setCustomValidity(start.value && end.value && end.value < start.value
            ? 'End date must be the same as or later than the start date.' : '');
    };
    start.addEventListener('change', validateRange);
    end.addEventListener('change', validateRange);
    form.addEventListener('submit', validateRange);
    validateRange();
});
</script>
</body>
</html>
