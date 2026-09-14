<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

check_user_role('user');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile.php');
    exit();
}
auth_require_csrf();

function avatar_return(string $message): void
{
    $_SESSION['flash'] = $message;
    header('Location: profile.php');
    exit();
}

$upload = $_FILES['new_avatar'] ?? null;
if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    avatar_return('Profile picture update failed: Please select an image.');
}
if (($upload['size'] ?? 0) < 1 || $upload['size'] > 5 * 1024 * 1024) {
    avatar_return('Profile picture must be no larger than 5 MB.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($extensions[$mime]) || @getimagesize($upload['tmp_name']) === false) {
    avatar_return('Profile picture must be a valid JPG, PNG, or WebP image.');
}

$uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
    avatar_return('Profile picture storage is unavailable.');
}

$filename = 'user_' . (int)$_SESSION['user_id'] . '_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
$destination = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
if (!move_uploaded_file($upload['tmp_name'], $destination)) {
    avatar_return('Profile picture could not be saved. Please try again.');
}

$stmt = $conn->prepare('SELECT profile_pic FROM users WHERE user_id = ? LIMIT 1');
$userId = (int)$_SESSION['user_id'];
$stmt->bind_param('i', $userId);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare('UPDATE users SET profile_pic = ? WHERE user_id = ?');
$stmt->bind_param('si', $filename, $userId);
if (!$stmt->execute()) {
    $stmt->close();
    @unlink($destination);
    avatar_return('Profile picture could not be updated.');
}
$stmt->close();

$oldFilename = basename((string)($current['profile_pic'] ?? ''));
if ($oldFilename !== '' && !in_array($oldFilename, ['default_user.png', 'default_avatar.png'], true)) {
    $oldPath = $uploadDirectory . DIRECTORY_SEPARATOR . $oldFilename;
    if (is_file($oldPath) && realpath(dirname($oldPath)) === realpath($uploadDirectory)) {
        @unlink($oldPath);
    }
}

$_SESSION['user_pic'] = $filename;
avatar_return('Profile picture updated successfully.');
