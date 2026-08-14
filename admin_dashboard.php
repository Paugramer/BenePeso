<?php
require "auth.php"; // This handles the session_start and role routing
require "db.php";

// Protect this page for admins only using our new function
check_user_role("admin");

if (!isset($conn) || !($conn instanceof mysqli)) {
  die("Database connection not found. Please check db.php");
}

// Redirect unauthorized users to the MAIN unified login
if (!isset($_SESSION["admin_id"])) {
  header("Location: login.php");
  exit();
}

$admin_id = (int) $_SESSION["admin_id"]; 

/* =========================
   HELPERS & TIME AGO
========================= */
function h($value){
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

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

function table_exists(mysqli $conn, string $table): bool {
  $table = $conn->real_escape_string($table);
  $sql = "SHOW TABLES LIKE '$table'";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

function get_columns(mysqli $conn, string $table): array {
  $cols = [];
  if (!table_exists($conn, $table)) return $cols;
  $tableSafe = "`" . str_replace("`", "``", $table) . "`";
  $res = $conn->query("SHOW COLUMNS FROM $tableSafe");
  if ($res) {
    while ($row = $res->fetch_assoc()) {
      $cols[] = $row["Field"];
    }
  }
  return $cols;
}

function first_existing_column(array $columns, array $choices): ?string {
  foreach ($choices as $choice) {
    if (in_array($choice, $columns, true)) {
      return $choice;
    }
  }
  return null;
}

// ADMIN VERSION: No longer filters by created_by. Admins see all records.
function get_count(mysqli $conn, string $table): int {
  if (!table_exists($conn, $table)) return 0;
  $tableSafe = "`" . str_replace("`", "``", $table) . "`";
  $sql = "SELECT COUNT(*) AS total FROM $tableSafe";
  $res = $conn->query($sql);
  if ($res && ($row = $res->fetch_assoc())) { return (int)$row["total"]; }
  return 0;
}

function get_unique_beneficiary_count(mysqli $conn): int {
  if (!table_exists($conn, 'beneficiaries')) return 0;
  $result = $conn->query("SELECT COUNT(DISTINCT CASE
      WHEN user_id IS NOT NULL AND user_id > 0 THEN CONCAT('user:', user_id)
      WHEN TRIM(COALESCE(email, '')) <> '' THEN CONCAT('email:', LOWER(TRIM(email)))
      WHEN TRIM(COALESCE(full_name, '')) <> '' THEN CONCAT('person:', LOWER(TRIM(full_name)), '|', COALESCE(DATE_FORMAT(birthdate, '%Y-%m-%d'), ''), '|', LOWER(TRIM(COALESCE(barangay, ''))))
      ELSE CONCAT('record:', beneficiary_id) END) AS total FROM beneficiaries");
  $row = $result ? $result->fetch_assoc() : null;
  return (int)($row['total'] ?? 0);
}

// ADMIN VERSION: Admins see all records, no staff_id filter.
function get_count_by_status(mysqli $conn, string $table, array $possibleStatusColumns, array $statusValues): int {
  if (!table_exists($conn, $table)) return 0;
  $columns = get_columns($conn, $table);
  $statusCol = first_existing_column($columns, $possibleStatusColumns);
  if (!$statusCol) return 0;
  
  $tableSafe = "`" . str_replace("`", "``", $table) . "`";
  $statusSafe = "`" . str_replace("`", "``", $statusCol) . "`";
  $escapedValues = array_map(function($value) use ($conn){ return "'" . $conn->real_escape_string($value) . "'"; }, $statusValues);
  $in = implode(",", $escapedValues);
  
  $sql = "SELECT COUNT(*) AS total FROM $tableSafe WHERE $statusSafe IN ($in)";
  $res = $conn->query($sql);
  if ($res && ($row = $res->fetch_assoc())) { return (int)$row["total"]; }
  return 0;
}

function format_date_value($value): string {
  if (!$value) return "—";
  $ts = strtotime((string)$value);
  return $ts ? date("M d, Y", $ts) : (string)$value;
}

/* =========================
   ADMIN INFO
========================= */
$admin_name = "System Administrator";
$admin_position = "Admin";
$admin_pic = "default_avatar.png";

// FIXED QUERY: Only selecting 'email' since first_name, last_name, etc., are not in the db schema.
if (table_exists($conn, "admins")) {
  $stmt = $conn->prepare("SELECT email FROM admins WHERE admin_id = ? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
      // Use the part of the email before the @ as a display name
      $email_parts = explode('@', $row["email"]);
      $admin_name = ucfirst($email_parts[0]); 
      $admin_position = "Administrator";
    }
    $stmt->close();
  }
}

if (empty(trim($admin_name))) { $admin_name = "System Administrator"; }
$pic_path = "uploads/admin_pics/" . $admin_pic; 
if (!file_exists($pic_path) || empty($admin_pic)) { $pic_path = "img/default_avatar.png"; }

/* =========================
   COUNTS (SYSTEM WIDE)
========================= */
$totalPrograms = get_count($conn, "programs");
$totalBeneficiaryRecords = get_count($conn, "beneficiaries");
$totalBeneficiaries = get_unique_beneficiary_count($conn);
$activePrograms = get_count_by_status($conn, "programs", ["status", "program_status"], ["Active", "Ongoing", "Open", "Running"]);
$pendingReviews = get_count_by_status($conn, "beneficiaries", ["status", "approval_status", "review_status"], ["Pending", "For Review", "Review"]);

if ($pendingReviews === 0) {
  $pendingReviews = get_count_by_status($conn, "programs", ["status", "approval_status"], ["Pending", "For Review", "Review"]);
}

/* Live dashboard charts: registrations by month and current review status. */
$chartLabels = [];
$chartValues = [];
$chartStart = new DateTime('first day of -11 months');
$monthlyTotals = [];
if (table_exists($conn, "beneficiaries")) {
  $beneficiaryColumns = get_columns($conn, "beneficiaries");
  if (in_array('created_at', $beneficiaryColumns, true)) {
    $chartStartSql = $chartStart->format('Y-m-01 00:00:00');
    $stmt = $conn->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS total
                            FROM beneficiaries
                            WHERE created_at >= ?
                            GROUP BY month_key
                            ORDER BY month_key");
    if ($stmt) {
      $stmt->bind_param("s", $chartStartSql);
      $stmt->execute();
      $result = $stmt->get_result();
      while ($row = $result->fetch_assoc()) {
        $monthlyTotals[$row['month_key']] = (int)$row['total'];
      }
      $stmt->close();
    }
  }
}
for ($i = 0; $i < 12; $i++) {
  $month = clone $chartStart;
  $month->modify("+$i months");
  $key = $month->format('Y-m');
  $chartLabels[] = $month->format('M');
  $chartValues[] = $monthlyTotals[$key] ?? 0;
}

$statusLabels = [];
$statusValues = [];
if (table_exists($conn, "beneficiaries")) {
  $beneficiaryColumns = $beneficiaryColumns ?? get_columns($conn, "beneficiaries");
  $statusColumn = first_existing_column($beneficiaryColumns, ['approval_status', 'status', 'review_status']);
  if ($statusColumn) {
    $safeStatusColumn = "`" . str_replace("`", "``", $statusColumn) . "`";
    $result = $conn->query("SELECT COALESCE(NULLIF(TRIM($safeStatusColumn), ''), 'Unspecified') AS status_label,
                                   COUNT(*) AS total
                            FROM beneficiaries
                            GROUP BY status_label
                            ORDER BY total DESC
                            LIMIT 6");
    if ($result) {
      while ($row = $result->fetch_assoc()) {
        $statusLabels[] = (string)$row['status_label'];
        $statusValues[] = (int)$row['total'];
      }
    }
  }
}

/* =========================
   RECENT PROGRAMS (SYSTEM WIDE)
========================= */
$recentPrograms = [];

if (table_exists($conn, "programs")) {
  $programCols = get_columns($conn, "programs");
  $nameCol   = first_existing_column($programCols, ["program_name", "name", "title"]);
  $statusCol = first_existing_column($programCols, ["status", "program_status"]);
  $approvalCol = first_existing_column($programCols, ["approval_status"]);
  $slotsCol  = first_existing_column($programCols, ["slots", "available_slots", "total_slots"]);
  $dateCol   = first_existing_column($programCols, ["updated_at", "created_at", "date_created"]);

  if ($nameCol) {
    $select = [];
    $select[] = "`$nameCol` AS program_name";
    $select[] = $statusCol ? "`$statusCol` AS status" : "'' AS status";
    $select[] = $approvalCol ? "`$approvalCol` AS approval_status" : "'' AS approval_status";
    $select[] = $slotsCol ? "`$slotsCol` AS slots" : "0 AS slots";
    $select[] = $dateCol ? "`$dateCol` AS updated_value" : "NULL AS updated_value";

    $orderBy = $dateCol ? "`$dateCol` DESC" : "`$nameCol` ASC";

    // No WHERE clause here so Admins see everything
    $sql = "SELECT " . implode(", ", $select) . " FROM `programs` ORDER BY $orderBy LIMIT 5";
    $res = $conn->query($sql);

    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $raw_status = trim($row["status"] ?? '');
        $approval = trim($row["approval_status"] ?? '');
        
        // Smart Check: Prioritize Admin Approval status first
        if (strtolower($approval) === 'pending') {
            $final_status = 'Pending Approval';
        } elseif (!empty($raw_status)) {
            $final_status = $raw_status;
        } else {
            $final_status = 'Pending'; 
        }

        $recentPrograms[] = [
          "name"    => $row["program_name"] ?? "Untitled Program",
          "status"  => $final_status,
          "slots"   => $row["slots"] ?? 0,
          "updated" => format_date_value($row["updated_value"] ?? null),
        ];
      }
    }
  }
}

