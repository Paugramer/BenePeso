<?php
require_once __DIR__ . '/auth.php';
require "db.php";
require "functions.php"; 

if (!isset($conn) || !($conn instanceof mysqli)) {
  die("Database connection not found. Please check db.php");
}

/* ==========================================
   PROFESSIONAL FIX: Unified Role Check
========================================== */
check_user_role("peso_staff");

$peso_staff_id = (int) $_SESSION["staff_id"];

/* =========================
   HELPERS
========================= */
function h($value){
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function table_exists(mysqli $conn, string $table): bool {
  $table = $conn->real_escape_string($table);
  $sql = "SHOW TABLES LIKE '$table'";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

function format_datetime_value($value): string {
  if (!$value) return "—";
  $ts = strtotime((string)$value);
  return $ts ? date("M d, Y • h:i A", $ts) : (string)$value;
}

function format_date_input($value): string {
  if (!$value) return "";
  $ts = strtotime((string)$value);
  return $ts ? date("Y-m-d", $ts) : "";
}

function format_activity_datetime($value): string {
  if (!$value) return 'Not specified';
  $ts = strtotime((string)$value);
  return $ts ? date('M d, Y', $ts) . ' • ' . date('h:i A', $ts) : (string)$value;
}

function activity_label($value): string {
  $raw = trim((string)$value);
  $key = strtolower($raw);
  $labels = ['program' => 'Programs', 'programs' => 'Programs', 'authentication' => 'Authentication', 'auth' => 'Authentication', 'account' => 'Manage Accounts', 'accounts' => 'Manage Accounts'];
  return $labels[$key] ?? ($raw !== '' ? ucwords(str_replace('_', ' ', $raw)) : 'Not specified');
}

// UPDATED: Now returns a dot color class instead of a full badge class
function action_dot_class(string $action): string {
  $a = strtolower(trim($action));

  if (in_array($a, ["create", "add beneficiary", "login"], true)) return "success";
  if (in_array($a, ["edit", "update", "update availment"], true)) return "warning";
  if (in_array($a, ["delete", "remove", "logout"], true)) return "danger";
  if (in_array($a, ["approve", "approved"], true)) return "success";
  if (in_array($a, ["reject", "rejected"], true)) return "danger";

  return "neutral";
}

// Smart query builder for pagination links
function build_query(array $overrides = []): string {
  $query = array_merge($_GET, $overrides);
  foreach ($query as $k => $v) {
    if ($v === null || $v === "") {
      unset($query[$k]);
    }
  }
  return http_build_query($query);
}

/* =========================
   STAFF INFO
========================= */
$staff_name = "PESO Staff";
$staff_position = "Staff";
$staff_pic = "default_avatar.png";

if (table_exists($conn, "peso_staff")) {
  $stmt = $conn->prepare("
    SELECT first_name, last_name, position, profile_picture
    FROM peso_staff
    WHERE staff_id = ?
    LIMIT 1
  ");
  if ($stmt) {
    $stmt->bind_param("i", $peso_staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
      $staff_name = trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""));
      $staff_position = $row["position"] ?? $staff_position;
      $staff_pic = !empty($row["profile_picture"]) ? $row["profile_picture"] : "default_avatar.png";
    }
    $stmt->close();
  }
}

if (empty($staff_name)) {
    $staff_name = "PESO Staff";
}

$pic_path = "uploads/staff_pics/" . $staff_pic;
if (!file_exists($pic_path) || empty($staff_pic)) {
    $pic_path = "img/default_user.svg";
}
$initial = strtoupper(substr(trim($staff_name), 0, 1));

/* =========================
   CHECK TABLE
========================= */
if (!table_exists($conn, "activity_logs")) {
  die("The activity_logs table was not found. Please create it first.");
}

/* =========================
   FILTERS & PAGINATION SETUP
========================= */
$search = trim($_GET["search"] ?? "");
$moduleFilter = trim($_GET["module"] ?? "All");
$actionFilter = trim($_GET["action"] ?? "All");
$dateFrom = trim($_GET["date_from"] ?? "");
$dateTo = trim($_GET["date_to"] ?? "");
$dateRangeError = '';
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
  $dateRangeError = 'The start date cannot be later than the end date.';
  $dateTo = $dateFrom;
}

