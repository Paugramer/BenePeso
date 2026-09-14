<?php

/**
 * Small server-side rate limiter for authentication endpoints.
 * State is kept outside the web root and is shared across browser sessions.
 */
function auth_rate_limit_path(string $scope, string $identity): string
{
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'benepeso_auth_limits';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Authentication rate-limit storage is unavailable.');
    }

    return $directory . DIRECTORY_SEPARATOR
        . hash('sha256', $scope . '|' . mb_strtolower(trim($identity))) . '.json';
}

function auth_rate_limit_update(
    string $scope,
    string $identity,
    int $maximumAttempts,
    int $windowSeconds,
    int $blockSeconds,
    bool $recordAttempt
): int {
    $path = auth_rate_limit_path($scope, $identity);
    $handle = fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('Authentication rate-limit storage could not be locked.');
    }

    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            $state = ['window_started' => time(), 'attempts' => 0, 'blocked_until' => 0];
        }

        $now = time();
        if ((int)$state['window_started'] <= $now - $windowSeconds) {
            $state = ['window_started' => $now, 'attempts' => 0, 'blocked_until' => 0];
        }

        if ((int)$state['blocked_until'] > $now) {
            return (int)$state['blocked_until'] - $now;
        }

        if ($recordAttempt) {
            $state['attempts'] = (int)$state['attempts'] + 1;
            if ((int)$state['attempts'] >= $maximumAttempts) {
                $state['blocked_until'] = $now + $blockSeconds;
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state));
            fflush($handle);
        }

        return max(0, (int)$state['blocked_until'] - $now);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function auth_rate_limit_retry_after(
    string $scope,
    string $identity,
    int $maximumAttempts,
    int $windowSeconds,
    int $blockSeconds
): int {
    return auth_rate_limit_update($scope, $identity, $maximumAttempts, $windowSeconds, $blockSeconds, false);
}

function auth_rate_limit_hit(
    string $scope,
    string $identity,
    int $maximumAttempts,
    int $windowSeconds,
    int $blockSeconds
): int {
    return auth_rate_limit_update($scope, $identity, $maximumAttempts, $windowSeconds, $blockSeconds, true);
}

function auth_rate_limit_clear(string $scope, string $identity): void
{
    $path = auth_rate_limit_path($scope, $identity);
    if (is_file($path)) {
        @unlink($path);
    }
}

function auth_request_ip(): string
{
    // REMOTE_ADDR is intentionally used instead of spoofable forwarding headers.
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}
