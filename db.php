<?php
$host = getenv("DB_HOST") ?: "localhost";
$user = getenv("DB_USER") ?: "root";
$pass = getenv("DB_PASSWORD") ?: "";
$db   = getenv("DB_NAME") ?: "benepeso";

$conn = new mysqli($host, $user, $pass, $db);

/* FIX TEXT ENCODING */
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
  die("Database connection failed: " . $conn->connect_error);
}
?>
