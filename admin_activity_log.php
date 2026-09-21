<?php
require_once __DIR__ . '/auth_session.php';
require "db.php";

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit();
}

$admin_id = (int)$_SESSION["admin_id"];
$admin_name = "PESO Vinzons";
$admin_position = "Administrator";
$admin_pic = "default_avatar.png";
$pic_path = "uploads/admin_pics/" . $admin_pic;
if (!file_exists($pic_path) || empty($admin_pic)) { $pic_path = "img/default_avatar.png"; }

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
$filter_action = trim($_GET['action'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$date_error = '';

if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    $date_error = 'The start date cannot be later than the end date.';
    $date_to = $date_from;
}

$whereParts = ["1=1"];
$params = [];
$types = "";

if ($search !== '') {
    $whereParts[] = "(description LIKE ? OR actor_name LIKE ? OR target_name LIKE ? OR module_name LIKE ? OR action_type LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s, $s);
    $types .= "sssss";
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

if (($_GET['export'] ?? '') === 'csv') {
    $exportSql = "SELECT created_at, actor_name, actor_role, module_name, action_type, target_name, ip_address, description FROM activity_logs $whereClause ORDER BY created_at DESC LIMIT 5000";
    $exportStmt = $conn->prepare($exportSql);
    if (!empty($params)) $exportStmt->bind_param($types, ...$params);
    $exportStmt->execute();
    $exportResult = $exportStmt->get_result();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="benepeso-activity-log-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Date and Time', 'Actor', 'Role', 'Module', 'Action', 'Target', 'IP Address', 'Description']);
    while ($exportRow = $exportResult->fetch_assoc()) fputcsv($output, $exportRow);
    fclose($output);
    exit();
}

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

/* Resolve names for legacy staff log rows that stored only staff_id. */
$staff_names = [];
$staff_name_result = $conn->query("SELECT staff_id, first_name, last_name FROM peso_staff");
if ($staff_name_result) {
    while ($staff_row = $staff_name_result->fetch_assoc()) {
        $resolved = trim(($staff_row['first_name'] ?? '') . ' ' . ($staff_row['last_name'] ?? ''));
        if ($resolved !== '') $staff_names[(int)$staff_row['staff_id']] = $resolved;
    }
}

if ($filter_action !== '') {
    $whereParts[] = "action_type = ?";
    $params[] = $filter_action;
    $types .= "s";
}

$clean_modules = [
    'Auth' => 'Authentication',
    'Accounts' => 'Manage Accounts',
    'Beneficiaries' => 'Beneficiaries',
    'Programs' => 'Programs',
    'Profile' => 'Profile'
];
$action_options = [];
$action_result = $conn->query("SELECT DISTINCT action_type FROM activity_logs WHERE action_type IS NOT NULL AND action_type <> '' ORDER BY action_type ASC");
if ($action_result) while ($action_row = $action_result->fetch_assoc()) $action_options[] = (string)$action_row['action_type'];
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
<link rel="stylesheet" href="activity_filter_polish.css?v=3">
<script src="activity_filter_polish.js?v=3" defer></script>
<link rel="stylesheet" href="frontend_polish.css?v=20260921">
<link rel="stylesheet" href="admin_responsive.css?v=23">
<link rel="stylesheet" href="system_search_polish.css?v=1">
<link rel="stylesheet" href="system_mobile.css?v=1">
<script src="frontend_polish.js?v=20260921" defer></script>
</head>
<body class="admin-mobile-page admin-activity-page">

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
            <form method="POST" action="logout.php" class="sidebar-logout-form"><?php echo auth_csrf_input(); ?><input type="hidden" name="role" value="admin"><button type="submit" class="nav-item logout-item"><i class="ph ph-sign-out"></i> Logout</button></form>
        </nav>
    </aside>

    <main class="main-area">

        <header class="top-area animate-fade-in">
            <div class="top-left">
                <button type="button" class="menu-toggle" id="menuToggle" aria-label="Open menu">
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
                    <?php echo h($admin_name); ?>
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
                <div class="stat-note">All activity recorded in the system.</div>
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
                <div class="stat-note">Actions recorded for PESO staff.</div>
            </div>
        </section>

        <section class="chart-section" style="margin-top: 4px;">
            <div class="panel-card animate-fade-in" style="animation-delay: 0.4s;">
                <div class="panel-head">
                    <div>
                        <div class="panel-title">Activity Records</div>
                        <div class="panel-sub"><?= number_format($totalRecords) ?> matching record<?= (int)$totalRecords === 1 ? '' : 's' ?>. Search, review, or export the current view.</div>
                    </div>
                    <a href="<?= h(buildQuery(['export' => 'csv', 'page' => null])) ?>" class="activity-export-btn"><i class="ph-bold ph-download-simple"></i> Export CSV</a>
                </div>

                <form method="GET" class="advanced-filter-row" id="filterForm">
                    <div class="activity-search-wrap">
                        <i class="ph ph-magnifying-glass search-input-icon"></i>
                        <input type="text" name="search" class="filter-input-search" id="liveSearchInput" placeholder="Search module, action, desc..." value="<?= h($search) ?>">
                    </div>
                    
                    <?php $roleLabels = ['' => 'All Roles', 'Admin' => 'Admin Only', 'Staff' => 'PESO Staff Only', 'User' => 'Users Only']; ?>
                    <input type="hidden" name="role" value="<?= h($filter_role) ?>" data-filter-input="role">
                    <div class="activity-filter-menu" data-filter-menu="role">
                        <button type="button" class="activity-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="activity-trigger-label"><i class="ph ph-users"></i><?= h($roleLabels[$filter_role] ?? 'All Roles') ?></span><i class="ph ph-caret-down"></i></button>
                        <div class="activity-filter-options" role="listbox" aria-label="Filter by role" hidden>
                            <?php foreach($roleLabels as $val => $label): ?><button type="button" role="option" data-filter-value="<?= h($val) ?>" aria-selected="<?= $filter_role === $val ? 'true' : 'false' ?>"><span><?= h($label) ?></span><?php if($filter_role === $val): ?><i class="ph-bold ph-check"></i><?php endif; ?></button><?php endforeach; ?>
                        </div>
                    </div>

                    <input type="hidden" name="module" value="<?= h($filter_module) ?>" data-filter-input="module">
                    <div class="activity-filter-menu" data-filter-menu="module">
                        <button type="button" class="activity-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="activity-trigger-label"><i class="ph ph-squares-four"></i><?= h($clean_modules[$filter_module] ?? 'All Modules') ?></span><i class="ph ph-caret-down"></i></button>
                        <div class="activity-filter-options" role="listbox" aria-label="Filter by module" hidden>
                            <button type="button" role="option" data-filter-value="" aria-selected="<?= $filter_module === '' ? 'true' : 'false' ?>"><span>All Modules</span><?php if($filter_module === ''): ?><i class="ph-bold ph-check"></i><?php endif; ?></button>
                            <?php foreach($clean_modules as $val => $label): ?><button type="button" role="option" data-filter-value="<?= h($val) ?>" aria-selected="<?= $filter_module === $val ? 'true' : 'false' ?>"><span><?= h($label) ?></span><?php if($filter_module === $val): ?><i class="ph-bold ph-check"></i><?php endif; ?></button><?php endforeach; ?>
                        </div>
                    </div>

                    <input type="hidden" name="action" value="<?= h($filter_action) ?>" data-filter-input="action">
                    <div class="activity-filter-menu" data-filter-menu="action">
                        <button type="button" class="activity-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="activity-trigger-label"><i class="ph ph-lightning"></i><?= h($filter_action !== '' ? $filter_action : 'All Actions') ?></span><i class="ph ph-caret-down"></i></button>
                        <div class="activity-filter-options" role="listbox" aria-label="Filter by action" hidden>
                            <button type="button" role="option" data-filter-value="" aria-selected="<?= $filter_action === '' ? 'true' : 'false' ?>"><span>All Actions</span><?php if($filter_action === ''): ?><i class="ph-bold ph-check"></i><?php endif; ?></button>
                            <?php foreach($action_options as $action_option): ?><button type="button" role="option" data-filter-value="<?= h($action_option) ?>" aria-selected="<?= $filter_action === $action_option ? 'true' : 'false' ?>"><span><?= h(ucwords(strtolower($action_option))) ?></span><?php if($filter_action === $action_option): ?><i class="ph-bold ph-check"></i><?php endif; ?></button><?php endforeach; ?>
                        </div>
                    </div>

                    <label class="activity-date-field"><span>From</span><input type="date" name="date_from" class="filter-date" value="<?= h($date_from) ?>" max="<?= h($date_to) ?>" onchange="this.form.submit()"></label>
                    <label class="activity-date-field"><span>To</span><input type="date" name="date_to" class="filter-date" value="<?= h($date_to) ?>" min="<?= h($date_from) ?>" onchange="this.form.submit()"></label>
                    
                    <?php if($search || $filter_role || $filter_module || $filter_action || $date_from || $date_to): ?>
                        <a href="admin_activity_log.php" class="btn-clear activity-clear-btn" title="Clear filters"><i class="ph-bold ph-x"></i><span>Clear</span></a>
                    <?php endif; ?>
                </form>
                <?php if($date_error): ?><div class="activity-filter-alert"><i class="ph ph-warning-circle"></i><?= h($date_error) ?></div><?php endif; ?>

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
                                    
                                    $raw_description = trim((string)($row['description'] ?? ''));
                                    $raw_actor_name = trim((string)($row['actor_name'] ?? ''));
                                    $raw_actor_role = trim((string)($row['actor_role'] ?? ''));
                                    $legacy_admin_entry = stripos($raw_description, 'Admin ') === 0;
                                    if ($legacy_admin_entry) {
                                        $raw_actor_name = 'PESO Vinzons';
                                        $raw_actor_role = 'Administrator';
                                    } elseif (($raw_actor_name === '' || strcasecmp($raw_actor_name, 'PESO Staff') === 0)
                                        && !empty($row['staff_id']) && isset($staff_names[(int)$row['staff_id']])) {
                                        $raw_actor_name = $staff_names[(int)$row['staff_id']];
                                        $raw_actor_role = 'PESO Staff';
                                    }
                                    $display_role = h($raw_actor_role !== '' ? $raw_actor_role : 'Unknown');
                                    $actor_name = h($raw_actor_name !== '' ? $raw_actor_name : ($row['target_name'] ?? 'System'));
                                    $action_title = strtoupper(h($row['action_type'] ?? 'LOG'));
                                    $module_name = h($row['module_name'] ?? 'System');
                                    $desc = h($row['description'] ?? '');
                                    
                                    if ($module_name === 'Administrator' || $module_name === 'PESO Staff') {
                                        $display_role = $module_name;
                                        $actor_name = ($module_name === 'Administrator') ? 'PESO Vinzons' : 'Staff Member';
                                        $module_name = 'Auth';
                                        $action_title = 'LOGIN';
                                        $desc = 'User logged in securely.';
                                    }

                                    if (strtolower($actor_name) === 'admin' || strtolower($actor_name) === 'system admin' || $actor_name === '0') {
                                        $display_role = 'Administrator';
                                        $actor_name = 'PESO Vinzons';
                                    }
                                    
                                    if ($display_role === 'Unknown') {
                                        if (stripos($actor_name, 'Admin') !== false) $display_role = 'Administrator';
                                        elseif (!empty($row['staff_id'])) $display_role = 'PESO Staff';
                                        else $display_role = 'User';
                                    }

                                    if ($display_role === 'Administrator') {
                                        $actor_name = 'PESO Vinzons';
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
                                <tr class="table-row-animate activity-log-row" tabindex="0" data-date="<?= h(date('M d, Y h:i A', strtotime($row['created_at']))) ?>" data-actor="<?= $actor_name ?>" data-role="<?= $display_role ?>" data-module="<?= $module_name ?>" data-action="<?= $action_title ?>" data-target="<?= h($row['target_name'] ?? 'Not specified') ?>" data-ip="<?= h($row['ip_address'] ?? 'Not recorded') ?>" data-description="<?= $desc ?>">
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
                                        <div class="activity-description-cell"><span><?= $desc ?></span><button type="button" class="activity-detail-btn" aria-label="View activity details"><i class="ph ph-arrow-square-out"></i></button></div>
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

<div class="activity-detail-modal" id="activityDetailModal" aria-hidden="true">
    <div class="activity-detail-backdrop" data-close-activity-modal></div>
    <section class="activity-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="activityDetailTitle">
        <header class="activity-detail-head">
            <div class="activity-detail-icon"><i class="ph ph-clock-counter-clockwise"></i></div>
            <div><div class="activity-detail-kicker">AUDIT RECORD</div><h2 id="activityDetailTitle">Activity details</h2></div>
            <button type="button" class="activity-detail-close" data-close-activity-modal aria-label="Close details"><i class="ph ph-x"></i></button>
        </header>
        <div class="activity-detail-body"><div class="activity-detail-grid">
            <div><span>Date and time</span><strong data-detail="date"></strong></div>
            <div><span>Actor</span><strong data-detail="actor"></strong><small data-detail="role"></small></div>
            <div><span>Module</span><strong data-detail="module"></strong></div>
            <div><span>Action</span><strong data-detail="action"></strong></div>
            <div class="activity-detail-wide"><span>IP address</span><strong data-detail="ip"></strong></div>
            <div class="activity-detail-wide"><span>Target</span><strong data-detail="target"></strong></div>
            <div class="activity-detail-wide"><span>Description</span><p data-detail="description"></p></div>
        </div></div>
        <footer class="activity-detail-footer"><button type="button" class="activity-detail-done" data-close-activity-modal>Done</button></footer>
    </section>
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

        const detailModal = document.getElementById('activityDetailModal');
        const closeDetail = () => { detailModal.classList.remove('show'); detailModal.setAttribute('aria-hidden', 'true'); };
        document.querySelectorAll('.activity-log-row').forEach(row => {
            const openDetail = () => {
                ['date','actor','role','module','action','target','ip','description'].forEach(key => {
                    const node = detailModal.querySelector(`[data-detail="${key}"]`);
                    if (node) node.textContent = row.dataset[key] || 'Not specified';
                });
                detailModal.classList.add('show');
                detailModal.setAttribute('aria-hidden', 'false');
                detailModal.querySelector('.activity-detail-close').focus();
            };
            row.addEventListener('click', openDetail);
            row.addEventListener('keydown', event => { if (event.key === 'Enter') openDetail(); });
        });
        document.querySelectorAll('[data-close-activity-modal]').forEach(button => button.addEventListener('click', closeDetail));
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && detailModal.classList.contains('show')) closeDetail(); });
    });
</script>

</body>
</html>
