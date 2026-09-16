<?php

require_once __DIR__ . '/google_auth_config.php';

function benepeso_register_jwt_autoloader(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Firebase\\JWT\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/vendor/firebase/php-jwt/src/'
            . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
    $registered = true;
}

function benepeso_google_jwks(bool $forceRefresh = false): array
{
    $cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'benepeso_google_jwks.json';
    $freshFor = 21600;
    $staleFor = 86400;

    $readCache = static function () use ($cachePath): array {
        if (!is_file($cachePath)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($cachePath), true);
        return is_array($decoded) && !empty($decoded['keys']) ? $decoded : [];
    };

    if (!$forceRefresh && is_file($cachePath) && filemtime($cachePath) >= time() - $freshFor) {
        return $readCache();
    }

    $curl = curl_init('https://www.googleapis.com/oauth2/v3/certs');
    if ($curl === false) {
        throw new RuntimeException('Google certificate service could not be initialized.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    $jwks = is_string($response) ? json_decode($response, true) : null;
    if ($status === 200 && is_array($jwks) && !empty($jwks['keys'])) {
        @file_put_contents($cachePath, json_encode($jwks), LOCK_EX);
        return $jwks;
    }

    if (is_file($cachePath) && filemtime($cachePath) >= time() - $staleFor) {
        $cached = $readCache();
        if ($cached) {
            return $cached;
        }
    }

    throw new RuntimeException('Google certificates are unavailable. ' . $error);
}

function benepeso_verify_google_id_token(string $credential): array|false
{
    benepeso_register_jwt_autoloader();
    $headers = new stdClass();

    $decode = static function (array $jwks) use ($credential, &$headers): array {
        $keys = Firebase\JWT\JWK::parseKeySet($jwks, 'RS256');
        Firebase\JWT\JWT::$leeway = 30;
        return (array)Firebase\JWT\JWT::decode($credential, $keys, $headers);
    };

    try {
        $payload = $decode(benepeso_google_jwks());
    } catch (UnexpectedValueException $firstError) {
        // Retry once with fresh keys in case Google rotated signing keys.
        try {
            $payload = $decode(benepeso_google_jwks(true));
        } catch (Throwable $secondError) {
            return false;
        }
    } catch (Throwable $error) {
        return false;
    }

    $expectedAudience = benepeso_google_client_id();
    $issuer = (string)($payload['iss'] ?? '');
    $audience = $payload['aud'] ?? '';

    if (!is_string($audience)
        || !hash_equals($expectedAudience, $audience)
        || !in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
        || empty($payload['sub'])
        || strlen((string)$payload['sub']) > 255) {
        return false;
    }

    return $payload;
}

