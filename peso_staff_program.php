<?php
require_once __DIR__ . '/auth.php';
require "db.php";
require_once "batch_code_helper.php";
require_once "program_eligibility_helper.php";
require_once "tupad_category_helper.php";
ensure_program_eligibility_schema($conn);
ensure_tupad_category_schema($conn);

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

check_user_role("peso_staff");

$staff_id = (int)$_SESSION["staff_id"];

$staff_name = "PESO Staff";
$staff_position = "Staff";
$staff_pic = "default_avatar.png";

$stmt = $conn->prepare("SELECT first_name, last_name, position, profile_picture FROM peso_staff WHERE staff_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $staff_name = trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));
        $staff_position = $row["position"] ?? $staff_position;
        $staff_pic = !empty($row["profile_picture"]) ? $row["profile_picture"] : "default_avatar.png";
    }
    $stmt->close();
}
if (empty($staff_name)) $staff_name = "PESO Staff";
$pic_path = "uploads/staff_pics/" . $staff_pic;
if (!file_exists($pic_path) || empty($staff_pic)) $pic_path = "img/default_avatar.png";

$flash = $_SESSION["flash"] ?? "";
$flash_type = $_SESSION["flash_type"] ?? "success";
unset($_SESSION["flash"], $_SESSION["flash_type"]);

if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8"); }
}
function build_query(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $k => $v) { if ($v === null || $v === "") unset($query[$k]); }
    return http_build_query($query);
}

