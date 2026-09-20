<?php
require_once __DIR__ . '/env_loader.php';

$host = getenv("DB_HOST") ?: "localhost";
$user = getenv("DB_USER") ?: "root";
$pass = getenv("DB_PASSWORD") ?: "";
$db   = getenv("DB_NAME") ?: "benepeso";

try {
  $conn = new mysqli($host, $user, $pass, $db);
  if ($conn->connect_error) {
    throw new RuntimeException($conn->connect_error);
  }
  if (!$conn->set_charset("utf8mb4")) {
    throw new RuntimeException('Unable to configure the database character set.');
  }
} catch (Throwable $error) {
  error_log('BENEPESO database connection failed: ' . $error->getMessage());
  http_response_code(503);
  exit("The BENEPESO service is temporarily unavailable. Please try again later.");
}
?>
