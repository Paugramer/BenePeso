<?php
session_start();
require "db.php";

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit();
}

$admin_id = (int)$_SESSION["admin_id"];
$admin_name = "System Administrator";
$admin_position = "Admin";
$admin_pic = "default_avatar.png";
$pic_path = "uploads/admin_pics/" . $admin_pic;
if (!file_exists($pic_path) || empty($admin_pic)) { $pic_path = "img/default_avatar.png"; }

$admin_info = $conn->prepare("SELECT email FROM admins WHERE admin_id = ? LIMIT 1");
if ($admin_info) {
    $admin_info->bind_param("i", $admin_id);
    $admin_info->execute();
    $admin_result = $admin_info->get_result();
    if ($admin_row = $admin_result->fetch_assoc()) {
        $email_parts = explode('@', (string)$admin_row['email']);
        $admin_name = ucfirst($email_parts[0]);
        $admin_position = "Administrator";
    }
    $admin_info->close();
}

function h($v){
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

function navClass($fileName){
    $current = basename($_SERVER["PHP_SELF"]);
    return ($current === $fileName) ? "nav-item active" : "nav-item";
}

function buildQuery(array $overrides = []) {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $k => $v) { if ($v === null || $v === "") unset($query[$k]); }
    return "?" . http_build_query($query);
}

