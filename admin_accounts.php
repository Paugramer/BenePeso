<?php
session_start();
require "db.php";

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit();
}

$admin_id = (int)$_SESSION["admin_id"];
$admin_name = $_SESSION["admin_name"] ?? "Administrator";
$admin_pic = "default_avatar.png";
$pic_path = "uploads/admin_pics/" . $admin_pic;
if (!file_exists($pic_path) || empty($admin_pic)) { $pic_path = "img/default_avatar.png"; }

$view = $_GET['view'] ?? 'staff';
$search = trim($_GET['search'] ?? '');
$banned_filter = $_GET['banned_filter'] ?? 'all';

$flash = $_SESSION["flash"] ?? "";
$flash_type = $_SESSION["flash_type"] ?? "success";
unset($_SESSION["flash"], $_SESSION["flash_type"]);

if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)($s ?? ""), ENT_QUOTES, "UTF-8"); }
}

function navClass($fileName){
    $current = basename($_SERVER["PHP_SELF"]);
    return ($current === $fileName) ? "nav-item active" : "nav-item";
}

function getDisplayName($row) {
    if (!empty($row['full_name'])) return e($row['full_name']);
    $first = $row['first_name'] ?? '';
    $last = $row['last_name'] ?? '';
    $combined = trim("$first $last");
    return !empty($combined) ? e($combined) : 'Unknown User';
}

function getProfileImage($filename, $type) {
    if (empty($filename) || $filename === 'default_avatar.png') return '';
    $folder = ($type === 'staff') ? 'uploads/staff_pics/' : 'uploads/user_pics/';
    return $folder . $filename;
}

function build_query(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $k => $v) { if ($v === null || $v === "") unset($query[$k]); }
    return http_build_query($query);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === 'ban_account') {
        $id = (int)$_POST['ban_id'];
        $type = $_POST['account_type'];
        $banned_status = 'Banned';

        if ($type === 'staff') {
            $get = $conn->prepare("SELECT * FROM peso_staff WHERE staff_id = ?");
            $get->bind_param("i", $id);
            $get->execute();
            $res = $get->get_result()->fetch_assoc();
            $name = $res ? getDisplayName($res) : 'Unknown Staff';

            // Direct SQL Logging for Banning Staff
            $log_desc = "Banned PESO Staff account: $name";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, description, created_at) VALUES (?, 'Administrator', 'Manage Accounts', 'BAN', ?, NOW())");
            if ($log_stmt) {
                $log_stmt->bind_param("ss", $admin_name, $log_desc);
                $log_stmt->execute();
                $log_stmt->close();
            }

            $stmt = $conn->prepare("UPDATE peso_staff SET status = ? WHERE staff_id = ?");
            $stmt->bind_param("si", $banned_status, $id);
            $stmt->execute();

        } elseif ($type === 'user') {
            $get = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
            $get->bind_param("i", $id);
            $get->execute();
            $res = $get->get_result()->fetch_assoc();
            $name = $res ? getDisplayName($res) : 'Unknown User';

            // Direct SQL Logging for Banning User
            $log_desc = "Banned User account: $name";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, description, created_at) VALUES (?, 'Administrator', 'Manage Accounts', 'BAN', ?, NOW())");
            if ($log_stmt) {
                $log_stmt->bind_param("ss", $admin_name, $log_desc);
                $log_stmt->execute();
                $log_stmt->close();
            }

            $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
            $stmt->bind_param("si", $banned_status, $id);
            $stmt->execute();
        }

        $_SESSION["flash"] = ucfirst($type) . " account banned successfully.";
        $_SESSION["flash_type"] = "warning";
        header("Location: admin_accounts.php?view=$view");
        exit();
    }

    if ($action === 'unban_account') {
        $id = (int)$_POST['unban_id'];
        $type = $_POST['account_type'];
        $active_status = 'Active';

        if ($type === 'staff') {
            $get = $conn->prepare("SELECT * FROM peso_staff WHERE staff_id = ?");
            $get->bind_param("i", $id);
            $get->execute();
            $res = $get->get_result()->fetch_assoc();
            $name = $res ? getDisplayName($res) : 'Unknown Staff';

            // Direct SQL Logging for Unbanning Staff
            $log_desc = "Unbanned PESO Staff account: $name";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, description, created_at) VALUES (?, 'Administrator', 'Manage Accounts', 'UNBAN', ?, NOW())");
            if ($log_stmt) {
                $log_stmt->bind_param("ss", $admin_name, $log_desc);
                $log_stmt->execute();
                $log_stmt->close();
            }

            $stmt = $conn->prepare("UPDATE peso_staff SET status = ? WHERE staff_id = ?");
            $stmt->bind_param("si", $active_status, $id);
            $stmt->execute();

        } elseif ($type === 'user') {
            $get = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
            $get->bind_param("i", $id);
            $get->execute();
            $res = $get->get_result()->fetch_assoc();
            $name = $res ? getDisplayName($res) : 'Unknown User';

            // Direct SQL Logging for Unbanning User
            $log_desc = "Unbanned User account: $name";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, description, created_at) VALUES (?, 'Administrator', 'Manage Accounts', 'UNBAN', ?, NOW())");
            if ($log_stmt) {
                $log_stmt->bind_param("ss", $admin_name, $log_desc);
                $log_stmt->execute();
                $log_stmt->close();
            }

            $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
            $stmt->bind_param("si", $active_status, $id);
            $stmt->execute();
        }

        $_SESSION["flash"] = ucfirst($type) . " account restored successfully.";
        $_SESSION["flash_type"] = "success";
        header("Location: admin_accounts.php?view=$view");
        exit();
    }

    if ($action === 'edit_staff') {
        $id = (int)$_POST['staff_id'];
        $fname = trim($_POST['first_name']);
        $lname = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $new_pass = trim($_POST['new_password'] ?? '');
        
        $pic_param = null;
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
            $allowed = ['jpg', 'jpeg', 'png'];
            $filename = $_FILES['profile_pic']['name'];
            $ext_file = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext_file, $allowed)) {
                $new_name = uniqid("staff_") . "." . $ext_file;
                $dest_dir = "uploads/staff_pics/";
                if (!is_dir($dest_dir)) mkdir($dest_dir, 0777, true);
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $dest_dir . $new_name)) {
                    $pic_param = $new_name;
                }
            }
        }

        $update_sql = "UPDATE peso_staff SET first_name=?, last_name=?, email=?";
        $types = "sss";
        $params = [$fname, $lname, $email];

        if (!empty($new_pass)) {
            $update_sql .= ", password_hash=?";
            $types .= "s";
            $params[] = password_hash($new_pass, PASSWORD_DEFAULT);
        }

        if ($pic_param) {
            $update_sql .= ", profile_picture=?";
            $types .= "s";
            $params[] = $pic_param;
        }

        $update_sql .= " WHERE staff_id=?";
        $types .= "i";
        $params[] = $id;

        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param($types, ...$params);
        
        if($stmt->execute()) {
            
            // Direct SQL Logging for Editing Staff
            $name_for_log = "$fname $lname";
            $log_desc = "Updated PESO Staff details for: $name_for_log";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (actor_name, actor_role, module_name, action_type, description, created_at) VALUES (?, 'Administrator', 'Manage Accounts', 'UPDATE', ?, NOW())");
            if ($log_stmt) {
                $log_stmt->bind_param("ss", $admin_name, $log_desc);
                $log_stmt->execute();
                $log_stmt->close();
            }

            $_SESSION["flash"] = "Staff account updated successfully.";
            $_SESSION["flash_type"] = "success";
        }
        header("Location: admin_accounts.php?view=$view");
        exit();
    }
}