/* =========================
   RECENT SYSTEM ACTIVITY 
========================= */
$recentActivities = [];

if (table_exists($conn, "activity_logs")) {
  // Admins see all non-auth activities system-wide
  $sql = "SELECT action_type AS action_title, 
                 description AS action_desc, 
                 created_at AS action_time 
          FROM activity_logs 
          WHERE action_type NOT LIKE '%Log%' AND action_type NOT LIKE '%Auth%'
          ORDER BY created_at DESC 
          LIMIT 5";
          
  $stmt = $conn->prepare($sql);
  
  if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    
    while ($row = $res->fetch_assoc()) {
      $recentActivities[] = [
        "title" => $row["action_title"] ?: "System Update",
        "desc"  => $row["action_desc"] ?: "Activity recorded",
        "time"  => time_ago($row["action_time"] ?? null) 
      ];
    }
    $stmt->close();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="img/pesologo.png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BENEPESO | Admin Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="admin_dashboard.css">
  <link rel="stylesheet" href="shared_sidebar.css">
  <link rel="stylesheet" href="dashboard_polish.css?v=2">
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
        <img src="<?php echo h($pic_path); ?>" alt="Admin" class="user-img-side" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin_name); ?>&background=2f6b4f&color=fff';">
      </div>
      <div>
        <div class="user-name"><?php echo h($admin_name); ?></div>
        <div class="user-role"><?php echo h($admin_position); ?></div>
      </div>
    </div>

    <nav class="nav-area">
      <a href="admin_dashboard.php" class="nav-item active"><i class="ph ph-squares-four"></i> Dashboard</a>
      <a href="admin_program.php" class="nav-item"><i class="ph ph-briefcase"></i> Programs</a>
      <a href="admin_beneficiaries.php" class="nav-item"><i class="ph ph-users"></i> Beneficiaries</a>
      <a href="admin_accounts.php" class="nav-item"><i class="ph ph-user-circle-gear"></i> Manage Accounts</a>
      <a href="admin_activity_log.php" class="nav-item"><i class="ph ph-clock-counter-clockwise"></i> System Logs</a>
      <a href="logout.php?role=admin" class="nav-item logout-item"><i class="ph ph-sign-out"></i> Logout</a>
    </nav>
  </aside>

  <main class="main-area">
    <header class="top-area animate-fade-in">
      <div class="top-left">
        <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open menu">
          <span></span><span></span><span></span>
        </button>

        <div class="top-title">
          <div class="eyebrow">Admin Overview</div>
          <div class="top-big">System Administrator</div>
          <div class="top-sub">Manage all programs, beneficiaries, and staff activities.</div>
        </div>
      </div>

      <div class="top-actions">
        <a href="admin_program.php" class="btn-soft"><i class="ph ph-plus-circle" style="font-size: 1.1rem;"></i> Programs</a>
        <div class="top-chip">
          <img src="<?php echo h($pic_path); ?>" alt="" class="chip-img" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin_name); ?>&background=2f6b4f&color=fff';">
          <?php echo h($admin_name); ?>
        </div>
      </div>
    </header>

    <section class="stats-grid">
      <div class="stat-card animate-fade-in" style="animation-delay: 0.1s;">
        <div class="stat-top">
          <div class="stat-label">Total Programs</div>
          <div class="stat-icon"><i class="ph-fill ph-briefcase"></i></div>
        </div>
        <div class="stat-value"><?php echo (int)$totalPrograms; ?></div>
        <div class="stat-trend trend-neutral"><i class="ph-bold ph-database"></i> System wide</div>
        <div class="stat-note">All recorded PESO programs</div>
      </div>

      <div class="stat-card animate-fade-in" style="animation-delay: 0.2s;">
        <div class="stat-top">
          <div class="stat-label">Active Programs</div>
          <div class="stat-icon"><i class="ph-fill ph-check-circle"></i></div>
        </div>
        <div class="stat-value"><?php echo (int)$activePrograms; ?></div>
        <div class="stat-trend trend-neutral"><i class="ph-bold ph-activity"></i> Live programs</div>
        <div class="stat-note">Currently running programs</div>
      </div>

      <a href="admin_beneficiaries.php" class="stat-card stat-card-link animate-fade-in" style="animation-delay: 0.3s;" aria-label="Open beneficiary records">
        <div class="stat-top">
          <div class="stat-label">Unique Beneficiaries</div>
          <div class="stat-icon"><i class="ph-fill ph-users-three"></i></div>
        </div>
        <div class="stat-value"><?php echo (int)$totalBeneficiaries; ?></div>
        <div class="stat-trend trend-neutral"><i class="ph-bold ph-files"></i> <?php echo (int)$totalBeneficiaryRecords; ?> program records</div>
        <div class="stat-note">Each person is counted once across programs</div>
      </a>

      <div class="stat-card animate-fade-in" style="animation-delay: 0.4s;">
        <div class="stat-top">
          <div class="stat-label">Pending Reviews</div>
          <div class="stat-icon"><i class="ph-fill ph-clock-countdown"></i></div>
        </div>
        <div class="stat-value"><?php echo (int)$pendingReviews; ?></div>
        <div class="stat-trend trend-down"><i class="ph-bold ph-trend-down"></i> Action Needed</div>
        <div class="stat-note">Require admin attention</div>
      </div>
    </section>

    <section class="chart-section animate-fade-in" style="animation-delay: 0.5s;" aria-label="Dashboard analytics">
      <div class="dashboard-chart-grid">
      <div class="panel-card dashboard-chart-card">
        <div class="panel-head">
          <div>
            <div class="panel-title">Registration Trend</div>
            <div class="panel-sub" id="trendDescription">New beneficiary records during the latest six months</div>
          </div>
          <div class="chart-period-menu" data-chart-period>
            <button type="button" class="chart-period-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="chartPeriodOptions">
              <i class="ph ph-calendar-blank" aria-hidden="true"></i>
              <span data-period-label>6 months</span>
              <i class="ph ph-caret-down chart-period-caret" aria-hidden="true"></i>
            </button>
            <div class="chart-period-options" id="chartPeriodOptions" role="listbox" aria-label="Chart period" hidden>
              <button type="button" role="option" data-months="1" aria-selected="false">1 month</button>
              <button type="button" role="option" data-months="3" aria-selected="false">3 months</button>
              <button type="button" role="option" data-months="6" aria-selected="true">6 months<i class="ph-bold ph-check"></i></button>
              <button type="button" role="option" data-months="12" aria-selected="false">12 months</button>
            </div>
          </div>
        </div>
        <div class="dashboard-chart-canvas dashboard-chart-canvas--line">
          <canvas id="overviewChart" role="img" aria-label="Monthly beneficiary registration trend"></canvas>
        </div>
      </div>
      <div class="panel-card dashboard-chart-card dashboard-chart-card--status">
        <div class="panel-head">
          <div>
            <div class="panel-title">Review Status</div>
            <div class="panel-sub">Current beneficiary records grouped by review status</div>
          </div>
          <a href="admin_beneficiaries.php" class="panel-link">Review</a>
        </div>
        <div class="dashboard-chart-canvas dashboard-chart-canvas--donut">
          <?php if (array_sum($statusValues) > 0): ?>
            <canvas id="statusChart" role="img" aria-label="Beneficiary review status distribution"></canvas>
          <?php else: ?>
            <div class="chart-empty"><i class="ph ph-chart-donut"></i><span>No status data yet</span></div>
          <?php endif; ?>
        </div>
      </div>
      </div>
    </section>

    <section class="split-grid">
      <div class="panel-card animate-fade-in" style="animation-delay: 0.6s; padding-bottom: 20px;">
        <div class="panel-head">
          <div>
            <div class="panel-title">All Recent Programs</div>
            <div class="panel-sub">Latest records from the global programs table</div>
          </div>
          <a href="admin_program.php" class="panel-link">View all</a>
        </div>

        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Program Name</th>
                <th>Capacity</th>
                <th class="col-status">Status</th>
                <th class="col-action">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentPrograms)): ?>
                <tr>
                  <td colspan="4" style="text-align:center; padding: 40px;">
                    <div class="empty-state" style="border: none; box-shadow: none;">
                        <i class="ph ph-folder-open empty-icon" style="font-size: 48px;"></i>
                        <h4 style="font-size: 16px;">No programs recorded yet.</h4>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentPrograms as $program): 
                    $status_str = strtolower(trim($program["status"]));
                    
                    if (in_array($status_str, ['ongoing', 'active', 'open', 'running'])) { 
                        $status_class = 'pill success'; 
                        $dot_class = 'ongoing'; 
                    } elseif (in_array($status_str, ['completed', 'finished', 'closed'])) { 
                        $status_class = 'pill neutral'; 
                        $dot_class = 'completed'; 
                    } elseif (in_array($status_str, ['pending', 'for review', 'review', 'pending approval'])) { 
                        $status_class = 'pill pending'; 
                        $dot_class = 'pending'; 
                    } else { 
                        $status_class = 'pill warning'; 
                        $dot_class = 'upcoming'; 
                    }
                ?>
                  <tr class="table-row-animate">
                    <td>
                        <div class="row-title" style="font-weight: 800; color: var(--green-dark); font-size: 15px; transition: 0.2s;"><?php echo h($program["name"]); ?></div>
                        <div style="font-size: 12px; color: var(--muted); margin-top: 4px;"><i class="ph ph-clock"></i> Updated <?php echo h($program["updated"]); ?></div>
                    </td>
                    <td>
                        <div style="font-size: 13px; font-weight: 600;">
                            <span style="color:var(--green-dark); font-weight:800; font-size:14px;"><?php echo h($program["slots"]); ?></span> Total Slots
                        </div>
                    </td>
                    <td class="col-status">
                        <div style="display:flex; justify-content:center; align-items:center;">
                            <span class="<?php echo $status_class; ?>" style="background: transparent; border: 1px solid var(--line); color: var(--text);">
                                <span class="pulse-dot <?php echo $dot_class; ?>"></span> <?php echo h($program["status"]); ?>
                            </span>
                        </div>
                    </td>
                    <td class="col-action">
                        <a href="admin_program.php?program=<?php echo urlencode($program["name"]); ?>" class="btn-action-view">
                            <i class="ph ph-folder-open"></i> Open
                        </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel-card animate-fade-in" style="animation-delay: 0.7s;">
        <div class="panel-head">
          <div>
            <div class="panel-title">System Activity</div>
            <div class="panel-sub">Recent actions across all staff</div>
          </div>
          <a href="admin_activity_log.php" class="panel-link">Open log</a>
        </div>

        <div class="log-list">
          <?php if (empty($recentActivities)): ?>
             <div class="empty-state" style="padding: 40px 20px; border: none; box-shadow: none;">
                <i class="ph ph-clock-counter-clockwise empty-icon" style="font-size: 40px; margin-bottom: 10px;"></i>
                <div class="empty-text" style="margin-top:0;">No recent system activities found.</div>
             </div>
          <?php else: ?>
            <?php foreach ($recentActivities as $activity): ?>
              <div class="log-item">
                <div class="log-left">
                  <div class="log-dot"></div>
                  <div>
                    <div class="log-action"><?php echo h($activity["title"]); ?></div>
                    <div class="log-by"><?php echo h($activity["desc"]); ?></div>
                  </div>
                </div>
                <div class="log-time"><?php echo h($activity["time"]); ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </main>
