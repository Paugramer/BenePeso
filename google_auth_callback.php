<?php

require_once __DIR__ . '/auth_session.php';
require_once __DIR__ . '/auth_rate_limit.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/google_auth_helper.php';
require_once __DIR__ . '/google_id_token_verifier.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function google_auth_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    google_auth_response(405, ['ok' => false, 'message' => 'Method not allowed.']);
}

if (!auth_verify_csrf($_POST['csrf_token'] ?? null)) {
    google_auth_response(403, ['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
}

$credential = trim((string)($_POST['credential'] ?? ''));
$requestIp = auth_request_ip();
$retryAfter = auth_rate_limit_retry_after('google-login-ip', $requestIp, 20, 900, 900);
if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    google_auth_response(429, ['ok' => false, 'message' => 'Too many sign-in attempts. Please wait and try again.']);
}

if ($credential === '' || strlen($credential) > 10000) {
    auth_rate_limit_hit('google-login-ip', $requestIp, 20, 900, 900);
    google_auth_response(400, ['ok' => false, 'message' => 'Google did not return a valid sign-in response.']);
}

try {
    $payload = benepeso_verify_google_id_token($credential);
} catch (Throwable $error) {
    error_log('BENEPESO Google token verification error: ' . $error->getMessage());
    $payload = false;
}

if (!is_array($payload)
    || empty($payload['sub'])
    || empty($payload['email'])
    || empty($payload['email_verified'])
    || !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
    auth_rate_limit_hit('google-login-ip', $requestIp, 20, 900, 900);
    google_auth_response(401, ['ok' => false, 'message' => 'Google could not verify this account. Please try again.']);
}

$identity = [
    'subject' => (string)$payload['sub'],
    'email' => mb_strtolower(trim((string)$payload['email'])),
    'given_name' => trim((string)($payload['given_name'] ?? '')),
    'family_name' => trim((string)($payload['family_name'] ?? '')),
    'picture' => filter_var($payload['picture'] ?? '', FILTER_VALIDATE_URL) ? (string)$payload['picture'] : '',
    'created_at' => time(),
];

if (!google_auth_ensure_schema($conn)) {
    error_log('BENEPESO could not prepare the Google identity table: ' . $conn->error);
    google_auth_response(500, ['ok' => false, 'message' => 'Google sign-in is temporarily unavailable.']);
}

// Google sign-in is intentionally beneficiary-only. Privileged accounts keep
// their existing password-based sign-in and cannot be reached through this route.
foreach ([['admins', 'admin_id'], ['peso_staff', 'staff_id']] as [$table, $idColumn]) {
    $roleCheck = $conn->prepare("SELECT $idColumn FROM $table WHERE LOWER(email) = ? LIMIT 1");
    if ($roleCheck) {
        $roleCheck->bind_param('s', $identity['email']);
        $roleCheck->execute();
        $isPrivilegedEmail = $roleCheck->get_result()->num_rows > 0;
        $roleCheck->close();
        if ($isPrivilegedEmail) {
            google_auth_response(403, [
                'ok' => false,
                'message' => 'This email belongs to an administrator or PESO staff account. Google sign-in is available only to beneficiaries; please use your BENEPESO email and password.'
            ]);
        }
    }
}

$user = null;
$linkExistingBeneficiary = false;
$linked = $conn->prepare(
    "SELECT u.user_id, u.first_name, u.last_name, u.profile_pic, u.status
     FROM user_auth_identities i
     INNER JOIN users u ON u.user_id = i.user_id
     WHERE i.provider = 'google' AND i.provider_subject = ? LIMIT 1"
);
if ($linked) {
    $linked->bind_param('s', $identity['subject']);
    $linked->execute();
    $user = $linked->get_result()->fetch_assoc() ?: null;
    $linked->close();
}

if ($user === null) {
    $byEmail = $conn->prepare(
        'SELECT user_id, first_name, last_name, profile_pic, status FROM users WHERE LOWER(email) = ? LIMIT 1'
    );
    if ($byEmail) {
        $byEmail->bind_param('s', $identity['email']);
        $byEmail->execute();
        $user = $byEmail->get_result()->fetch_assoc() ?: null;
        $byEmail->close();
    }
    $linkExistingBeneficiary = $user !== null;
}

if ($user !== null) {
    if (strcasecmp((string)($user['status'] ?? ''), 'Banned') === 0) {
        google_auth_response(403, [
            'ok' => false,
            'reason' => 'restricted',
            'message' => 'This beneficiary account has been restricted by PESO Vinzons. Google sign-in cannot continue. Please contact the PESO office if you believe this is a mistake.'
        ]);
    }

    // Only link after checking the account restriction. Matching a verified
    // email adds Google as a second sign-in method; it never changes the
    // beneficiary's existing BENEPESO password.
    if ($linkExistingBeneficiary && !google_auth_link_user($conn, (int)$user['user_id'], $identity)) {
        google_auth_response(409, [
            'ok' => false,
            'message' => 'This Google account could not be linked safely. Your existing BENEPESO password was not changed. Please use your password or contact PESO.'
        ]);
    }

    auth_rate_limit_clear('google-login-ip', $requestIp);
    unset($_SESSION['google_pending_identity']);
    google_auth_activate_beneficiary($conn, $user);
    google_auth_response(200, [
        'ok' => true,
        'flow' => 'login',
        'redirect' => 'home.php',
        'message' => $linkExistingBeneficiary
            ? 'We found your existing beneficiary account with the same verified email and linked Google as an additional sign-in method. Your BENEPESO password remains unchanged, so either login method will continue to work.'
            : 'Your Google identity was verified securely. Welcome back to BENEPESO.'
    ]);
}

$_SESSION['google_pending_identity'] = $identity;
auth_rate_limit_clear('google-login-ip', $requestIp);
google_auth_response(200, [
    'ok' => true,
    'flow' => 'signup',
    'redirect' => 'signup.php?google=complete',
    'message' => 'Your Google account is verified. Complete your beneficiary profile to finish creating your BENEPESO account.'
]);