$globalStaff = $conn->query("SELECT COUNT(*) as c FROM peso_staff")->fetch_assoc()['c'] ?? 0;
$globalUsers = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'] ?? 0;
$globalTotal = $globalStaff + $globalUsers;

$whereClause = "(status != 'Banned' OR status IS NULL)";
$searchConditionBanned = "";

if ($search !== '') {
    $s = $conn->real_escape_string($search);
    if ($view === 'staff') {
        $whereClause .= " AND (first_name LIKE '%$s%' OR last_name LIKE '%$s%' OR email LIKE '%$s%')";
    } else {
        $whereClause .= " AND (first_name LIKE '%$s%' OR last_name LIKE '%$s%' OR barangay LIKE '%$s%')";
    }
    $searchConditionBanned = " AND (first_name LIKE '%$s%' OR last_name LIKE '%$s%' OR email LIKE '%$s%')";
}

$limit = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

if ($view === 'staff') {
    $filteredCount = $conn->query("SELECT COUNT(*) as c FROM peso_staff WHERE $whereClause")->fetch_assoc()['c'] ?? 0;
} elseif ($view === 'user') {
    $filteredCount = $conn->query("SELECT COUNT(*) as c FROM users WHERE $whereClause")->fetch_assoc()['c'] ?? 0;
} else {
    if ($banned_filter === 'staff') {
        $countQuery = "SELECT COUNT(*) as total FROM peso_staff WHERE status = 'Banned' $searchConditionBanned";
    } elseif ($banned_filter === 'user') {
        $countQuery = "SELECT COUNT(*) as total FROM users WHERE status = 'Banned' $searchConditionBanned";
    } else {
        $countQuery = "SELECT SUM(c) as total FROM (
            SELECT COUNT(*) as c FROM peso_staff WHERE status = 'Banned' $searchConditionBanned
            UNION ALL
            SELECT COUNT(*) as c FROM users WHERE status = 'Banned' $searchConditionBanned
        ) as combined_counts";
    }
    $filteredCount = $conn->query($countQuery)->fetch_assoc()['total'] ?? 0;
}

$totalPages = max(1, ceil($filteredCount / $limit));

$panelTitle = 'Staff Directory';
if ($view === 'user') $panelTitle = 'User Directory';
if ($view === 'banned') $panelTitle = 'Banned Accounts Directory';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="img/pesologo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BENEPESO | Manage Accounts</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" href="admin_accounts.css">
    <link rel="stylesheet" href="shared_sidebar.css">
    <link rel="stylesheet" href="admin_accounts_polish.css">
    <link rel="stylesheet" href="frontend_polish.css?v=1">
    <script src="frontend_polish.js?v=1" defer></script>
</head>
<body>

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
        <img src="<?php echo e($pic_path ?? ''); ?>" alt="Admin" class="user-img-side" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin_name); ?>&background=1f7a54&color=fff';">
      </div>
      <div>
        <div class="user-name"><?php echo e($admin_name); ?></div>
        <div class="user-role">Administrator</div>
      </div>
    </div>

    <nav class="nav-area">
        <a href="admin_dashboard.php" class="<?php echo navClass('admin_dashboard.php'); ?>"><i class="ph ph-squares-four"></i> Dashboard</a>
        <a href="admin_program.php" class="<?php echo navClass('admin_program.php'); ?>"><i class="ph ph-briefcase"></i> Programs</a>
        <a href="admin_beneficiaries.php" class="<?php echo navClass('admin_beneficiaries.php'); ?>"><i class="ph ph-users"></i> Beneficiaries</a>
        <a href="admin_accounts.php" class="<?php echo navClass('admin_accounts.php'); ?>"><i class="ph ph-user-circle-gear"></i> Manage Accounts</a>
        <a href="admin_activity_log.php" class="<?php echo navClass('admin_activity_log.php'); ?>"><i class="ph ph-clock-counter-clockwise"></i> System Logs</a>
        <a href="logout.php?role=admin" class="nav-item logout-item"><i class="ph ph-sign-out"></i> Logout</a>
    </nav>
