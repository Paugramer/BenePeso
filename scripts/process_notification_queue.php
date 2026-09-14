<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
chdir($root);
require $root . '/db.php';
require $root . '/email_helper.php';

$table = $conn->query("SHOW TABLES LIKE 'notification_outbox'");
if (!$table || $table->num_rows === 0) {
    echo "Processed=0 Sent=0 Failed=0\n";
    exit;
}

$lock = $conn->query("SELECT GET_LOCK('benepeso_notification_worker', 0) AS acquired")->fetch_assoc();
if ((int)($lock['acquired'] ?? 0) !== 1) {
    echo "Another notification worker is already running.\n";
    exit;
}

$limit = max(1, min(100, (int)($argv[1] ?? 25)));
$result = $conn->query("SELECT * FROM notification_outbox WHERE attempts < 10 ORDER BY created_at ASC LIMIT {$limit}");
$processed = $sent = $failed = 0;
while ($job = $result->fetch_assoc()) {
    $processed++;
    $error = null;
    $ok = sendBENEPESOStatusEmail($conn, (int)$job['beneficiary_id'], (string)$job['status_name'], $error, (string)$job['custom_message'], (string)$job['schedule_date'], (string)$job['schedule_place']);
    $id = (int)$job['notification_id'];
    if ($ok) {
        $stmt = $conn->prepare('DELETE FROM notification_outbox WHERE notification_id=?');
        $stmt->bind_param('i', $id);
        $sent++;
    } else {
        $stmt = $conn->prepare('UPDATE notification_outbox SET attempts=attempts+1,last_error=? WHERE notification_id=?');
        $error = substr((string)$error, 0, 500);
        $stmt->bind_param('si', $error, $id);
        $failed++;
    }
    $stmt->execute();
    $stmt->close();
}
echo "Processed={$processed} Sent={$sent} Failed={$failed}\n";
$conn->query("SELECT RELEASE_LOCK('benepeso_notification_worker')");