</div>

<script>
// Sidebar Toggle Logic
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

// Chart JS
document.addEventListener('DOMContentLoaded', function() {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.querySelectorAll('.stat-value').forEach((element) => {
        const target = Number.parseInt(element.textContent.trim(), 10);
        if (reduceMotion || Number.isNaN(target)) return;
        const duration = 750;
        const startedAt = performance.now();
        element.textContent = '0';
        const updateCount = (now) => {
            const progress = Math.min((now - startedAt) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            element.textContent = Math.round(target * eased).toLocaleString();
            if (progress < 1) requestAnimationFrame(updateCount);
        };
        requestAnimationFrame(updateCount);
    });

    document.querySelectorAll('.table-row-animate, .log-item').forEach((element, index) => {
        element.classList.add('dashboard-item-enter');
        element.style.animationDelay = `${Math.min(index * 55, 330)}ms`;
    });

    const canvas = document.getElementById('overviewChart');
    if(!canvas || typeof Chart === 'undefined') return;
    const ctx = canvas.getContext('2d');
    
    let gradient = ctx.createLinearGradient(0, 0, 0, 400);
    gradient.addColorStop(0, 'rgba(31, 122, 84, 0.5)'); 
    gradient.addColorStop(1, 'rgba(31, 122, 84, 0)');

    const allChartLabels = <?php echo json_encode($chartLabels); ?>;
    const allChartValues = <?php echo json_encode($chartValues); ?>;

    const overviewChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: allChartLabels.slice(-6),
            datasets: [{
                label: 'New Beneficiaries',
                data: allChartValues.slice(-6),
                backgroundColor: gradient,
                borderColor: '#1f7a54', 
                borderWidth: 3,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#1f7a54',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4 
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: reduceMotion ? 0 : 900, easing: 'easeOutQuart' },
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#112a1e',
                    titleFont: { family: 'Poppins', size: 13 },
                    bodyFont: { family: 'Poppins', size: 14, weight: 'bold' },
                    padding: 12,
                    cornerRadius: 8,
                    displayColors: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(31, 122, 84, 0.07)', drawBorder: false },
                    border: { display: false },
                    ticks: { font: { family: 'Poppins', size: 11 }, color: '#5c7365' }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    border: { display: false },
                    ticks: { font: { family: 'Poppins', size: 11 }, color: '#5c7365' }
                }
            }
        }
    });

    const periodMenu = document.querySelector('[data-chart-period]');
    const trendDescription = document.getElementById('trendDescription');
    if (periodMenu) {
      const periodTrigger = periodMenu.querySelector('.chart-period-trigger');
      const periodOptions = periodMenu.querySelector('.chart-period-options');
      const periodButtons = Array.from(periodOptions.querySelectorAll('[data-months]'));
      const periodLabel = periodMenu.querySelector('[data-period-label]');

      const closePeriodMenu = (restoreFocus = false) => {
        periodOptions.hidden = true;
        periodTrigger.setAttribute('aria-expanded', 'false');
        periodMenu.classList.remove('is-open');
        if (restoreFocus) periodTrigger.focus();
      };

      const updatePeriod = (button) => {
        const months = Number.parseInt(button.dataset.months, 10) || 6;
        overviewChart.data.labels = allChartLabels.slice(-months);
        overviewChart.data.datasets[0].data = allChartValues.slice(-months);
        overviewChart.update();
        periodLabel.textContent = button.textContent.trim();
        periodButtons.forEach((option) => {
          const selected = option === button;
          option.setAttribute('aria-selected', selected ? 'true' : 'false');
          const oldCheck = option.querySelector('.ph-check');
          if (oldCheck) oldCheck.remove();
          if (selected) {
            const check = document.createElement('i');
            check.className = 'ph-bold ph-check';
            check.setAttribute('aria-hidden', 'true');
            option.appendChild(check);
          }
        });
        if (trendDescription) {
          const periodText = months === 1 ? 'the latest month' : `the latest ${months} months`;
          trendDescription.textContent = `New beneficiary records during ${periodText}`;
        }
        closePeriodMenu(true);
      };

      periodTrigger.addEventListener('click', () => {
        const willOpen = periodOptions.hidden;
        periodOptions.hidden = !willOpen;
        periodTrigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        periodMenu.classList.toggle('is-open', willOpen);
        if (willOpen) (periodButtons.find((item) => item.getAttribute('aria-selected') === 'true') || periodButtons[0]).focus();
      });
      periodButtons.forEach((button) => button.addEventListener('click', () => updatePeriod(button)));
      periodOptions.addEventListener('keydown', (event) => {
        const index = periodButtons.indexOf(document.activeElement);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          const direction = event.key === 'ArrowDown' ? 1 : -1;
          periodButtons[(index + direction + periodButtons.length) % periodButtons.length].focus();
        } else if (event.key === 'Escape') {
          event.preventDefault();
          closePeriodMenu(true);
        }
      });
      document.addEventListener('click', (event) => {
        if (!periodMenu.contains(event.target)) closePeriodMenu();
      });
    }

    const statusCanvas = document.getElementById('statusChart');
    if (statusCanvas) {
      new Chart(statusCanvas.getContext('2d'), {
        type: 'doughnut',
        data: {
          labels: <?php echo json_encode($statusLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
          datasets: [{
            data: <?php echo json_encode($statusValues); ?>,
            backgroundColor: ['#1f7a54', '#f0a202', '#3178c6', '#7a5af8', '#d64545', '#7a8b82'],
            borderColor: '#ffffff',
            borderWidth: 3,
            hoverOffset: 5
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '67%',
          animation: { duration: reduceMotion ? 0 : 850 },
          plugins: {
            legend: {
              position: 'bottom',
              labels: { boxWidth: 10, boxHeight: 10, padding: 14, usePointStyle: true, font: { family: 'Poppins', size: 11, weight: '600' } }
            },
            tooltip: { backgroundColor: '#112a1e', padding: 12, cornerRadius: 8 }
          }
        }
      });
    }
});
</script>
</body>
</html>