</aside>
<main class="main-area">

<header class="top-area animate-fade-in">
    <div class="top-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
            <span></span><span></span><span></span>
        </button>
        <div class="top-title">
            <div class="eyebrow">ACCOUNT CONTROL</div>
            <div class="top-big">Manage System Access</div>
            <div class="top-sub">Review and manage Staff and User credentials.</div>
        </div>
    </div>

    <div class="top-actions">
        <div class="top-chip">
            <img src="<?php echo e($pic_path ?? ''); ?>" alt="" class="chip-img" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin_name); ?>&background=1f7a54&color=fff';">
            Administrator
        </div>
    </div>
</header>

<section class="stats-grid">
    <div class="stat-card animate-fade-in" style="animation-delay: 0.1s;">
        <div class="stat-top">
            <div class="stat-label">Total Accounts</div>
            <div class="stat-icon" style="color: #64748b; background: #f1f5f9;"><i class="ph-fill ph-database"></i></div>
        </div>
        <div class="stat-value"><?= (int)$globalTotal ?></div>
        <div class="stat-trend trend-neutral"><i class="ph-bold ph-folder-notch"></i> System Tracked</div>
        <div class="stat-note">System-wide records combined</div>
    </div>
    
    <div class="stat-card animate-fade-in" style="animation-delay: 0.2s;">
        <div class="stat-top">
            <div class="stat-label">System Staff</div>
            <div class="stat-icon"><i class="ph-fill ph-user-circle-gear"></i></div>
        </div>
        <div class="stat-value"><?= (int)$globalStaff ?></div>
        <div class="stat-trend trend-up"><i class="ph-bold ph-trend-up"></i> Active Personnel</div>
        <div class="stat-note">Administrative accounts created</div>
    </div>
    
    <div class="stat-card animate-fade-in" style="animation-delay: 0.3s;">
        <div class="stat-top">
            <div class="stat-label">Registered Users</div>
            <div class="stat-icon" style="color: #0f766e; background: #ccfbf1;"><i class="ph-fill ph-users-three"></i></div>
        </div>
        <div class="stat-value"><?= (int)$globalUsers ?></div>
        <div class="stat-trend trend-up"><i class="ph-bold ph-check-circle"></i> Verified Users</div>
        <div class="stat-note">Verified Beneficiaries in database</div>
    </div>
</section>

<div class="controls-row animate-fade-in" style="animation-delay: 0.4s;">
    <div class="controls-flex-container">
        <div class="segmented-control">
            <a href="?view=staff&page=1" class="segment-btn <?= $view==='staff'?'active':'' ?>">Staff Accounts</a>
            <a href="?view=user&page=1" class="segment-btn <?= $view==='user'?'active':'' ?>">Registered Users</a>
            <a href="?view=banned&page=1" class="segment-btn <?= $view==='banned'?'active':'' ?>">Banned Accounts</a>
        </div>
        
        <form class="filter-form" method="GET">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            
            <?php if($view === 'banned'): ?>
            <select name="banned_filter" class="banned-filter-select" onchange="this.form.submit()">
                <option value="all" <?= $banned_filter === 'all' ? 'selected' : '' ?>>All Types</option>
                <option value="staff" <?= $banned_filter === 'staff' ? 'selected' : '' ?>>Staff Only</option>
                <option value="user" <?= $banned_filter === 'user' ? 'selected' : '' ?>>Users Only</option>
            </select>
            <?php endif; ?>
            
            <i class="ph ph-magnifying-glass search-icon"></i>
            <input type="text" name="search" class="search-input" id="liveSearchInput" placeholder="Search accounts by name, email, or barangay..." value="<?= e($search) ?>">
        </form>

        <?php if($view === 'staff'): ?>
        <a href="admin_add_staff.php" class="btn-main">
            <i class="ph-bold ph-plus" style="font-size: 1.1rem; margin-right: 6px;"></i> Add PESO Staff
        </a>
        <?php endif; ?>
    </div>
</div>