$total_logs = $conn->query("SELECT COUNT(*) as c FROM activity_logs")->fetch_assoc()['c'] ?? 0;
$today_logs = $conn->query("SELECT COUNT(*) as c FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'] ?? 0;
$staff_logs = $conn->query("SELECT COUNT(*) as c FROM activity_logs WHERE staff_id IS NOT NULL OR actor_role = 'PESO Staff'")->fetch_assoc()['c'] ?? 0;

$limit = 7; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = trim($_GET['search'] ?? '');
$filter_role = $_GET['role'] ?? '';
$filter_module = $_GET['module'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$whereParts = ["1=1"];
$params = [];
$types = "";

if ($search !== '') {
    $whereParts[] = "(description LIKE ? OR actor_name LIKE ? OR target_name LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s);
    $types .= "sss";
}

if ($filter_role !== '') {
    if ($filter_role === 'Admin') {
        $whereParts[] = "(actor_role = 'Administrator' OR actor_name = 'System Admin' OR actor_name = 'Admin')";
    } elseif ($filter_role === 'Staff') {
        $whereParts[] = "(actor_role = 'PESO Staff' OR staff_id IS NOT NULL)";
    } elseif ($filter_role === 'User') {
        $whereParts[] = "(actor_role = 'User' OR actor_role = 'Registered User')";
    }
}

if ($filter_module !== '') {
    if ($filter_module === 'Auth') {
        $whereParts[] = "(module_name IN ('Auth', 'authentication', 'Administrator', 'PESO Staff'))";
    } elseif ($filter_module === 'Accounts') {
        $whereParts[] = "(module_name IN ('accounts', 'Manage Accounts', 'Account'))";
    } elseif ($filter_module === 'Programs') {
        $whereParts[] = "(module_name IN ('Program', 'programs'))";
    } elseif ($filter_module === 'Beneficiaries') {
        $whereParts[] = "(module_name IN ('Beneficiaries', 'Beneficiary'))";
    } else {
        $whereParts[] = "module_name = ?";
        $params[] = $filter_module;
        $types .= "s";
    }
}

if ($date_from !== '') {
    $whereParts[] = "DATE(created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to !== '') {
    $whereParts[] = "DATE(created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$whereClause = "WHERE " . implode(" AND ", $whereParts);

$countSql = "SELECT COUNT(*) as total FROM activity_logs $whereClause";
$stmtC = $conn->prepare($countSql);
if (!empty($params)) {
    $stmtC->bind_param($types, ...$params);
}
$stmtC->execute();
$totalRecords = $stmtC->get_result()->fetch_assoc()['total'] ?? 0;
$totalPages = max(1, ceil($totalRecords / $limit));

$sql = "SELECT * FROM activity_logs $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $bindParams = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($types . "ii", ...$bindParams);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();
$result = $stmt->get_result();

$clean_modules = [
    'Auth' => 'Authentication',
    'Accounts' => 'Manage Accounts',
    'Beneficiaries' => 'Beneficiaries',
    'Programs' => 'Programs',
    'Profile' => 'Profile'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="img/pesologo.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BENEPESO | Activity Log</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="stylesheet" href="admin_activity_log.css">
<link rel="stylesheet" href="shared_sidebar.css">
<link rel="stylesheet" href="activity_filter_polish.css?v=1">
<script src="activity_filter_polish.js?v=1" defer></script>
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
            <img src="<?php echo h($pic_path ?? ''); ?>" alt="Admin" class="user-img-side" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin_name); ?>&background=1f7a54&color=fff';">
          </div>
          <div>
            <div class="user-name"><?php echo h($admin_name); ?></div>
            <div class="user-role"><?php echo h($admin_position); ?></div>
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
                    <div class="eyebrow">SYSTEM MONITORING</div>
                    <div class="top-big">System Activity Log</div>
                    <div class="top-sub">Track system-wide actions, program updates, and user records in one place.</div>
                </div>
            </div>

            <div class="top-actions">
                <div class="top-chip">
                    <img src="<?php echo h($pic_path ?? ''); ?>" alt="" class="chip-img" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin_name); ?>&background=1f7a54&color=fff';">
                    Administrator
                </div>
            </div>
        </header>

        <section class="stats-grid">
            <div class="stat-card animate-fade-in" style="animation-delay: 0.1s;">
                <div class="stat-top">
                    <div class="stat-label">TOTAL LOGS</div>
                    <div class="stat-icon" style="color: var(--green); background: var(--green-light);"><i class="ph-fill ph-stack"></i></div>
                </div>
                <div class="stat-value"><?= number_format($total_logs) ?></div>
                <div class="stat-note">All recorded system activities</div>
            </div>
            
            <div class="stat-card animate-fade-in" style="animation-delay: 0.2s;">
                <div class="stat-top">
                    <div class="stat-label">TODAY</div>
                    <div class="stat-icon" style="color: #0f766e; background: #ccfbf1;"><i class="ph-fill ph-clock"></i></div>
                </div>
                <div class="stat-value"><?= number_format($today_logs) ?></div>
                <div class="stat-note">Actions recorded today</div>
            </div>
            
            <div class="stat-card animate-fade-in" style="animation-delay: 0.3s;">
                <div class="stat-top">
                    <div class="stat-label">STAFF ACTIONS</div>
                    <div class="stat-icon" style="color: #4338ca; background: #e0e7ff;"><i class="ph-fill ph-users-three"></i></div>
                </div>
                <div class="stat-value"><?= number_format($staff_logs) ?></div>
                <div class="stat-note">Actions by active PESO Staff</div>
            </div>
        </section>

        <section class="chart-section" style="margin-top: 4px;">
            <div class="panel-card animate-fade-in" style="animation-delay: 0.4s;">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">Activity Records</div>
                        <div class="panel-sub">Search and filter the complete activity history.</div>
                    </div>
                </div>

                <form method="GET" class="advanced-filter-row" id="filterForm">
                    <div style="position: relative; flex: 1; min-width: 200px;">
                        <i class="ph ph-magnifying-glass search-input-icon"></i>
                        <input type="text" name="search" class="filter-input-search" id="liveSearchInput" placeholder="Search module, action, desc..." value="<?= h($search) ?>">
                    </div>
                    
                    <?php $roleLabels = ['' => 'All Roles', 'Admin' => 'Admin Only', 'Staff' => 'PESO Staff Only', 'User' => 'Users Only']; ?>
                    <input type="hidden" name="role" value="<?= h($filter_role) ?>" data-filter-input="role">
                    <div class="activity-filter-menu" data-filter-menu="role">
                        <button type="button" class="activity-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span><?= h($roleLabels[$filter_role] ?? 'All Roles') ?></span><i class="ph ph-caret-down"></i></button>
                        <div class="activity-filter-options" role="listbox" aria-label="Filter by role" hidden>
                            <?php foreach($roleLabels as $val => $label): ?><button type="button" role="option" data-filter-value="<?= h($val) ?>" aria-selected="<?= $filter_role === $val ? 'true' : 'false' ?>"><span><?= h($label) ?></span><?php if($filter_role === $val): ?><i class="ph-bold ph-check"></i><?php endif; ?></button><?php endforeach; ?>
                        </div>
                    </div>

                    <input type="hidden" name="module" value="<?= h($filter_module) ?>" data-filter-input="module">
                    <div class="activity-filter-menu" data-filter-menu="module">
                        <button type="button" class="activity-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span><?= h($clean_modules[$filter_module] ?? 'All Modules') ?></span><i class="ph ph-caret-down"></i></button>
                        <div class="activity-filter-options" role="listbox" aria-label="Filter by module" hidden>
                            <button type="button" role="option" data-filter-value="" aria-selected="<?= $filter_module === '' ? 'true' : 'false' ?>"><span>All Modules</span><?php if($filter_module === ''): ?><i class="ph-bold ph-check"></i><?php endif; ?></button>
                            <?php foreach($clean_modules as $val => $label): ?><button type="button" role="option" data-filter-value="<?= h($val) ?>" aria-selected="<?= $filter_module === $val ? 'true' : 'false' ?>"><span><?= h($label) ?></span><?php if($filter_module === $val): ?><i class="ph-bold ph-check"></i><?php endif; ?></button><?php endforeach; ?>
                        </div>
                    </div>

                    <input type="date" name="date_from" class="filter-date" value="<?= h($date_from) ?>" onchange="this.form.submit()" title="Start Date">
                    <input type="date" name="date_to" class="filter-date" value="<?= h($date_to) ?>" onchange="this.form.submit()" title="End Date">
                    
                    <?php if($search || $filter_role || $filter_module || $date_from || $date_to): ?>
                        <a href="admin_activity_log.php" class="btn-clear" title="Clear Filters"><i class="ph-bold ph-x"></i></a>
                    <?php endif; ?>
                </form>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th width="20%">DATE & TIME</th>
                                <th width="20%">ACTOR</th>
                                <th width="15%">MODULE</th>
                                <th width="15%">ACTION</th>
                                <th width="30%">DESCRIPTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result && $result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()): 
                                    
                                    $display_role = h($row['actor_role'] ?? 'Unknown');
                                    $actor_name = h($row['actor_name'] ?? $row['target_name'] ?? 'System');
                                    $action_title = strtoupper(h($row['action_type'] ?? 'LOG'));
                                    $module_name = h($row['module_name'] ?? 'System');
                                    $desc = h($row['description'] ?? '');
                                    
                                    if ($module_name === 'Administrator' || $module_name === 'PESO Staff') {
                                        $display_role = $module_name;
                                        $actor_name = ($module_name === 'Administrator') ? 'System Admin' : 'Staff Member';
                                        $module_name = 'Auth';
                                        $action_title = 'LOGIN';
                                        $desc = 'User logged in securely.';
                                    }

                                    if (strtolower($actor_name) === 'admin' || strtolower($actor_name) === 'system admin' || $actor_name === '0') {
                                        $display_role = 'Administrator';
                                        $actor_name = 'System Admin';
                                    }
                                    
                                    if ($display_role === 'Unknown') {
                                        if (stripos($actor_name, 'Admin') !== false) $display_role = 'Administrator';
                                        elseif (!empty($row['staff_id'])) $display_role = 'PESO Staff';
                                        else $display_role = 'User';
                                    }

                                    if (strtolower($module_name) === 'authentication') $module_name = 'Auth';
                                    if (strtolower($module_name) === 'programs') $module_name = 'Program';
                                    if (strtolower($module_name) === 'accounts') $module_name = 'Manage Accounts';

                                    $pillClass = "pill-gray"; 
                                    if ($action_title === 'CREATE' || $action_title === 'ADD' || $action_title === 'UNBAN' || strpos($action_title, 'LOGIN') !== false) {
                                        $pillClass = "pill-green";
                                    } elseif ($action_title === 'UPDATE' || $action_title === 'EDIT' || strpos($action_title, 'AUTH') !== false) {
                                        $pillClass = "pill-blue";
                                    } elseif ($action_title === 'DELETE' || $action_title === 'BAN' || strpos($action_title, 'LOGOUT') !== false) {
                                        $pillClass = "pill-red";
                                    }
                                ?>
                                <tr class="table-row-animate">
                                    <td>
                                        <div style="font-weight: 800; color: var(--text); font-size: 13px;"><?= date("M d, Y", strtotime($row['created_at'])) ?> <span style="color:var(--muted); font-weight:600; font-size:12px; margin-left:4px;">&bull; <?= date("h:i A", strtotime($row['created_at'])) ?></span></div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 800; color: var(--green-dark); font-size: 13.5px;"><?= $actor_name ?></div>
                                        <div style="font-size: 11px; color: var(--muted); font-weight: 600; text-transform: uppercase; margin-top:2px;"><?= $display_role ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: var(--text); font-size: 13.5px;"><?= $module_name ?></div>
                                    </td>
                                    <td>
                                        <span class="action-pill <?= $pillClass ?>"><span class="pill-dot"></span> <?= $action_title ?></span>
                                    </td>
                                    <td>
                                        <div style="font-size: 13.5px; color: var(--muted); font-weight: 500; line-height: 1.5;"><?= $desc ?></div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <i class='ph ph-clock empty-icon'></i>
                                            <h4>No activity logs found.</h4>
                                            <div class="empty-sub">There are no records matching your current filter.</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination-wrapper">
                    <span class="page-info">Showing <?= min($totalRecords, $offset + 1) ?> to <?= min($totalRecords, $offset + $limit) ?> of <?= $totalRecords ?> entries</span>
                    <div class="pagination-controls">
                        <a href="<?= buildQuery(['page' => max(1, $page - 1)]) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">Previous</a>
                        <a href="<?= buildQuery(['page' => min($totalPages, $page + 1)]) ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?> page-btn-next">Next</a>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </section>
    </main>
</div>

<script>
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
    });
</script>

</body>
</html>