$active_program = isset($_GET['program']) ? trim($_GET['program']) : null;
$tupadCategoryOptions = available_tupad_categories($conn);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "add_category") {
        $title = trim($_POST["program_name"] ?? "");
        $desc = trim($_POST["description"] ?? "");
        $elig = trim($_POST["eligibility"] ?? "");
        $req = trim($_POST["requirements"] ?? "");
        [$eligibleSex, $minimumAge, $maximumAge, $onePerHousehold] = clean_eligibility_rules($_POST);
        $new_image_path = null;
        
        if (!empty($_FILES["image_path"]["name"]) && $_FILES["image_path"]["error"] === 0) {
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
        $stmt = $conn->prepare("INSERT INTO program_categories (program_name, description, eligibility, requirements, image_path, eligible_sex, minimum_age, maximum_age, one_per_household) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("ssssssiii", $title, $desc, $elig, $req, $new_image_path, $eligibleSex, $minimumAge, $maximumAge, $onePerHousehold);
        if ($stmt->execute()) { 
            
            $log_desc = "Created a new Master Program Category: " . $title;
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (staff_id, actor_name, actor_role, module_name, action_type, target_name, description, created_at) VALUES (?, ?, 'PESO Staff', 'Programs', 'CREATE', ?, ?, NOW())");
            if ($log_stmt) {
                // FIXED: Changed "issss" to "isss" to match the 4 variables
                $log_stmt->bind_param("isss", $staff_id, $staff_name, $title, $log_desc);
                $log_stmt->execute();
                $log_stmt->close();
            }

            $_SESSION["flash"] = "New Program Category added successfully."; 
        }
        header("Location: peso_staff_program.php"); exit();
    }

    if ($action === "add_batch") {
        $title = trim($_POST["program_name"] ?? ""); 
        $slots = (int)($_POST["slots"] ?? 0);
        $start_date = $_POST["start_date"];
        $end_date = $_POST["end_date"];
        $venue = trim($_POST["venue"] ?? "PESO Vinzons");
        $tupadCategory = submitted_tupad_category($_POST, $title);
        [$batchEligibleSex, $batchMinimumAge, $batchMaximumAge, $batchOnePerHousehold] = clean_eligibility_rules($_POST);

        if (!valid_batch_date_range($start_date, $end_date)) {
            $_SESSION["flash"] = "The end date must be the same as or later than the start date.";
            $_SESSION["flash_type"] = "error";
            header("Location: peso_staff_program.php?program=".urlencode($title)); exit();
        }
        
        $catRes = $conn->query("SELECT description, eligibility, requirements, image_path, eligible_sex, minimum_age, maximum_age, one_per_household FROM program_categories WHERE program_name = '".$conn->real_escape_string($title)."'");
        $catData = $catRes->fetch_assoc();
        
        $batchYear = batch_year_from_date($start_date);
        if (!acquire_batch_code_lock($conn, $batchYear)) {
            $_SESSION["flash"] = "A batch number could not be reserved. Please try again.";
            $_SESSION["flash_type"] = "error";
            header("Location: peso_staff_program.php?program=".urlencode($title)); exit();
        }
        $code = next_batch_code($conn, $start_date, $title, $tupadCategory);
        $stmt = $conn->prepare("INSERT INTO programs (program_code, program_name, tupad_category, description, eligibility, requirements, start_date, end_date, venue, image_path, slots, eligible_sex, minimum_age, maximum_age, one_per_household, status, created_by, approval_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'Upcoming', ?, 'Pending', NOW(), NOW())");
        $stmt->bind_param("ssssssssssisiiii", $code, $title, $tupadCategory, $catData['description'], $catData['eligibility'], $catData['requirements'], $start_date, $end_date, $venue, $catData['image_path'], $slots, $batchEligibleSex, $batchMinimumAge, $batchMaximumAge, $batchOnePerHousehold, $staff_id);
        
        if ($stmt->execute()) { 
            
            $log_desc = "Requested a new batch for " . $title . " (Code: " . $code . ") requiring admin approval.";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (staff_id, actor_name, actor_role, module_name, action_type, target_name, description, created_at) VALUES (?, ?, 'PESO Staff', 'Programs', 'CREATE', ?, ?, NOW())");
            if ($log_stmt) {
                // FIXED: Changed "issss" to "isss" to match the 4 variables
                $log_stmt->bind_param("isss", $staff_id, $staff_name, $title, $log_desc);
                $log_stmt->execute();
                $log_stmt->close();
            }

            $_SESSION["flash"] = "Batch $code submitted for Admin approval."; 
        }
        release_batch_code_lock($conn, $batchYear);
        header("Location: peso_staff_program.php?program=".urlencode($title)); exit();
    }
}

$tabFilter = trim($_GET["tab"] ?? "All");
$search = trim($_GET["search"] ?? "");
$tupadCategoryFilter = trim($_GET["tupad_category"] ?? "All");
$sort = trim($_GET["sort"] ?? "newest");
$page = max(1, (int)($_GET["p"] ?? 1));

$limit = 5; 
$offset = ($page - 1) * $limit;
$whereParts = ["1=1"];

if ($tabFilter === "Pending") $whereParts[] = "approval_status = 'Pending'";
if ($tabFilter === "Approved") $whereParts[] = "approval_status = 'Approved'";
if ($tabFilter === "Ongoing") $whereParts[] = "status IN ('Ongoing', 'Active') AND approval_status = 'Approved'";
if ($tabFilter === "Completed") $whereParts[] = "status = 'Completed' AND approval_status = 'Approved'";
if ($search !== "") {
    $s = $conn->real_escape_string($search);
    $whereParts[] = "(program_code LIKE '%$s%' OR program_name LIKE '%$s%')";
}
$whereStr = implode(" AND ", $whereParts);

$programTemplates = [];
$templateResult = $conn->query("SELECT program_name, description, eligibility, requirements, eligible_sex, minimum_age, maximum_age, one_per_household FROM program_categories ORDER BY program_name");
if ($templateResult) while ($template = $templateResult->fetch_assoc()) $programTemplates[] = $template;
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

if ($active_program) {
    $statRes = $conn->query("SELECT approval_status, status FROM programs WHERE program_name = '".$conn->real_escape_string($active_program)."'");
    $stats = ['total' => 0, 'approved' => 0, 'pending' => 0, 'ongoing' => 0];
    while($r = $statRes->fetch_assoc()) {
        $stats['total']++;
        if($r['approval_status'] === 'Approved') $stats['approved']++;
        if($r['approval_status'] === 'Pending') $stats['pending']++;
        if(in_array($r['status'], ['Ongoing','Active']) && $r['approval_status'] === 'Approved') $stats['ongoing']++;
    }
    $card1 = ["label" => "Total Batches", "val" => $stats['total'], "note" => "All batches for this program"];
    $card2 = ["label" => "Approved", "val" => $stats['approved'], "note" => "Approved by administrator"];
    $card3 = ["label" => "Pending Review", "val" => $stats['pending'], "note" => "Awaiting admin approval"];
    $card4 = ["label" => "Ongoing Batches", "val" => $stats['ongoing'], "note" => "Currently active and running"];
} else {
    $card1 = ["label" => "Total Programs", "val" => $conn->query("SELECT COUNT(*) as c FROM program_categories")->fetch_assoc()['c'] ?? 0, "note" => "All recorded categories"];
    $card2 = ["label" => "Total Batches", "val" => $conn->query("SELECT COUNT(*) as c FROM programs")->fetch_assoc()['c'] ?? 0, "note" => "Across all programs"];
    $card3 = ["label" => "Pending Review", "val" => $conn->query("SELECT COUNT(*) as c FROM programs WHERE approval_status='Pending'")->fetch_assoc()['c'] ?? 0, "note" => "Requires admin attention"];
    $card4 = ["label" => "Ongoing Batches", "val" => $conn->query("SELECT COUNT(*) as c FROM programs WHERE status IN ('Ongoing','Active') AND approval_status='Approved'")->fetch_assoc()['c'] ?? 0, "note" => "Currently running programs"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/pesologo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BENEPESO | PESO Staff Programs</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="peso_staff_program.css">
    <link rel="stylesheet" href="shared_sidebar.css">
    <link rel="stylesheet" href="program_filter_polish.css?v=2">
    <script src="program_filter_polish.js?v=1" defer></script>
    <link rel="stylesheet" href="frontend_polish.css?v=1">
    <script src="frontend_polish.js?v=1" defer></script>
</head>
<body>
<div class="page-wrap">
    
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="side-area" id="sideArea">
        <div class="side-top">
            <div class="side-brand">
                <img src="img/pesologo.png" class="side-logo" alt="Logo">
                <div>
                    <div class="side-title">BENEPESO</div>
                    <div class="side-sub">PESO Staff Panel</div>
                </div>
            </div>
            <button class="side-close" id="sideClose" type="button" aria-label="Close menu">
                <i class="ph ph-x"></i>
            </button>
        </div>
        <div class="side-user">
            <div class="user-pic-wrap">
                <img src="<?php echo e($pic_path); ?>" alt="Staff" class="user-img-side" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($staff_name); ?>&background=2f6b4f&color=fff';">
            </div>
            <div>
                <div class="user-name"><?php echo e($staff_name); ?></div>
                <div class="user-role"><?php echo e($staff_position); ?></div>
            </div>
        </div>
        <nav class="nav-area">
            <a href="peso_staff_dashboard.php" class="nav-item"><i class="ph ph-squares-four"></i> Dashboard</a>
            <a href="peso_staff_program.php" class="nav-item active"><i class="ph ph-briefcase"></i> Program</a>
            <a href="peso_staff_beneficiaries.php" class="nav-item"><i class="ph ph-users"></i> Beneficiaries</a>
            <a href="peso_staff_activity_log.php" class="nav-item"><i class="ph ph-clock-counter-clockwise"></i> Activity Log</a>
            <a href="logout.php?role=peso_staff" class="nav-item logout-item"><i class="ph ph-sign-out"></i> Logout</a>
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
                            <a href="peso_staff_program.php">
                                <i class="ph ph-folder-open" style="font-size: 1rem; margin-right: 6px;"></i> Directories
                            </a>
                            <span class="separator">/</span>
                            <span class="current"><?php echo e($active_program); ?></span>
                        </nav>
                        <div class="top-big"><?php echo e($active_program); ?> Batches</div>
                        <div class="top-sub">Submit scheduling adding for this program.</div>
                    <?php else: ?>
                        <div class="eyebrow">Program Management</div>
                        <div class="top-big">PESO Staff Programs</div>
                        <div class="top-sub">Submit and manage program batches for approval.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="top-actions">
                <?php if($active_program): ?>
                    <button class="btn-main" onclick="document.getElementById('addBatchModal').classList.add('show');">
                        <i class="ph ph-plus-circle" style="font-size: 1.2rem; margin-right: 6px;"></i> Add New Batch
                    </button>
                <?php else: ?>
                    <button class="btn-main" onclick="document.getElementById('addProgModal').classList.add('show');">
                        <i class="ph ph-plus-circle" style="font-size: 1.2rem; margin-right: 6px;"></i> Add Program
                    </button>
                <?php endif; ?>
                <div class="top-chip">
                    <img src="<?php echo e($pic_path); ?>" alt="" class="chip-img" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($staff_name); ?>&background=2f6b4f&color=fff';">
                    <?php echo e($staff_name); ?>
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
                        <span style="color:#b06900; font-weight:700;"><i class="ph-bold ph-warning-circle"></i> Action Required</span>
                    <?php else: ?>
                        <?php echo $c['note']; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </section>

        <section class="panel-card animate-fade-in" style="animation-delay: 0.2s;">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><?php echo $active_program ? e($active_program) . ' Directory' : 'Program Directories'; ?></div>
                </div>
            </div>

            <div class="custom-tabs">
                <a href="?<?php echo build_query(['tab'=>'All', 'p'=>1]); ?>" class="tab-item <?php echo $tabFilter==='All'?'active':''; ?>">All</a>
                <a href="?<?php echo build_query(['tab'=>'Pending', 'p'=>1]); ?>" class="tab-item <?php echo $tabFilter==='Pending'?'active':''; ?>">Pending Review</a>
                <a href="?<?php echo build_query(['tab'=>'Ongoing', 'p'=>1]); ?>" class="tab-item <?php echo $tabFilter==='Ongoing'?'active':''; ?>">Ongoing</a>
                <a href="?<?php echo build_query(['tab'=>'Completed', 'p'=>1]); ?>" class="tab-item <?php echo $tabFilter==='Completed'?'active':''; ?>">Completed</a>
            </div>

            <form class="filter-form" method="GET">
                <input type="hidden" name="tab" value="<?php echo e($tabFilter); ?>">
                <?php if($active_program): ?><input type="hidden" name="program" value="<?php echo e($active_program); ?>"><?php endif; ?>
                
                <i class="ph ph-magnifying-glass search-icon"></i>
                <input class="search-input" id="liveSearchInput" type="text" name="search" placeholder="Search records..." value="<?php echo e($search); ?>">
                <?php if ($active_program && stripos($active_program, 'TUPAD') !== false): ?>
                <select name="tupad_category" class="program-category-select" aria-label="Filter by TUPAD category" onchange="this.form.submit()">
                    <option value="All">All TUPAD Categories</option>
                    <?php foreach ($tupadCategoryOptions as $category): ?><option value="<?php echo e($category); ?>" <?php echo $tupadCategoryFilter === $category ? 'selected' : ''; ?>><?php echo e($category); ?></option><?php endforeach; ?>
                </select>
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
                        (SELECT SUM(IF(approval_status='Pending',1,0)) FROM programs p WHERE p.program_name = c.program_name) as pending_count 
                        FROM program_categories c";
                $res = $conn->query($sql);
            ?>
                <div class="program-grid">
                    <article class="program-card-shell add-program-card" onclick="document.getElementById('addProgModal').classList.add('show');">
                        <div class="add-card-content">
                            <div class="add-icon"><i class="ph ph-plus"></i></div>
                            <h3>Add Program</h3>
                            <p style="color:var(--muted); font-size:12px; margin-top:4px;">Define a new category</p>
                        </div>
                    </article>
                    <?php if($res): while ($dir = $res->fetch_assoc()): if ($tabFilter !== 'All' && $dir['batch_count'] == 0) continue; ?>
                    <article class="program-card-shell">
                        <a href="?program=<?php echo urlencode($dir['program_name']); ?>" style="display:block; text-decoration:none; color:inherit;">
                            <div class="program-image-wrap">
                                <div class="program-image-bg" style="background-image: url('<?php echo $dir['image_path'] ?: 'img/pesobgs.jpg'; ?>');"></div>
                                <div class="program-image-overlay"></div>
                                
                                <?php if($dir['pending_count'] > 0): ?>
                                    <div class="pill pending" style="position: absolute; top: 16px; right: 16px; z-index: 2; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
                                        <?php echo $dir['pending_count']; ?> Pending
                                    </div>
                                <?php endif; ?>

                                <h3 class="program-card-title"><?php echo e($dir['program_name']); ?></h3>
                            </div>
                        </a>
                        <div class="program-body">
                            <div class="program-meta-clean">
                                <div class="meta-item">
                                    <i class="ph ph-stack"></i>
                                    <span><strong><?php echo $dir['batch_count']; ?></strong> Total Batches</span>
                                </div>
                                <?php if($dir['pending_count'] > 0): ?>
                                <div class="meta-item text-warning">
                                    <i class="ph-fill ph-warning-circle"></i>
                                    <span><strong><?php echo $dir['pending_count']; ?></strong> Need Review</span>
                                </div>
                                <?php else: ?>
                                <div class="meta-item" style="color: var(--muted); opacity: 0.7;">
                                    <i class="ph-fill ph-check-circle" style="color: var(--green);"></i>
                                    <span>All batches updated</span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <a href="?program=<?php echo urlencode($dir['program_name']); ?>" class="btn-main" style="width: 100%; margin-top: auto;">
                                <i class="ph ph-folder-open" style="font-size: 1.1rem; margin-right: 6px;"></i> Open Directory
                            </a>
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
                        (SELECT COUNT(*) FROM beneficiaries b WHERE b.program_id = p.program_id) as beneficiary_count,
                        (SELECT CONCAT(first_name, ' ', last_name) FROM peso_staff WHERE staff_id = p.created_by) as staff_creator
                        FROM programs p 
                        WHERE $batchWhereStr 
                        ORDER BY $orderByClause LIMIT $limit OFFSET $offset";
                $res = $conn->query($sql);
            ?>
                <div class="table-wrap">
                    <table class="data-table batch-table">
                        <thead>
                            <tr>
                                <th>Batch Details</th>
                                <th>Capacity Details</th>
                                <th>Schedule & Metadata</th>
                                <th class="col-status">STATUS</th>
                                <th class="col-action">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($res && $res->num_rows > 0): while($b = $res->fetch_assoc()): 
                                
                                if ($b['slots'] > 0 && $b['beneficiary_count'] >= $b['slots'] && $b['status'] !== 'Completed') {
                                    $b['status'] = 'Completed';
                                    if(isset($b['program_id'])){
                                        $conn->query("UPDATE programs SET status = 'Completed' WHERE program_id = " . (int)$b['program_id']);
                                    }
                                }
                                
                                $pct = $b['slots'] > 0 ? min(100, round(($b['beneficiary_count'] / $b['slots']) * 100)) : 0;
                                
                                if ($b['approval_status'] === 'Pending') {
                                    $display_status = 'Pending Approval';
                                    $status_class = 'pill warning';
                                    $dot_class = 'pending';
                                } else {
                                    $display_status = $b['status'];
                                    $status_str = strtolower($b['status']);
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
                                title="Click to view full details">
                                
                                <td>
                                    <?php if($b['approval_status'] !== 'Pending'): ?>
                                        <a href="peso_staff_beneficiaries.php?program_name=<?php echo urlencode($b['program_name']); ?>&program_id=<?php echo (int)$b['program_id']; ?>" class="batch-title-link" title="Open Applicants">
                                            <div class="batch-title-td" style="color: var(--green); display:flex; align-items:center; gap:6px;">
                                                <?php echo e($b['program_name']); ?> <i class="ph-bold ph-link" style="font-size: 14px;"></i>
                                            </div>
                                            <div class="batch-code-td" style="color: var(--green-dark);">Code: <?php echo e($b['program_code']); ?></div>
                                            <?php if (stripos($b['program_name'], 'TUPAD') !== false): ?><div class="batch-code-td" style="color:var(--green); margin-top:3px;"><i class="ph ph-tag"></i> <?php echo e($b['tupad_category'] ?: 'Regular TUPAD'); ?></div><?php endif; ?>
                                        </a>
                                    <?php else: ?>
                                        <div style="cursor: not-allowed; opacity: 0.7;" title="Pending Approval">
                                            <div class="batch-title-td" style="color: var(--muted); display:flex; align-items:center; gap:6px;">
                                                <?php echo e($b['program_name']); ?> <i class="ph-bold ph-lock-key" style="font-size: 14px;"></i>
                                            </div>
                                            <div class="batch-code-td">Code: <?php echo e($b['program_code']); ?></div>
                                            <?php if (stripos($b['program_name'], 'TUPAD') !== false): ?><div class="batch-code-td" style="margin-top:3px;"><i class="ph ph-tag"></i> <?php echo e($b['tupad_category'] ?: 'Regular TUPAD'); ?></div><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size: 13px; font-weight: 600; margin-bottom: 6px; white-space: nowrap;">
                                        <span style="color:var(--green-dark); font-weight:800; font-size:14px;"><?php echo (int)$b['beneficiary_count']; ?></span> / <?php echo e($b['slots']); ?> Applied
                                    </div>
                                    <div class="slim-progress">
                                        <div class="slim-fill <?php echo $pct >= 100 ? 'full' : ($pct >= 80 ? 'warning' : 'safe'); ?>" style="width: <?php echo $pct; ?>%;"></div>
                                    </div>
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
                                        
                                        <?php if($b['approval_status'] === 'Pending'): ?>
                                            <span class="btn-action-view" style="opacity: 0.6; pointer-events: none; background: #f4f8f5;" title="Awaiting admin approval">
                                                <i class="ph-bold ph-lock-key"></i> Applicants
                                            </span>
                                        <?php else: ?>
                                            <a href="peso_staff_beneficiaries.php?program_name=<?php echo urlencode($b['program_name']); ?>&program_id=<?php echo (int)$b['program_id']; ?>" class="btn-action-view">
                                                <i class="ph-bold ph-users"></i> Applicants
                                            </a>
                                        <?php endif; ?>

                                        <div class="dropdown-wrapper">
                                            <button class="btn-icon" onclick="toggleDropdown(this)" title="More Options">
                                                <i class="ph-bold ph-dots-three-vertical"></i>
                                            </button>
                                            <div class="dropdown-menu">
                                                <button class="dropdown-item open-view-trigger" data-title="<?php echo e($b['program_name']); ?>" data-code="<?php echo e($b['program_code']); ?>" data-start="<?php echo date('M j, Y', strtotime($b['start_date'])); ?>" data-end="<?php echo date('M j, Y', strtotime($b['end_date'])); ?>" data-venue="<?php echo e($b['venue']); ?>" data-slots="<?php echo e($b['slots']); ?>">
                                                    <i class="ph ph-info"></i> View Details
                                                </button>
                                            </div>
                                        </div>

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

<div class="modal" id="addProgModal">
    <div class="modal-backdrop" data-close-modal="addProgModal"></div>
    <div class="modal-dialog landscape-modal">
        <div class="modal-head">
            <div><div class="modal-title">New Program</div><div class="modal-sub">Create a new program category.</div></div>
            <button class="modal-close" data-close-modal="addProgModal"><i class="ph ph-x"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="modal-form">
            <input type="hidden" name="action" value="add_category">
            
            <div class="landscape-form-grid">
                <div class="form-col">
                    <div class="form-group"><label>Name *</label><input type="text" name="program_name" id="add_program_name" list="programTemplateNames" required placeholder="e.g. TUPAD"><datalist id="programTemplateNames"><?php foreach ($programTemplates as $template): ?><option value="<?php echo e($template['program_name']); ?>"><?php endforeach; ?></datalist><small>Select an existing LGU program name to fill its official details automatically.</small></div>
                    <div class="form-group"><label>Description *</label><textarea name="description" id="add_program_description" rows="2" required></textarea></div>
                    <div class="form-group"><label>Eligibility *</label><textarea name="eligibility" id="add_program_eligibility" rows="2" required></textarea></div>
                    <div class="form-group"><label>Eligible Sex *</label><select name="eligible_sex" id="add_program_sex" required><option value="Any">Any sex</option><option value="Male">Male only</option><option value="Female">Female only</option></select></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div class="form-group"><label>Minimum Age *</label><input type="number" name="minimum_age" id="add_program_min_age" min="0" max="120" value="18" required></div><div class="form-group"><label>Maximum Age</label><input type="number" name="maximum_age" id="add_program_max_age" min="0" max="120" placeholder="No maximum"></div></div>
                    <label style="display:flex;gap:9px;align-items:flex-start;font-size:13px"><input type="checkbox" name="one_per_household" id="add_program_one_household" value="1" style="width:auto;margin-top:3px"> Allow only one pending or active beneficiary per household</label>
                    <div class="form-group"><label>Requirements *</label><textarea name="requirements" id="add_program_requirements" rows="2" required></textarea></div>
                </div>
                <div class="form-col">
                    <div class="form-group" style="height: 100%; display: flex; flex-direction: column;">
                        <label>Cover Image *</label>
                        <label class="upload-zone" id="uploadZone">
                            <input type="file" name="image_path" id="imageInput" accept=".jpg,.jpeg,.png,.webp" style="display: none;" required>
                            
                            <div id="uploadContent" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; width: 100%;">
                                <div class="upload-icon-wrap"><i class="ph ph-upload-simple"></i></div>
                                <span class="upload-text-main">CLICK OR DRAG IMAGE</span>
                                <span class="upload-text-sub">JPEG, PNG, OR WEBP (MAX 5MB)</span>
                            </div>
                            
                            <div id="imagePreview" style="display: none; width: 100%; height: 100%; position: relative;">
                                <img id="previewImg" src="" alt="Preview" style="width: 100%; height: 100%; object-fit: cover; border-radius: 12px;">
                                <div class="preview-overlay">
                                    <i class="ph ph-arrows-clockwise"></i> Click to change
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-light" data-close-modal="addProgModal">Cancel</button>
                <button type="submit" class="btn-main">Save Program</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="addBatchModal">
    <div class="modal-backdrop" data-close-modal="addBatchModal"></div>
    <div class="modal-dialog landscape-modal" style="max-width: 700px;">
        <div class="modal-head">
            <div><div class="modal-title">Add Batch</div><div class="modal-sub">Schedule a rollout for <?php echo e($active_program); ?></div></div>
            <button class="modal-close" data-close-modal="addBatchModal"><i class="ph ph-x"></i></button>
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
                <button type="submit" class="btn-main">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="viewBatchModal">
    <div class="modal-backdrop" data-close-modal="viewBatchModal"></div>
    <div class="modal-dialog" style="max-width: 500px;">
        <div class="modal-head">
            <div><div class="modal-title">Batch Details</div><div class="modal-sub">Complete scheduling and capacity info.</div></div>
            <button class="modal-close" data-close-modal="viewBatchModal"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-form">
            <div class="form-grid" style="gap: 12px; padding-bottom: 24px;">
                <div class="form-group span-2"><label>Program Name</label><div class="view-data" id="view_title"></div></div>
                <div class="form-group span-2"><label>Batch Code</label><div class="view-data" id="view_code"></div></div>
                <div class="form-group"><label>Start Date</label><div class="view-data" id="view_start"></div></div>
                <div class="form-group"><label>End Date</label><div class="view-data" id="view_end"></div></div>
                <div class="form-group"><label>Venue</label><div class="view-data" id="view_venue"></div></div>
                <div class="form-group"><label>Total Slots</label><div class="view-data" id="view_slots"></div></div>
            </div>
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
const programTemplates = <?php echo json_encode($programTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const addProgramName = document.getElementById('add_program_name');
function autofillProgramTemplate() {
    if (!addProgramName) return;
    const selected = programTemplates.find(item => item.program_name.toLowerCase() === addProgramName.value.trim().toLowerCase());
    if (!selected) return;
    document.getElementById('add_program_description').value = selected.description || '';
    document.getElementById('add_program_eligibility').value = selected.eligibility || '';
    document.getElementById('add_program_requirements').value = selected.requirements || '';
    document.getElementById('add_program_sex').value = selected.eligible_sex || 'Any';
    document.getElementById('add_program_min_age').value = selected.minimum_age ?? 18;
    document.getElementById('add_program_max_age').value = selected.maximum_age ?? '';
    document.getElementById('add_program_one_household').checked = Number(selected.one_per_household) === 1;
}
addProgramName?.addEventListener('input', autofillProgramTemplate);
addProgramName?.addEventListener('change', autofillProgramTemplate);

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

document.querySelectorAll('[data-close-modal]').forEach(b => b.onclick = (e) => { e.preventDefault(); document.getElementById(b.dataset.closeModal).classList.remove('show'); });

const programModals = Array.from(document.querySelectorAll('.modal'));
const syncProgramModalState = () => {
    const openModal = programModals.find(modal => modal.classList.contains('show'));
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
    if (openModal) openModal.classList.remove('show');
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
setTimeout(() => { const f = document.querySelector('.flash'); if(f) { f.style.opacity = '0'; setTimeout(() => f.remove(), 300); } }, 4000);

function openRowDetails(e, row) {
    if(e.target.closest('.col-action') || e.target.closest('a')) {
        return; 
    }
    
    document.getElementById('view_title').innerText = row.dataset.title;
    document.getElementById('view_code').innerText = row.dataset.code;
    document.getElementById('view_start').innerText = row.dataset.start;
    document.getElementById('view_end').innerText = row.dataset.end;
    document.getElementById('view_venue').innerText = row.dataset.venue;
    document.getElementById('view_slots').innerText = row.dataset.slots;
    document.getElementById('viewBatchModal').classList.add('show');
}

document.querySelectorAll('.open-view-trigger').forEach(btn => {
    btn.onclick = (e) => {
        e.preventDefault();
        document.getElementById('view_title').innerText = btn.dataset.title;
        document.getElementById('view_code').innerText = btn.dataset.code;
        document.getElementById('view_start').innerText = btn.dataset.start;
        document.getElementById('view_end').innerText = btn.dataset.end;
        document.getElementById('view_venue').innerText = btn.dataset.venue;
        document.getElementById('view_slots').innerText = btn.dataset.slots;
        document.getElementById('viewBatchModal').classList.add('show');
    };
});

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

const imageInput = document.getElementById('imageInput');
const uploadContent = document.getElementById('uploadContent');
const imagePreview = document.getElementById('imagePreview');
const previewImg = document.getElementById('previewImg');
const uploadZone = document.getElementById('uploadZone');

if(imageInput) {
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