<section class="chart-section">
    <div class="panel-card animate-fade-in" style="animation-delay: 0.5s;">
        <div class="panel-head">
            <div>
                <div class="panel-title"><?= e($panelTitle) ?></div>
                <div class="panel-sub">Manage active credentials and system access permissions.</div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <?php if($view==='staff'): ?>
                    <tr>
                        <th width="35%">Staff Info</th>
                        <th width="20%" class="col-status">Account Status</th>
                        <th width="25%" style="text-align: center;">Joined Date</th>
                        <th width="20%" class="col-action">Action</th>
                    </tr>
                    <?php elseif($view==='user'): ?>
                    <tr>
                        <th width="30%">User Info</th>
                        <th width="20%">Barangay</th>
                        <th width="15%" style="text-align: center;">Contact</th>
                        <th width="20%" class="col-status">Account Status</th>
                        <th width="15%" class="col-action">Action</th>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <th width="30%">Account Info</th>
                        <th width="18%">Account Type</th>
                        <th width="17%" class="col-status">Account Status</th>
                        <th width="20%" style="text-align: center;">Joined Date</th>
                        <th width="15%" class="col-action">Action</th>
                    </tr>
                    <?php endif; ?>
                </thead>

                <tbody>
                    <?php
                    if ($view==='staff') {
                        $res = $conn->query("SELECT * FROM peso_staff WHERE $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
                        if ($res && $res->num_rows > 0) {
                            while($row = $res->fetch_assoc()):
                                $displayName = getDisplayName($row);
                                $first_name = trim($row['first_name'] ?? '');
                                $last_name = trim($row['last_name'] ?? '');
                                $joinDate = date("M d, Y", strtotime($row['created_at'] ?? 'now'));
                                $email = !empty($row['email']) ? e($row['email']) : 'No email provided';
                                
                                $status = $row['status'] ?? 'Active';
                                $isBanned = strcasecmp($status, 'Banned') === 0;
                                
                                $imgSrc = getProfileImage($row['profile_picture'] ?? '', 'staff');
                                $encodedName = urlencode($displayName);
                                $fallbackAvatar = "https://ui-avatars.com/api/?name={$encodedName}&background=e6f4ed&color=1f7a54&bold=true";
                    ?>
                    <tr class="table-row-animate clickable-row" onclick="triggerProfileCardFromRow(this)"
                        data-type="staff" data-id="<?= $row['staff_id'] ?>" data-name="<?= e($displayName) ?>"
                        data-fname="<?= e($first_name) ?>" data-lname="<?= e($last_name) ?>"
                        data-email="<?= $email ?>" data-status="<?= e($status) ?>"
                        data-joined="<?= $joinDate ?>" data-img="<?= e($imgSrc) ?>" data-fallback="<?= e($fallbackAvatar) ?>">
                        
                        <td>
                            <div class="table-user-cell">
                                <div class="avatar-circle">
                                    <img src="<?= !empty($imgSrc) ? e($imgSrc) : e($fallbackAvatar) ?>" 
                                         onerror="this.src='<?= e($fallbackAvatar) ?>';" 
                                         style="width:100%; height:100%; border-radius:50%; object-fit:cover;" alt="Profile">
                                </div>
                                <div>
                                    <div class="table-name row-title" style="transition: 0.2s;"><?= e($displayName) ?></div>
                                    <div style="font-size: 12px; color: var(--muted); margin-top: 4px;"><?= $email ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="col-status">
                            <div style="display:flex; justify-content:center; align-items:center;">
                                <?php if($isBanned): ?>
                                    <span class="pill danger"><span class="pulse-dot banned"></span> Banned</span>
                                <?php else: ?>
                                    <span class="pill success"><span class="pulse-dot active"></span> Active</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="text-align: center; color: var(--muted); font-size: 13px;">
                            <i class="ph ph-calendar-blank"></i> <?= $joinDate ?>
                        </td>
                        <td class="col-action">
                            <div class="action-flex-wrapper">
                                <button type="button" class="btn-action-view" onclick="event.stopPropagation(); triggerProfileCardFromRow(this.closest('tr'))">
                                    <i class="ph ph-identification-card" style="font-size: 1.1rem;"></i> View
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php 
                            endwhile;
                        } else {
                            echo "<tr><td colspan='4'>
                                    <div class='empty-state' style='border:none; box-shadow:none;'>
                                        <i class='ph ph-users empty-icon' style='font-size: 48px;'></i>
                                        <h4>No staff accounts found.</h4>
                                    </div>
                                  </td></tr>";
                        }

                    } elseif ($view === 'user') {
                        $res = $conn->query("SELECT * FROM users WHERE $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
                        if ($res && $res->num_rows > 0) {
                            while($row = $res->fetch_assoc()):
                                $displayName = getDisplayName($row);
                                $first_name = trim($row['first_name'] ?? '');
                                $last_name = trim($row['last_name'] ?? '');
                                $barangay = !empty($row['barangay']) ? e($row['barangay']) : '—';
                                $joinDate = date("M d, Y", strtotime($row['created_at'] ?? 'now'));
                                
                                $contact = !empty($row['contact_no']) ? e($row['contact_no']) : '—';
                                $maskedContact = ($contact !== '—' && strlen($contact) >= 10) ? substr($contact, 0, 4) . ' &bull;&bull;&bull; &bull;' . substr($contact, -3) : $contact;

                                $status = $row['status'] ?? 'Active';
                                $isBanned = strcasecmp($status, 'Banned') === 0;

                                $imgSrc = getProfileImage($row['profile_picture'] ?? '', 'user');
                                $encodedName = urlencode($displayName);
                                $fallbackAvatar = "https://ui-avatars.com/api/?name={$encodedName}&background=e6f4ed&color=1f7a54&bold=true";
                    ?>
                    <tr class="table-row-animate clickable-row" onclick="triggerProfileCardFromRow(this)"
                        data-type="user" data-id="<?= $row['user_id'] ?>" data-name="<?= e($displayName) ?>"
                        data-fname="<?= e($first_name) ?>" data-lname="<?= e($last_name) ?>"
                        data-barangay="<?= $barangay ?>" data-contact="<?= $contact ?>" data-maskedcontact="<?= $maskedContact ?>"
                        data-status="<?= e($status) ?>" data-joined="<?= $joinDate ?>" data-img="<?= e($imgSrc) ?>" data-fallback="<?= e($fallbackAvatar) ?>">
                        
                        <td>
                            <div class="table-user-cell">
                                <div class="avatar-circle">
                                    <img src="<?= !empty($imgSrc) ? e($imgSrc) : e($fallbackAvatar) ?>" 
                                         onerror="this.src='<?= e($fallbackAvatar) ?>';" 
                                         style="width:100%; height:100%; border-radius:50%; object-fit:cover;" alt="Profile">
                                </div>
                                <div>
                                    <div class="table-name row-title" style="transition: 0.2s;"><?= e($displayName) ?></div>
                                    <div style="font-size: 12px; color: var(--muted); margin-top: 4px;"><i class="ph ph-calendar-blank"></i> Joined <?= $joinDate ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="text-transform: uppercase; font-weight: 700; color: var(--green-dark); font-size: 13px;">
                            <i class="ph ph-map-pin" style="color: var(--muted);"></i> <?= $barangay ?>
                        </td>
                        <td style="text-align: center; color: var(--text);">
                            <div style="display:flex; align-items:center; justify-content:center; gap:8px;">
                                <span class="contact-val" data-full="<?= $contact ?>" data-masked="<?= $maskedContact ?>"><?= $maskedContact ?></span>
                                <?php if($contact !== '—'): ?>
                                <button type="button" class="btn-icon-tiny" onclick="toggleContact(event, this)" title="Show Number">
                                    <i class="ph-bold ph-eye"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="col-status">
                            <div style="display:flex; justify-content:center; align-items:center;">
                                <?php if($isBanned): ?>
                                    <span class="pill danger"><span class="pulse-dot banned"></span> Banned</span>
                                <?php else: ?>
                                    <span class="pill success"><span class="pulse-dot active"></span> Active</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="col-action">
                            <div class="action-flex-wrapper">
                                <button type="button" class="btn-action-view" onclick="event.stopPropagation(); triggerProfileCardFromRow(this.closest('tr'))">
                                    <i class="ph ph-identification-card" style="font-size: 1.1rem;"></i> View
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php 
                            endwhile; 
                        } else {
                            echo "<tr><td colspan='5'>
                                    <div class='empty-state' style='border:none; box-shadow:none;'>
                                        <i class='ph ph-users empty-icon' style='font-size: 48px;'></i>
                                        <h4>No registered users found.</h4>
                                    </div>
                                  </td></tr>";
                        }
                    } else {
                        $q_staff = "SELECT staff_id AS id, first_name, last_name, email, '—' AS contact_no, '—' AS barangay, created_at, status, profile_picture, 'staff' AS account_type FROM peso_staff WHERE status = 'Banned' $searchConditionBanned";
                        $q_user = "SELECT user_id AS id, first_name, last_name, email, contact_no, barangay, created_at, status, profile_pic AS profile_picture, 'user' AS account_type FROM users WHERE status = 'Banned' $searchConditionBanned";

                        if ($banned_filter === 'staff') {
                            $dataQuery = "$q_staff ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
                        } elseif ($banned_filter === 'user') {
                            $dataQuery = "$q_user ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
                        } else {
                            $dataQuery = "$q_staff UNION ALL $q_user ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
                        }

                        $res = $conn->query($dataQuery);
                        
                        if ($res && $res->num_rows > 0) {
                            while($row = $res->fetch_assoc()):
                                $first_name = trim($row['first_name'] ?? '');
                                $last_name = trim($row['last_name'] ?? '');
                                $displayName = !empty(trim("$first_name $last_name")) ? e(trim("$first_name $last_name")) : 'Unknown';
                                $joinDate = date("M d, Y", strtotime($row['created_at'] ?? 'now'));
                                
                                $email = !empty($row['email']) ? e($row['email']) : 'No email provided';
                                $contact = !empty($row['contact_no']) ? e($row['contact_no']) : '—';
                                $barangay = !empty($row['barangay']) ? e($row['barangay']) : '—';
                                $maskedContact = ($contact !== '—' && strlen($contact) >= 10) ? substr($contact, 0, 4) . ' &bull;&bull;&bull; &bull;' . substr($contact, -3) : $contact;

                                $status = $row['status'] ?? 'Active';
                                $type = $row['account_type'];
                                
                                $imgSrc = getProfileImage($row['profile_picture'] ?? '', $type);
                                $encodedName = urlencode($displayName);
                                $fallbackAvatar = "https://ui-avatars.com/api/?name={$encodedName}&background=e6f4ed&color=1f7a54&bold=true";

                                $badgeText = $type === 'staff' ? 'PESO STAFF' : 'REGISTERED USER';
                                $badgeStyle = $type === 'staff' 
                                    ? "background: var(--green-light); color: var(--green-dark); border: 1px solid rgba(31,122,84,0.2);" 
                                    : "background: #ffffff; color: var(--muted); border: 1px solid var(--line);";
                    ?>
                    <tr class="table-row-animate clickable-row" onclick="triggerProfileCardFromRow(this)"
                        data-type="<?= e($type) ?>" data-id="<?= $row['id'] ?>" data-name="<?= e($displayName) ?>"
                        data-fname="<?= e($first_name) ?>" data-lname="<?= e($last_name) ?>"
                        data-email="<?= $email ?>" data-barangay="<?= $barangay ?>" data-contact="<?= $contact ?>" data-maskedcontact="<?= $maskedContact ?>"
                        data-status="<?= e($status) ?>" data-joined="<?= $joinDate ?>" data-img="<?= e($imgSrc) ?>" data-fallback="<?= e($fallbackAvatar) ?>">
                        
                        <td>
                            <div class="table-user-cell">
                                <div class="avatar-circle">
                                    <img src="<?= !empty($imgSrc) ? e($imgSrc) : e($fallbackAvatar) ?>" 
                                         onerror="this.src='<?= e($fallbackAvatar) ?>';" 
                                         style="width:100%; height:100%; border-radius:50%; object-fit:cover;" alt="Profile">
                                </div>
                                <div>
                                    <div class="table-name row-title" style="transition: 0.2s;"><?= e($displayName) ?></div>
                                    <div style="font-size: 12px; color: var(--muted); margin-top: 4px;"><?= $type === 'staff' ? $email : $barangay ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="pill" style="<?= $badgeStyle ?>"><?= $badgeText ?></span>
                        </td>
                        <td class="col-status">
                            <div style="display:flex; justify-content:center; align-items:center;">
                                <span class="pill danger"><span class="pulse-dot banned"></span> Banned</span>
                            </div>
                        </td>
                        <td style="text-align: center; color: var(--muted); font-size: 13px;">
                            <i class="ph ph-calendar-blank"></i> <?= $joinDate ?>
                        </td>
                        <td class="col-action">
                            <div class="action-flex-wrapper">
                                <button type="button" class="btn-action-view" onclick="event.stopPropagation(); triggerProfileCardFromRow(this.closest('tr'))">
                                    <i class="ph ph-identification-card" style="font-size: 1.1rem;"></i> View
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php 
                            endwhile;
                        } else {
                            echo "<tr><td colspan='5'>
                                    <div class='empty-state' style='border:none; box-shadow:none;'>
                                        <i class='ph ph-shield-warning empty-icon' style='font-size: 48px; color: #fca5a5;'></i>
                                        <h4>No banned accounts found.</h4>
                                    </div>
                                  </td></tr>";
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper">
            <span class="page-info">Showing Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong></span>
            <div class="pagination-controls">
                <a href="?<?= build_query(['page' => max(1, $page - 1)]) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="ph ph-caret-left"></i> Prev</a>
                <a href="?<?= build_query(['page' => min($totalPages, $page + 1)]) ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Next <i class="ph ph-caret-right"></i></a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

</main>
</div>

<div class="modal" id="idCardModal" aria-hidden="true">
  <div class="modal-backdrop" onclick="closeProfileCard()"></div>
  <div class="modal-dialog id-card-dialog" style="padding: 0;">
    <button type="button" class="modal-close-icon" onclick="closeProfileCard()" style="position: absolute; top: 16px; right: 16px; z-index: 10;">
        <i class="ph-bold ph-x"></i>
    </button>

    <div class="id-card-left" id="idCardLeftPanel">
       <div class="id-card-pattern"></div>
       <div class="id-avatar-large" id="idAvatar">
           <img src="" id="modalAvatarImg" style="width:100%; height:100%; object-fit:cover; border-radius:50%;" alt="Profile">
       </div>
       <div class="id-badge" id="idBadge">Role</div>
    </div>

    <div class="id-card-right">
       <div class="id-header">
          <div style="display: flex; align-items: center; gap: 16px;">
              <h3 class="id-name" id="idName" style="margin: 0;">User Name</h3>
              <div id="idStatusContainer"></div>
          </div>
          <p class="id-joined" id="idJoined" style="margin-top: 6px;">Joined: Jan 1, 2026</p>
       </div>
       <div class="id-details-grid" id="idDetailsGrid"></div>
       <div class="id-actions" style="margin-top: auto; padding-top: 24px; border-top: 1px solid var(--line);">
          <button type="button" class="btn-main" id="idBtnEdit" style="display:none;"><i class="ph-bold ph-pencil-simple" style="margin-right: 6px;"></i> Edit Account</button>
          <button type="button" class="btn-ghost-danger" id="idBtnBan" style="display:none;"><i class="ph-bold ph-prohibit" style="margin-right: 6px;"></i> Ban Account</button>
          <button type="button" class="btn-main" id="idBtnUnban" style="display:none; background: #059669; box-shadow: 0 8px 20px rgba(5, 150, 105, 0.25);"><i class="ph-bold ph-arrow-counter-clockwise" style="margin-right: 6px;"></i> Unban Account</button>
       </div>
    </div>
  </div>
</div>

<div class="modal" id="editStaffModal" aria-hidden="true">
    <div class="modal-backdrop" onclick="closeEditModal()"></div>
    <div class="modal-dialog landscape-modal">
        <div class="modal-head">
            <div><div class="modal-title">Edit Staff Account</div><div class="modal-sub">Update profile details and system credentials.</div></div>
            <button type="button" class="modal-close" onclick="closeEditModal()"><i class="ph-bold ph-x"></i></button>
        </div>
        <form method="POST" class="modal-form" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_staff">
            <input type="hidden" name="staff_id" id="edit_staff_id">
            
            <div class="edit-profile-header-clean">
                <div class="avatar-edit-container">
                    <img id="edit_modal_avatar" src="" alt="Staff Profile">
                    <label for="edit_profile_pic" class="avatar-upload-btn">
                        <i class="ph-bold ph-camera"></i>
                    </label>
                </div>
                <input type="file" id="edit_profile_pic" name="profile_pic" accept="image/png, image/jpeg" style="display: none;">
                <h4 id="edit_header_name">Staff Name</h4>
                <span class="pill success" style="border:none; padding: 4px 12px; margin-top: 4px;">PESO STAFF</span>
            </div>

            <div class="landscape-form-grid">
                <div class="form-col">
                    <div class="form-group"><label>First Name <span style="color:#e11d48">*</span></label><input type="text" name="first_name" id="edit_fname" required></div>
                    <div class="form-group"><label>Last Name <span style="color:#e11d48">*</span></label><input type="text" name="last_name" id="edit_lname" required></div>
                    <div class="form-group"><label>Email Address <span style="color:#e11d48">*</span></label><input type="email" name="email" id="edit_email" required></div>
                </div>
                <div class="form-col">
                    <div class="security-box">
                        <div style="display:flex; align-items:center; gap:8px; color: var(--green-dark); margin-bottom:16px;">
                            <i class="ph-fill ph-shield-check" style="font-size:24px;"></i>
                            <h4 style="font-weight: 800; font-size:15px; margin:0;">Account Security</h4>
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 16px;">
                            <label>Old Password</label>
                            <input type="password" name="old_password" id="edit_old_password" placeholder="Enter current password" autocomplete="new-password">
                        </div>
                        
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" id="edit_new_password" placeholder="Enter new password" autocomplete="new-password">
                        </div>
                        
                        <p style="font-size: 11px; color: var(--muted); margin-top: 14px; font-weight: 500; line-height: 1.4;">Leave password fields blank if you do not wish to change the system credentials.</p>
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-light" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn-main"><i class="ph-bold ph-check-circle" style="margin-right: 6px;"></i> Update Account</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="banModal" aria-hidden="true">
  <div class="modal-backdrop" onclick="closeBanModal()"></div>
  <div class="success-dialog" style="text-align:center; padding: 40px; margin-top: 10vh;">
    <div class="warning-icon" style="background:linear-gradient(135deg, #ff6b6b, #d93838); color:#fff; box-shadow:0 16px 32px rgba(217, 56, 56, 0.25); border:none;">
        <i class="ph-bold ph-prohibit" style="font-size: 36px;"></i>
    </div>
    <div class="success-title" style="color:#a32222; margin-bottom: 12px;">Ban Account?</div>
    <div class="success-text" style="color:#66786f; font-size: 14.5px; font-weight: 500; line-height: 1.5; margin-bottom: 32px;">Are you sure you want to ban this account? The user will be immediately logged out and prevented from accessing the system.</div>

    <form method="POST" style="margin:0;">
        <input type="hidden" name="action" value="ban_account">
        <input type="hidden" name="ban_id" id="ban_id">
        <input type="hidden" name="account_type" id="ban_account_type">
        <div style="display:flex; gap:12px; justify-content:center;">
            <button type="button" class="btn-light" style="flex:1;" onclick="closeBanModal()">Cancel</button>
            <button type="submit" class="btn-danger" style="flex:1;">Yes, Ban Account</button>
        </div>
    </form>
  </div>
</div>

<div class="modal" id="unbanModal" aria-hidden="true">
  <div class="modal-backdrop" onclick="closeUnbanModal()"></div>
  <div class="success-dialog" style="text-align:center; padding: 40px; margin-top: 10vh;">
    <div class="warning-icon" style="background:linear-gradient(135deg, #22c55e, #16a34a); color:#fff; box-shadow:0 16px 32px rgba(34, 197, 94, 0.25); border: 4px solid #86efac;">
        <i class="ph-bold ph-arrow-counter-clockwise" style="font-size: 36px;"></i>
    </div>
    <div class="success-title" style="color:var(--green-dark); margin-bottom: 12px;">Unban Account?</div>
    <div class="success-text" style="color:#66786f; font-size: 14.5px; font-weight: 500; line-height: 1.5; margin-bottom: 32px;">Are you sure you want to restore access for this account? They will be able to log into the system immediately.</div>

    <form method="POST" style="margin:0;">
        <input type="hidden" name="action" value="unban_account">
        <input type="hidden" name="unban_id" id="unban_id">
        <input type="hidden" name="account_type" id="unban_account_type">
        <div style="display:flex; gap:12px; justify-content:center;">
            <button type="button" class="btn-light" style="flex:1;" onclick="closeUnbanModal()">Cancel</button>
            <button type="submit" class="btn-main" style="flex:1; background:#059669;">Yes, Unban Account</button>
        </div>
    </form>
  </div>
</div>

<?php if ($flash): ?>
<div class="flash <?php echo $flash_type; ?>">
    <?php if($flash_type === 'warning'): ?>
        <i class="ph-fill ph-warning-circle" style="color: #dc2626; margin-right: 8px; font-size: 1.2rem;"></i> 
    <?php else: ?>
        <i class="ph-fill ph-check-circle" style="color: var(--green); margin-right: 8px; font-size: 1.2rem;"></i> 
    <?php endif; ?>
    <?php echo e($flash); ?>
</div>
<?php endif; ?>

<script>
    function triggerProfileCardFromRow(row) {
        const data = row.dataset;
        document.getElementById('idName').textContent = data.name;
        document.getElementById('idJoined').textContent = "Joined: " + data.joined;

        const modalImg = document.getElementById('modalAvatarImg');
        const imgSource = data.img && data.img.trim() !== '' ? data.img : data.fallback;
        modalImg.src = imgSource;
        modalImg.onerror = function() { this.src = data.fallback; };

        const detailsGrid = document.getElementById('idDetailsGrid');
        const badge = document.getElementById('idBadge');
        const btnEdit = document.getElementById('idBtnEdit');
        const btnBan = document.getElementById('idBtnBan');
        const btnUnban = document.getElementById('idBtnUnban');
        const statusContainer = document.getElementById('idStatusContainer');

        let statusText = data.status === 'Banned' ? '<span class="status-badge-banned">BANNED</span>' : '<span class="status-badge-active">ACTIVE</span>';
        statusContainer.innerHTML = statusText;

        if (data.type === 'staff') {
            badge.textContent = "PESO Staff";
            
            detailsGrid.innerHTML = `
                <div class="detail-group"><label>First Name</label><div class="detail-value">${data.fname}</div></div>
                <div class="detail-group"><label>Last Name</label><div class="detail-value">${data.lname}</div></div>
                <div class="detail-group" style="grid-column: span 2;"><label>Email Address</label><div class="detail-value" style="text-transform: none;">${data.email}</div></div>`;
            
            if (data.status === 'Banned') {
                btnEdit.style.display = 'none';
            } else {
                btnEdit.style.display = 'inline-flex';
                btnEdit.onclick = function() {
                    closeProfileCard();
                    openEditStaffModal(data);
                };
            }

        } else {
            badge.textContent = "Registered User";
            
            let contactHtml = `<div class="detail-value">${data.contact}</div>`;
            if (data.contact !== '—') {
                contactHtml = `
                <div class="detail-value" style="display:flex; justify-content:space-between; align-items:center;">
                    <span class="contact-val" data-full="${data.contact}" data-masked="${data.maskedcontact}">${data.maskedcontact}</span>
                    <button type="button" class="btn-icon-tiny" onclick="toggleContact(event, this)" title="Show Number" style="margin-left: 8px;">
                        <i class="ph-bold ph-eye"></i>
                    </button>
                </div>`;
            }

            detailsGrid.innerHTML = `
                <div class="detail-group"><label>First Name</label><div class="detail-value">${data.fname}</div></div>
                <div class="detail-group"><label>Last Name</label><div class="detail-value">${data.lname}</div></div>
                <div class="detail-group"><label>Barangay</label><div class="detail-value" style="text-transform: uppercase;">${data.barangay}</div></div>
                <div class="detail-group"><label>Contact Number</label>${contactHtml}</div>`;
            
            btnEdit.style.display = 'none';
        }

        if (data.status === 'Banned') {
            btnBan.style.display = 'none';
            btnUnban.style.display = 'inline-flex';
            btnUnban.onclick = function() {
                closeProfileCard();
                triggerUnbanConfirmation(data.id, data.type);
            };
        } else {
            btnUnban.style.display = 'none';
            btnBan.style.display = 'inline-flex';
            btnBan.onclick = function() {
                closeProfileCard();
                triggerBanConfirmation(data.id, data.type);
            };
        }
        
        document.getElementById('idCardModal').classList.add('show');
    }

    function closeProfileCard() { document.getElementById('idCardModal').classList.remove('show'); }

    function toggleContact(e, btn) {
        e.stopPropagation();
        const span = btn.previousElementSibling;
        const icon = btn.querySelector('i');
        if (span.innerHTML === span.dataset.masked) {
            span.innerHTML = span.dataset.full;
            icon.classList.remove('ph-eye');
            icon.classList.add('ph-eye-slash');
        } else {
            span.innerHTML = span.dataset.masked;
            icon.classList.remove('ph-eye-slash');
            icon.classList.add('ph-eye');
        }
    }

    function triggerBanConfirmation(id, type) {
        document.getElementById('ban_id').value = id;
        document.getElementById('ban_account_type').value = type;
        document.getElementById('banModal').classList.add('show');
    }
    function closeBanModal() { document.getElementById('banModal').classList.remove('show'); }

    function triggerUnbanConfirmation(id, type) {
        document.getElementById('unban_id').value = id;
        document.getElementById('unban_account_type').value = type;
        document.getElementById('unbanModal').classList.add('show');
    }
    function closeUnbanModal() { document.getElementById('unbanModal').classList.remove('show'); }

    function openEditStaffModal(data) {
        document.getElementById('edit_staff_id').value = data.id;
        document.getElementById('edit_fname').value = data.fname;
        document.getElementById('edit_lname').value = data.lname;
        document.getElementById('edit_email').value = data.email;
        document.getElementById('edit_old_password').value = '';
        document.getElementById('edit_new_password').value = '';
        
        document.getElementById('edit_header_name').textContent = data.fname + " " + data.lname;
        const imgSource = data.img && data.img.trim() !== '' ? data.img : data.fallback;
        const editAvatar = document.getElementById('edit_modal_avatar');
        editAvatar.src = imgSource;
        editAvatar.onerror = function() { this.src = data.fallback; };

        document.getElementById('editStaffModal').classList.add('show');
    }
    function closeEditModal() { document.getElementById('editStaffModal').classList.remove('show'); }
    
    document.addEventListener('DOMContentLoaded', () => {
        const menuToggle = document.getElementById('menuToggle');
        const sideArea = document.getElementById('sideArea');
        const sideClose = document.getElementById('sideClose');
        const overlay = document.getElementById('sidebarOverlay');

        if(menuToggle) menuToggle.addEventListener('click', () => { sideArea.classList.add('open'); overlay.classList.add('show'); });
        if(sideClose) sideClose.addEventListener('click', () => { sideArea.classList.remove('open'); overlay.classList.remove('show'); });
        if(overlay) overlay.addEventListener('click', () => { sideArea.classList.remove('open'); overlay.classList.remove('show'); });

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
                searchTimeout = setTimeout(() => { this.form.submit(); }, 600); 
            });
        }
        
        const editProfileInput = document.getElementById('edit_profile_pic');
        if(editProfileInput) {
            editProfileInput.addEventListener('change', function() {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        document.getElementById('edit_modal_avatar').src = e.target.result;
                    }
                    reader.readAsDataURL(file);
                }
            });
        }

        setTimeout(() => { const f = document.querySelector('.flash'); if(f) { f.style.opacity = '0'; setTimeout(() => f.remove(), 300); } }, 4000);
    });
</script>

</body>
</html>
