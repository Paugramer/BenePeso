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
    $pic_path = "img/default_avatar.png";
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

$res = $conn->query("SELECT COUNT(*) AS total FROM activity_logs WHERE staff_id = {$peso_staff_id} AND module_name = 'Program'");
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
  
  <link rel="stylesheet" href="peso_staff_activity_log.css?v=<?php echo time(); ?>">
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
          <div class="side-sub">PESO Staff Panel</div>
        </div>
      </div>
      <button class="side-close" id="sideClose" type="button" aria-label="Close menu"><i class="ph ph-x"></i></button>
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
      <a href="logout.php?role=peso_staff" class="nav-item logout-item">
        <i class="ph ph-sign-out"></i> Logout
      </a>
    </nav>
  </aside>

  <main class="main-area">
    <header class="top-area animation-slide-up" style="animation-delay: 0.1s;">
      <div class="top-left">
        <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open menu">
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
        <div class="stat-note">All recorded activities</div>
      </div>

      <div class="stat-card animation-slide-up" style="animation-delay: 0.3s;">
        <div class="stat-top">
          <div class="stat-label">Today</div>
          <div class="stat-icon"><i class="ph-fill ph-clock"></i></div>
        </div>
        <div class="stat-value"><?php echo (int)$todayLogs; ?></div>
        <div class="stat-note">Actions made today</div>
      </div>

      <div class="stat-card animation-slide-up" style="animation-delay: 0.4s;">
        <div class="stat-top">
          <div class="stat-label">Program Logs</div>
          <div class="stat-icon"><i class="ph-fill ph-briefcase"></i></div>
        </div>
        <div class="stat-value"><?php echo (int)$programLogs; ?></div>
        <div class="stat-note">Program-related actions</div>
      </div>
    </section>

    <section class="panel-card animation-slide-up" style="animation-delay: 0.6s;">
      <div class="panel-head panel-head-stack">
        <div>
          <div class="panel-title">Activity Records</div>
          <div class="panel-sub">Search and filter the activity history.</div>
        </div>

        <form method="GET" class="toolbar-form" id="filterForm">
          <input type="hidden" name="page" value="1"> 
          <div class="toolbar-grid">
            <input
              type="text"
              name="search"
              value="<?php echo h($search); ?>"
              class="toolbar-input"
              placeholder="Search module, action, target, desc..."
            >

            <input type="hidden" name="module" value="<?php echo h($moduleFilter); ?>" data-filter-input="module">
            <div class="activity-filter-menu" data-filter-menu="module">
              <button type="button" class="activity-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span><?php echo h($moduleFilter === 'All' ? 'All Modules' : $moduleFilter); ?></span><i class="ph ph-caret-down"></i></button>
              <div class="activity-filter-options" role="listbox" aria-label="Filter by module" hidden>
                <button type="button" role="option" data-filter-value="All" aria-selected="<?php echo $moduleFilter === 'All' ? 'true' : 'false'; ?>"><span>All Modules</span><?php if($moduleFilter === 'All'): ?><i class="ph-bold ph-check"></i><?php endif; ?></button>
                <?php foreach ($modules as $module): ?><button type="button" role="option" data-filter-value="<?php echo h($module); ?>" aria-selected="<?php echo $moduleFilter === $module ? 'true' : 'false'; ?>"><span><?php echo h($module); ?></span><?php if($moduleFilter === $module): ?><i class="ph-bold ph-check"></i><?php endif; ?></button><?php endforeach; ?>
              </div>
            </div>

            <input type="hidden" name="action" value="<?php echo h($actionFilter); ?>" data-filter-input="action">
            <div class="activity-filter-menu" data-filter-menu="action">
              <button type="button" class="activity-filter-trigger" aria-haspopup="listbox" aria-expanded="false"><span><?php echo h($actionFilter === 'All' ? 'All Actions' : $actionFilter); ?></span><i class="ph ph-caret-down"></i></button>
              <div class="activity-filter-options" role="listbox" aria-label="Filter by action" hidden>
                <button type="button" role="option" data-filter-value="All" aria-selected="<?php echo $actionFilter === 'All' ? 'true' : 'false'; ?>"><span>All Actions</span><?php if($actionFilter === 'All'): ?><i class="ph-bold ph-check"></i><?php endif; ?></button>
                <?php foreach ($actions as $action): ?><button type="button" role="option" data-filter-value="<?php echo h($action); ?>" aria-selected="<?php echo $actionFilter === $action ? 'true' : 'false'; ?>"><span><?php echo h($action); ?></span><?php if($actionFilter === $action): ?><i class="ph-bold ph-check"></i><?php endif; ?></button><?php endforeach; ?>
              </div>
            </div>

            <input
              type="date"
              name="date_from"
              value="<?php echo h(format_date_input($dateFrom)); ?>"
              class="toolbar-input auto-submit"
            >

            <input
              type="date"
              name="date_to"
              value="<?php echo h(format_date_input($dateTo)); ?>"
              class="toolbar-input auto-submit"
            >
          </div>
        </form>
      </div>

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
                <tr>
                  <td class="datetime-col"><?php echo h(format_datetime_value($log["created_at"] ?? "")); ?></td>
                  <td class="module-col text-center"><?php echo h($log["module_name"] ?? "—"); ?></td>
                  
                  <!-- UPDATED TO PERFECTLY MATCH DASHBOARD DOT STYLE -->
                  <td class="text-center">
                    <div style="display:flex; justify-content:center; align-items:center;">
                        <span class="pill" style="background: #f4f8f5; border: 1px solid var(--line); color: var(--text);">
                            <span class="pulse-dot <?php echo h(action_dot_class($log["action_type"] ?? "")); ?>"></span> 
                            <?php echo h($log["action_type"] ?? "—"); ?>
                        </span>
                    </div>
                  </td>

                  <td class="target-col"><?php echo h($log["target_name"] ?? "—"); ?></td>
                  <td class="desc-col"><?php echo h($log["description"] ?? "—"); ?></td>
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
    }

    function closeSidebar() {
      sideArea.classList.remove('open');
      sidebarOverlay.classList.remove('show');
    }

    if (menuToggle) menuToggle.addEventListener('click', openSidebar);
    if (sideClose) sideClose.addEventListener('click', closeSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);

    // Auto submit form
    document.querySelectorAll('.auto-submit').forEach(el => {
      el.addEventListener('change', () => {
        document.getElementById('filterForm').submit();
      });
    });
  });
</script>

</body>
</html>
