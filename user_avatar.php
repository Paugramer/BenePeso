<?php

require_once __DIR__ . '/auth_session.php';
require_once __DIR__ . '/db.php';

if (($_SESSION['role'] ?? '') !== 'user' || empty($_SESSION['user_id'])) {
    http_response_code(404);
    exit;
}

$stmt = $conn->prepare('SELECT profile_pic FROM users WHERE user_id = ? LIMIT 1');
$userId = (int)$_SESSION['user_id'];
$stmt->bind_param('i', $userId);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

$filename = basename(trim((string)($profile['profile_pic'] ?? '')));
$uploadDirectory = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
$path = $uploadDirectory && $filename !== ''
    ? realpath($uploadDirectory . DIRECTORY_SEPARATOR . $filename)
    : false;

if (!$path || !is_file($path) || dirname($path) !== $uploadDirectory) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
