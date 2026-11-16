<?php
/** Read-only package inspection. No GLPI bootstrap, database or network access. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$result = [
    'package_directory' => realpath($root),
    'directory_key' => basename($root),
    'php_cli_version' => PHP_VERSION,
    'metadata' => null,
    'autoload_attempts' => [],
    'checked_files' => 0,
    'missing_or_unreadable' => [],
    'different_files' => [],
    'errors' => [],
    'scope' => 'Local package only. Does not load GLPI or verify its configured search paths, web-process access, database state or caches.',
];
if (basename($root) !== 'g4freportsana') {
    $result['errors'][] = 'The plugin directory key must be g4freportsana.';
}

// Metadata must remain readable without initializing application classes.
$guard = static function (string $class) use (&$result): void {
    $result['autoload_attempts'][] = $class;
    throw new RuntimeException('Package metadata attempted to load an application class: ' . $class);
};
spl_autoload_register($guard, true, true);
$included = get_included_files();
try {
    require $root . '/setup.php';
    if (!function_exists('plugin_version_g4freportsana')) {
        throw new RuntimeException('The expected plugin version callback is absent.');
    }
    $result['metadata'] = plugin_version_g4freportsana();
    foreach (array_diff(get_included_files(), $included) as $file) {
        if (realpath($file) !== realpath($root . '/setup.php')) {
            $result['errors'][] = 'Metadata included a runtime dependency: ' . basename($file);
        }
    }
} catch (Throwable $e) {
    $result['errors'][] = $e->getMessage();
} finally {
    spl_autoload_unregister($guard);
}

$manifest = $root . '/SHA256SUMS.txt';
if (!is_readable($manifest)) {
    $result['errors'][] = 'Package integrity manifest is missing or unreadable.';
} else {
    foreach (file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (!preg_match('/^([a-f0-9]{64})  (.+)$/D', $line, $match)
            || str_starts_with($match[2], '/') || str_contains($match[2], '\\')
            || in_array('..', explode('/', $match[2]), true)) {
            $result['errors'][] = 'Invalid entry in the package integrity manifest.';
            continue;
        }
        $path = $root . '/' . $match[2];
        if (!is_file($path) || !is_readable($path)) {
            $result['missing_or_unreadable'][] = $match[2];
        } elseif (hash_file('sha256', $path) !== $match[1]) {
            $result['different_files'][] = $match[2];
        }
        $result['checked_files']++;
    }
}
$result['ok'] = $result['errors'] === []
    && $result['autoload_attempts'] === []
    && $result['missing_or_unreadable'] === []
    && $result['different_files'] === [];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