// Pagination logic: 5 items per page
$limit = 5;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

/* =========================
   SUMMARY COUNTS
========================= */
$totalLogs = 0;
$todayLogs = 0;
$programLogs = 0;

$res = $conn->query("SELECT COUNT(*) AS total FROM activity_logs WHERE staff_id = {$peso_staff_id}");
if ($res && ($row = $res->fetch_assoc())) $totalLogs = (int)($row["total"] ?? 0);

$res = $conn->query("SELECT COUNT(*) AS total FROM activity_logs WHERE staff_id = {$peso_staff_id} AND DATE(created_at) = CURDATE()");
if ($res && ($row = $res->fetch_assoc())) $todayLogs = (int)($row["total"] ?? 0);

$res = $conn->query("SELECT COUNT(*) AS total FROM activity_logs WHERE staff_id = {$peso_staff_id} AND LOWER(module_name) IN ('program', 'programs')");
if ($res && ($row = $res->fetch_assoc())) $programLogs = (int)($row["total"] ?? 0);

/* =========================
   DROPDOWN DATA
========================= */
$modules = [];
$actions = [];

$res = $conn->query("SELECT DISTINCT module_name FROM activity_logs WHERE staff_id = {$peso_staff_id} ORDER BY module_name ASC");
if ($res) { while ($row = $res->fetch_assoc()) { $modules[] = $row["module_name"]; } }

$res = $conn->query("SELECT DISTINCT action_type FROM activity_logs WHERE staff_id = {$peso_staff_id} ORDER BY action_type ASC");
if ($res) { while ($row = $res->fetch_assoc()) { $actions[] = $row["action_type"]; } }

/* =========================
   ACTIVITY LIST QUERY BUILDER
========================= */
$whereParts = ["staff_id = ?"];
$params = [$peso_staff_id];
$types = "i";

if ($search !== "") {
  $whereParts[] = "(module_name LIKE ? OR action_type LIKE ? OR target_name LIKE ? OR description LIKE ?)";
  $searchLike = "%" . $search . "%";
  $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike; $params[] = $searchLike;
  $types .= "ssss";
}

if ($moduleFilter !== "All") {
  $whereParts[] = "module_name = ?";
  $params[] = $moduleFilter;
  $types .= "s";
}

if ($actionFilter !== "All") {
  $whereParts[] = "action_type = ?";
  $params[] = $actionFilter;
  $types .= "s";
}

if ($dateFrom !== "") {
  $whereParts[] = "DATE(created_at) >= ?";
  $params[] = $dateFrom;
  $types .= "s";
}

if ($dateTo !== "") {
  $whereParts[] = "DATE(created_at) <= ?";
  $params[] = $dateTo;
  $types .= "s";
}

// 1. Get total records for pagination math
$totalFilteredLogs = 0;
$countSql = "SELECT COUNT(*) as total FROM activity_logs WHERE " . implode(" AND ", $whereParts);
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
  $countStmt->bind_param($types, ...$params);
  $countStmt->execute();
  $countRes = $countStmt->get_result()->fetch_assoc();
  $totalFilteredLogs = (int)($countRes['total'] ?? 0);
  $countStmt->close();
}
$totalPages = max(1, ceil($totalFilteredLogs / $limit));

// 2. Get actual records for this specific page
$logs = [];
$sql = "SELECT * FROM activity_logs WHERE " . implode(" AND ", $whereParts) . " ORDER BY created_at DESC, log_id DESC LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if ($stmt) {
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $logs[] = $row;
  }
  $stmt->close();
}
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
  
  <link rel="stylesheet" href="peso_staff_activity_log.css?v=20260905">
  <link rel="stylesheet" href="shared_sidebar.css">
  <link rel="stylesheet" href="activity_filter_polish.css?v=3">
  <script src="activity_filter_polish.js?v=3" defer></script>
