<?php

require_once __DIR__ . '/env_loader.php';

function benepeso_turnstile_site_key(): string
{
    return trim((string)(getenv('BENEPESO_TURNSTILE_SITE_KEY') ?: ''));
}

function benepeso_turnstile_secret_key(): string
{
    return trim((string)(getenv('BENEPESO_TURNSTILE_SECRET_KEY') ?: ''));
}

function benepeso_turnstile_enabled(): bool
{
    return benepeso_turnstile_site_key() !== '' && benepeso_turnstile_secret_key() !== '';
}

function benepeso_verify_turnstile(string $token, string $remoteIp = '', ?string &$error = null): bool
{
    $error = null;
    if (!benepeso_turnstile_enabled()) {
        return true;
    }

    $token = trim($token);
    if ($token === '' || strlen($token) > 2048) {
        $error = 'Please complete the security verification.';
        return false;
    }

    $payload = http_build_query(array_filter([
        'secret' => benepeso_turnstile_secret_key(),
        'response' => $token,
        'remoteip' => trim($remoteIp),
    ], static fn($value): bool => $value !== ''));

    $response = false;
    if (function_exists('curl_init')) {
        $curl = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
    } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 8,
        ]]);
        $response = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
    }

    if (!is_string($response) || $response === '') {
        $error = 'Security verification is temporarily unavailable. Please try again.';
        return false;
    }

    $result = json_decode($response, true);
    if (!is_array($result) || empty($result['success']) || ($result['action'] ?? '') !== 'login') {
        $error = 'Security verification expired or was unsuccessful. Please try again.';
        return false;
    }

    return true;
}
