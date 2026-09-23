<?php

require_once __DIR__ . '/google_auth_config.php';
require_once __DIR__ . '/activity_log_helper.php';

const GOOGLE_PENDING_REGISTRATION_TTL = 1800;
const GOOGLE_PROFILE_PICTURE_MAX_BYTES = 5242880;
const GOOGLE_PROFILE_PICTURE_MAX_DIMENSION = 4096;

/**
 * Return a Google-hosted HTTPS profile image URL, or an empty string.
 * Keeping this allow-list narrow prevents an ID-token claim from becoming an
 * arbitrary server-side request when a new beneficiary imports their photo.
 */
function google_auth_profile_picture_url(array $identity): string
{
    $url = trim((string)($identity['picture'] ?? ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    $port = isset($parts['port']) ? (int)$parts['port'] : 443;
    $hasCredentials = isset($parts['user']) || isset($parts['pass']);
    $isGoogleImageHost = $host === 'googleusercontent.com'
        || str_ends_with($host, '.googleusercontent.com');

    if ($scheme !== 'https' || $port !== 443 || $hasCredentials || !$isGoogleImageHost) {
        return '';
    }

    return $url;
}

/**
 * Download a verified Google profile image into a temporary file. The caller
 * owns the returned file and must move it or delete it.
 *
 * @return array{tmp_name:string, extension:string}|null
 */
function google_auth_download_profile_picture(array $identity): ?array
{
    $url = google_auth_profile_picture_url($identity);
    if ($url === '' || !function_exists('curl_init')) {
        return null;
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'benepeso_google_');
    if ($temporaryPath === false) {
        return null;
    }

    $handle = fopen($temporaryPath, 'wb');
    if ($handle === false) {
        @unlink($temporaryPath);
        return null;
    }

    $downloadedBytes = 0;
    $tooLarge = false;
    $curl = curl_init($url);
    if ($curl === false) {
        fclose($handle);
        @unlink($temporaryPath);
        return null;
    }
    $options = [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'BENEPESO-Google-Profile-Importer/1.0',
        CURLOPT_HTTPHEADER => ['Accept: image/jpeg, image/png, image/webp'],
        CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use ($handle, &$downloadedBytes, &$tooLarge): int {
            $chunkLength = strlen($chunk);
            if ($downloadedBytes + $chunkLength > GOOGLE_PROFILE_PICTURE_MAX_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $written = fwrite($handle, $chunk);
            if ($written === false) {
                return 0;
            }
            $downloadedBytes += $written;
            return $written;
        },
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($curl, $options);
    $completed = curl_exec($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    fclose($handle);

    if ($completed !== true || $tooLarge || $httpStatus !== 200 || $downloadedBytes < 1) {
        @unlink($temporaryPath);
        return null;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $dimensions = @getimagesize($temporaryPath);
    if (!isset($extensions[$mime])
        || !is_array($dimensions)
        || (int)$dimensions[0] < 1
        || (int)$dimensions[1] < 1
        || (int)$dimensions[0] > GOOGLE_PROFILE_PICTURE_MAX_DIMENSION
        || (int)$dimensions[1] > GOOGLE_PROFILE_PICTURE_MAX_DIMENSION) {
        @unlink($temporaryPath);
        return null;
    }

    return [
        'tmp_name' => $temporaryPath,
        'extension' => $extensions[$mime],
    ];
}

function google_auth_ensure_schema(mysqli $conn): bool
{
    return $conn->query(
        "CREATE TABLE IF NOT EXISTS user_auth_identities (
            identity_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            provider VARCHAR(32) NOT NULL,
            provider_subject VARCHAR(255) NOT NULL,
            provider_email VARCHAR(120) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at DATETIME DEFAULT NULL,
            PRIMARY KEY (identity_id),
            UNIQUE KEY uq_provider_subject (provider, provider_subject),
            UNIQUE KEY uq_provider_user (provider, user_id),
            KEY idx_identity_user (user_id),
            CONSTRAINT fk_identity_user FOREIGN KEY (user_id)
                REFERENCES users (user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    ) === true;
}

function google_auth_pending_identity(): ?array
{
    $pending = $_SESSION['google_pending_identity'] ?? null;
    if (!is_array($pending) || empty($pending['subject']) || empty($pending['email'])) {
        return null;
    }

    if ((int)($pending['created_at'] ?? 0) < time() - GOOGLE_PENDING_REGISTRATION_TTL) {
        unset($_SESSION['google_pending_identity']);
        return null;
    }

    return $pending;
}

function google_auth_link_user(mysqli $conn, int $userId, array $identity): bool
{
    if (!google_auth_ensure_schema($conn)) {
        return false;
    }

    $provider = 'google';
    $subject = (string)$identity['subject'];
    $email = mb_strtolower(trim((string)$identity['email']));
    $stmt = $conn->prepare(
        "INSERT INTO user_auth_identities
            (user_id, provider, provider_subject, provider_email, last_login_at)
         VALUES (?, ?, ?, ?, NOW())"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('isss', $userId, $provider, $subject, $email);
    try {
        $ok = $stmt->execute();
    } catch (mysqli_sql_exception $error) {
        $ok = false;
    }
    $stmt->close();
    if ($ok) {
        return true;
    }

    // A duplicate is accepted only when this exact provider identity is already
    // linked to this exact beneficiary. This prevents cross-account relinking.
    $existing = $conn->prepare(
        'SELECT identity_id FROM user_auth_identities
         WHERE provider = ? AND provider_subject = ? AND user_id = ? LIMIT 1'
    );
    if (!$existing) {
        return false;
    }
    $existing->bind_param('ssi', $provider, $subject, $userId);
    $existing->execute();
    $identityId = (int)($existing->get_result()->fetch_assoc()['identity_id'] ?? 0);
    $existing->close();
    if ($identityId < 1) {
        return false;
    }

    $touch = $conn->prepare(
        'UPDATE user_auth_identities SET provider_email = ?, last_login_at = NOW() WHERE identity_id = ?'
    );
    if (!$touch) {
        return false;
    }
    $touch->bind_param('si', $email, $identityId);
    $updated = $touch->execute();
    $touch->close();
    return $updated;
}

function google_auth_activate_beneficiary(mysqli $conn, array $user): void
{
    $userId = (int)$user['user_id'];
    $fullName = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);

    $_SESSION['fail_count'] = 0;
    $_SESSION['lock_until'] = 0;

    $historyStatus = 'success';
    $history = $conn->prepare('INSERT INTO login_history (user_id, status) VALUES (?, ?)');
    if ($history) {
        $history->bind_param('is', $userId, $historyStatus);
        $history->execute();
        $history->close();
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $fullName;
    $_SESSION['user_pic'] = $user['profile_pic'] ?? 'default_avatar.png';
    auth_activate_role('user');
    auth_regenerate_session();

    benepeso_log_user_activity(
        $conn,
        $userId,
        $fullName,
        'Auth',
        'LOGIN',
        'System',
        $fullName . ' logged in securely using Google.'
    );
}