<link rel="stylesheet" href="frontend_polish.css?v=20260921">
<link rel="stylesheet" href="peso_staff_responsive.css?v=23">
<link rel="stylesheet" href="system_search_polish.css?v=1">
<link rel="stylesheet" href="system_mobile.css?v=18">
<link rel="stylesheet" href="system_readability.css?v=1">
<script src="frontend_polish.js?v=20260925" defer></script>
</head>
<body class="staff-mobile-page staff-activity-page">

<div class="page-wrap">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <aside class="side-area" id="sideArea">
    <div class="side-top">
      <div class="side-brand">
        <img src="img/pesologo.png" alt="PESO Logo" class="side-logo">
        <div>
          <div class="side-title">BENEPESO</div>
          <div class="side-sub">PESO Staff Panel</div>
        </div>
      </div>
      <button class="side-close" id="sideClose" type="button" aria-label="Close menu"><span class="side-close-glyph" aria-hidden="true">&#215;</span></button>
    </div>

    <div class="side-user">
      <div class="user-pic-wrap">
        <img src="<?php echo h($pic_path); ?>" alt="Staff" class="user-img-side" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($staff_name); ?>&background=2f6b4f&color=fff';">
      </div>
      <div>
        <div class="user-name"><?php echo h($staff_name); ?></div>
        <div class="user-role"><?php echo h($staff_position); ?></div>
      </div>
    </div>

    <nav class="nav-area">
      <a href="peso_staff_dashboard.php" class="nav-item" onclick="window.location.href='peso_staff_dashboard.php'; return false;">
        <i class="ph ph-squares-four"></i> Dashboard
      </a>
      <a href="peso_staff_program.php" class="nav-item" onclick="window.location.href='peso_staff_program.php'; return false;">
        <i class="ph ph-briefcase"></i> Program
      </a>
      <a href="peso_staff_beneficiaries.php" class="nav-item" onclick="window.location.href='peso_staff_beneficiaries.php'; return false;">
        <i class="ph ph-users"></i> Beneficiaries
      </a>
      <a href="peso_staff_activity_log.php" class="nav-item active" onclick="window.location.href='peso_staff_activity_log.php'; return false;">
        <i class="ph ph-clock-counter-clockwise"></i> Activity Log
      </a>
      <form method="POST" action="logout.php" class="sidebar-logout-form"><?php echo auth_csrf_input(); ?><input type="hidden" name="role" value="peso_staff"><button type="submit" class="nav-item logout-item">
        <i class="ph ph-sign-out"></i> Logout
      </button></form>
    </nav>
  </aside>

  <main class="main-area">
    <header class="top-area animation-slide-up" style="animation-delay: 0.1s;">
      <div class="top-left">
        <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="sideArea">
          <span></span>
          <span></span>
          <span></span>
        </button>

        <div class="top-title">
          <div class="eyebrow">System Monitoring</div>
          <div class="top-big">PESO Staff Activity Log</div>
          <div class="top-sub">Track staff actions, program updates, and beneficiary records in one place.</div>
        </div>
      </div>

      <div class="top-actions">
        <div class="top-chip">
          <img src="<?php echo h($pic_path); ?>" alt="" class="chip-img" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($staff_name); ?>&background=2f6b4f&color=fff';">
          <?php echo h($staff_name); ?>
        </div>
      </div>
    </header>

    <section class="stats-grid">
      <div class="stat-card animation-slide-up" style="animation-delay: 0.2s;">
        <div class="stat-top">
          <div class="stat-label">Total Logs</div>
          <div class="stat-icon"><i class="ph-fill ph-stack"></i></div>
        </div>
        <div class="stat-value"><?php echo (int)$totalLogs; ?></div>
        <div class="stat-note">All activity recorded for your account.</div>
      </div>

      <div class="stat-card animation-slide-up" style="animation-delay: 0.3s;">
        <div class="stat-top">
          <div class="stat-label">Today</div>
          <div class="stat-icon"><i class="ph-fill ph-clock"></i></div>
        </div>
        <div class="stat-value"><?php echo (int)$todayLogs; ?></div>
        <div class="stat-note">Actions recorded today.</div>
      </div>

      <div class="stat-card animation-slide-up" style="animation-delay: 0.4s;">
        <div class="stat-top">
          <div class="stat-label">Program Logs</div>
          <div class="stat-icon"><i class="ph-fill ph-briefcase"></i></div>
        </div>
        <div class="stat-value"><?php echo (int)$programLogs; ?></div>
        <div class="stat-note">Actions involving programs.</div>
      </div>
    </section>

    <section class="panel-card animation-slide-up" style="animation-delay: 0.6s;">
      <div class="panel-head panel-head-stack">
        <div>
          <div class="panel-title">Activity Records</div>
          <div class="panel-sub"><?php echo number_format($totalFilteredLogs); ?> matching record<?php echo $totalFilteredLogs === 1 ? '' : 's'; ?> in your private activity history.</div>
        </div>

        <form method="GET" class="toolbar-form" id="filterForm">
          <input type="hidden" name="page" value="1"> 
          <div class="toolbar-grid">
            <div class="activity-search-wrap"><i class="ph ph-magnifying-glass search-input-icon"></i><input type="text" name="search" value="<?php echo h($search); ?>" class="toolbar-input" placeholder="Search module, action, target, description..."></div>

            <input type="hidden" name="module" value="<?php echo h($moduleFilter); ?>" data-filter-input="module">
            <div class="activity-filter-menu" data-filter-menu="module">
              <button type="button" class="activity-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="activity-trigger-label"><i class="ph ph-squares-four"></i><?php echo h($moduleFilter === 'All' ? 'All Modules' : activity_label($moduleFilter)); ?></span><i class="ph ph-caret-down"></i></button>
              <div class="activity-filter-options" role="listbox" aria-label="Filter by module" hidden>
                <button type="button" role="option" data-filter-value="All" aria-selected="<?php echo $moduleFilter === 'All' ? 'true' : 'false'; ?>"><span>All Modules</span><?php if($moduleFilter === 'All'): ?><i class="ph-bold ph-check"></i><?php endif; ?></button>
                <?php foreach ($modules as $module): ?><button type="button" role="option" data-filter-value="<?php echo h($module); ?>" aria-selected="<?php echo $moduleFilter === $module ? 'true' : 'false'; ?>"><span><?php echo h(activity_label($module)); ?></span><?php if($moduleFilter === $module): ?><i class="ph-bold ph-check"></i><?php endif; ?></button><?php endforeach; ?>
              </div>
            </div>

            <input type="hidden" name="action" value="<?php echo h($actionFilter); ?>" data-filter-input="action">
            <div class="activity-filter-menu" data-filter-menu="action">
              <button type="button" class="activity-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span class="activity-trigger-label"><i class="ph ph-lightning"></i><?php echo h($actionFilter === 'All' ? 'All Actions' : activity_label($actionFilter)); ?></span><i class="ph ph-caret-down"></i></button>
              <div class="activity-filter-options" role="listbox" aria-label="Filter by action" hidden>
                <button type="button" role="option" data-filter-value="All" aria-selected="<?php echo $actionFilter === 'All' ? 'true' : 'false'; ?>"><span>All Actions</span><?php if($actionFilter === 'All'): ?><i class="ph-bold ph-check"></i><?php endif; ?></button>
                <?php foreach ($actions as $action): ?><button type="button" role="option" data-filter-value="<?php echo h($action); ?>" aria-selected="<?php echo $actionFilter === $action ? 'true' : 'false'; ?>"><span><?php echo h($action); ?></span><?php if($actionFilter === $action): ?><i class="ph-bold ph-check"></i><?php endif; ?></button><?php endforeach; ?>
              </div>
            </div>

            <label class="activity-date-field"><span>From</span><input type="date" name="date_from" value="<?php echo h(format_date_input($dateFrom)); ?>" max="<?php echo h(format_date_input($dateTo)); ?>" class="toolbar-input auto-submit"></label>
            <label class="activity-date-field"><span>To</span><input type="date" name="date_to" value="<?php echo h(format_date_input($dateTo)); ?>" min="<?php echo h(format_date_input($dateFrom)); ?>" class="toolbar-input auto-submit"></label>
            <?php if($search !== '' || $moduleFilter !== 'All' || $actionFilter !== 'All' || $dateFrom !== '' || $dateTo !== ''): ?><a href="peso_staff_activity_log.php" class="btn-light activity-clear-btn"><i class="ph ph-x"></i><span>Clear</span></a><?php endif; ?>
          </div>
        </form>
      </div>
      <?php if($dateRangeError): ?><div class="activity-filter-alert"><i class="ph ph-warning-circle"></i><?php echo h($dateRangeError); ?></div><?php endif; ?>

      <?php if (!$logs): ?>
        <div class="empty-state">
          <div class="empty-title">No activity records found</div>
          <div class="empty-text">No matching logs were found for the selected filters.</div>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="activity-table">
            <thead>
              <tr>
                <th>Date & Time</th>
                <th class="text-center">Module</th>
                <th class="text-center">Action</th>
                <th>Target</th>
                <th>Description</th>
                </tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $log): ?>
                <tr class="activity-log-row" tabindex="0" data-date="<?php echo h(format_activity_datetime($log['created_at'] ?? '')); ?>" data-actor="<?php echo h($staff_name); ?>" data-role="PESO Staff" data-module="<?php echo h(activity_label($log['module_name'] ?? '')); ?>" data-action="<?php echo h(activity_label($log['action_type'] ?? '')); ?>" data-target="<?php echo h($log['target_name'] ?? 'Not specified'); ?>" data-description="<?php echo h($log['description'] ?? 'Not specified'); ?>">
                  <td class="datetime-col" data-label="Date &amp; time"><?php echo h(format_activity_datetime($log["created_at"] ?? "")); ?></td>
                  <td class="module-col text-center" data-label="Module"><?php echo h($log["module_name"] ?? "—"); ?></td>
                  
                  <!-- UPDATED TO PERFECTLY MATCH DASHBOARD DOT STYLE -->
                  <td class="text-center" data-label="Action">
                    <div style="display:flex; justify-content:center; align-items:center;">
                        <span class="pill" style="background: #f4f8f5; border: 1px solid var(--line); color: var(--text);">
                            <span class="pulse-dot <?php echo h(action_dot_class($log["action_type"] ?? "")); ?>"></span> 
                            <?php echo h($log["action_type"] ?? "—"); ?>
                        </span>
                    </div>
                  </td>

                  <td class="target-col" data-label="Target"><?php echo h($log["target_name"] ?? "—"); ?></td>
                  <td class="desc-col" data-label="Description"><?php echo h($log["description"] ?? "—"); ?></td>
                  </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="pagination-bar" style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; padding-top:20px; border-top:1px solid rgba(0,0,0,0.06); flex-wrap:wrap; gap:10px;">
            <div style="font-size:13px; color:var(--muted); font-weight:500;">
                Showing <?php echo $totalFilteredLogs > 0 ? $offset + 1 : 0; ?> to <?php echo min($offset + $limit, $totalFilteredLogs); ?> of <?php echo $totalFilteredLogs; ?> entries
            </div>
            <div style="display:flex; gap:8px;">
                <?php if($page > 1): ?>
                    <a href="?<?php echo h(build_query(['page' => $page - 1])); ?>" class="btn-light" style="display:inline-flex; align-items:center; justify-content:center; min-height:36px; padding:0 14px; font-size:13px; font-weight:600; background:#fff; border:1px solid rgba(0,0,0,0.1); border-radius:8px; color:var(--text); text-decoration:none;">Previous</a>
                <?php else: ?>
                    <button class="btn-light" disabled style="min-height:36px; padding:0 14px; font-size:13px; font-weight:600; background:#f9f9f9; border:1px solid rgba(0,0,0,0.05); border-radius:8px; color:#aaa; cursor:not-allowed;">Previous</button>
                <?php endif; ?>

                <?php if($page < $totalPages): ?>
                    <a href="?<?php echo h(build_query(['page' => $page + 1])); ?>" class="btn-light" style="display:inline-flex; align-items:center; justify-content:center; min-height:36px; padding:0 14px; font-size:13px; font-weight:600; background:#fff; border:1px solid rgba(0,0,0,0.1); border-radius:8px; color:var(--text); text-decoration:none;">Next</a>
                <?php else: ?>
                    <button class="btn-light" disabled style="min-height:36px; padding:0 14px; font-size:13px; font-weight:600; background:#f9f9f9; border:1px solid rgba(0,0,0,0.05); border-radius:8px; color:#aaa; cursor:not-allowed;">Next</button>
                <?php endif; ?>
            </div>
        </div>
      <?php endif; ?>
    </section>
  </main>
