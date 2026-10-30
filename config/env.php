<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// config/env.php — Zero-Dependency .env Loader & Dynamic Base URL Resolver
// ============================================================================

/**
 * Load key/value pairs from .env files into $_ENV without overriding
 * existing server environment variables (unless $override = true).
 */
function loadEnvFile(string $filePath, bool $override = false): void
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        $value = trim($value, "\"'");

        if ($key === '') {
            continue;
        }

        if ($override || (getenv($key) === false && !array_key_exists($key, $_ENV))) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

/**
 * Read an environment variable with fallback default.
 */
function env(string $key, mixed $default = null): mixed
{
    static $loaded = false;

    if (!$loaded) {
        $root = dirname(__DIR__);
        loadEnvFile($root . '/.env');
        loadEnvFile($root . '/.env.local', true);
        $loaded = true;
    }

    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }

    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }

    return $default;
}

/**
 * Automatically detect the application root URL prefix (e.g., "" or "/NEW_MOCKSYSTEM")
 * so redirects and asset links work seamlessly under Laragon vhosts, subdirectories, or PHP CLI server.
 */
function appBaseUrl(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $configured = trim((string) env('APP_BASE_URL', ''));
    if ($configured !== '') {
        $base = rtrim($configured, '/');
        return $base;
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    foreach (['/public/api/', '/public/', '/modules/auth/', '/modules/'] as $marker) {
        $pos = strpos($scriptName, $marker);
        if ($pos !== false) {
            $base = rtrim(substr($scriptName, 0, $pos), '/');
            return $base;
        }
    }

    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    $base = ($dir === '/' || $dir === '.') ? '' : $dir;
    return $base;
}

/**
 * Build an application URL relative to the root base path.
 */
function appUrl(string $path = ''): string
{
    $clean = ltrim($path, '/');
    $base  = appBaseUrl();
    return $base . '/' . $clean;
}