</div>

<div class="activity-detail-modal" id="activityDetailModal" aria-hidden="true">
  <div class="activity-detail-backdrop" data-close-activity-modal></div>
  <section class="activity-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="activityDetailTitle">
    <header class="activity-detail-head"><div class="activity-detail-icon"><i class="ph ph-clock-counter-clockwise"></i></div><div><div class="activity-detail-kicker">MY ACTIVITY</div><h2 id="activityDetailTitle">Activity details</h2></div><button type="button" class="activity-detail-close" data-close-activity-modal aria-label="Close details"><i class="ph ph-x"></i></button></header>
    <div class="activity-detail-body"><div class="activity-detail-grid"><div><span>Date and time</span><strong data-detail="date"></strong></div><div><span>Actor</span><strong data-detail="actor"></strong><small data-detail="role"></small></div><div><span>Module</span><strong data-detail="module"></strong></div><div><span>Action</span><strong data-detail="action"></strong></div><div class="activity-detail-wide"><span>Target</span><strong data-detail="target"></strong></div><div class="activity-detail-wide"><span>Description</span><p data-detail="description"></p></div></div></div>
    <footer class="activity-detail-footer"><button type="button" class="activity-detail-done" data-close-activity-modal>Done</button></footer>
  </section>
</div>

<script src="peso_staff_activity_log.js"></script>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    // Sidebar toggle
    const menuToggle = document.getElementById('menuToggle');
    const sideClose = document.getElementById('sideClose');
    const sideArea = document.getElementById('sideArea');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function openSidebar() {
      sideArea.classList.add('open');
      sidebarOverlay.classList.add('show');
      document.body.classList.add('sidebar-open');
      menuToggle?.setAttribute('aria-expanded', 'true');
      menuToggle?.setAttribute('aria-label', 'Close menu');
    }

    function closeSidebar() {
      sideArea.classList.remove('open');
      sidebarOverlay.classList.remove('show');
      document.body.classList.remove('sidebar-open');
      menuToggle?.setAttribute('aria-expanded', 'false');
      menuToggle?.setAttribute('aria-label', 'Open menu');
    }

    if (menuToggle) menuToggle.addEventListener('click', openSidebar);
    if (sideClose) sideClose.addEventListener('click', closeSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && sideArea?.classList.contains('open')) closeSidebar();
    });

    // Auto submit form
    document.querySelectorAll('.auto-submit').forEach(el => {
      el.addEventListener('change', () => {
        document.getElementById('filterForm').submit();
      });
    });

    const detailModal = document.getElementById('activityDetailModal');
    const closeDetail = () => { detailModal.classList.remove('show'); detailModal.setAttribute('aria-hidden', 'true'); };
    document.querySelectorAll('.activity-log-row').forEach(row => {
      const moduleCell = row.querySelector('.module-col');
      const targetCell = row.querySelector('.target-col');
      const descriptionCell = row.querySelector('.desc-col');
      const actionPill = row.querySelector('.pill');
      if (moduleCell) moduleCell.textContent = row.dataset.module || 'Not specified';
      if (targetCell) targetCell.textContent = row.dataset.target || 'Not specified';
      if (descriptionCell) descriptionCell.textContent = row.dataset.description || 'Not specified';
      if (actionPill) {
        const dot = actionPill.querySelector('.pulse-dot');
        actionPill.textContent = '';
        if (dot) actionPill.appendChild(dot);
        actionPill.appendChild(document.createTextNode(' ' + (row.dataset.action || 'Not specified')));
      }
      const openDetail = () => {
        ['date','actor','role','module','action','target','description'].forEach(key => {
          const node = detailModal.querySelector(`[data-detail="${key}"]`);
          if (node) node.textContent = row.dataset[key] || 'Not specified';
        });
        detailModal.classList.add('show'); detailModal.setAttribute('aria-hidden', 'false');
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
