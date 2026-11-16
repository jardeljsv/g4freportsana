<?php
/**
 * Metadata inspection must not depend on a booted GLPI application.
 * CLI: php ana_discovery_metadata_test.php [plugin-directory] [expected-version]
 * No GLPI stubs, database, application bootstrap or network are used.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

$root = realpath($argv[1] ?? dirname(__DIR__));
$expectedVersion = $argv[2] ?? '2.0.5';
if (!$root || !is_file($root . '/setup.php')) {
    fwrite(STDERR, "Plugin directory containing setup.php is required.\n");
    exit(1);
}
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
};
$autoloadAttempts = [];
$autoloadTrap = static function (string $class) use (&$autoloadAttempts): void {
    $autoloadAttempts[] = $class;
};
spl_autoload_register($autoloadTrap);
$exitCode = 0;

try {
    $classesBefore = get_declared_classes();
    $loadersBefore = spl_autoload_functions();
    $includedBefore = get_included_files();
    $sessionBefore = $_SESSION ?? null;
    $hooksBefore = $GLOBALS['PLUGIN_HOOKS'] ?? null;

    require $root . '/setup.php';

    $check(function_exists('plugin_version_g4freportsana'), 'Metadata callback is declared');
    $metadata = plugin_version_g4freportsana();
    $check(($metadata['name'] ?? null) === 'Relatórios G4F — ANA', 'Plugin display name is preserved');
    $check(($metadata['version'] ?? null) === $expectedVersion, 'Expected release version is returned');
    $check(($metadata['requirements']['glpi']['min'] ?? null) === '11.0.0', 'GLPI 11 lower boundary is preserved');
    $check(($metadata['requirements']['glpi']['max'] ?? null) === '13.0.0', 'GLPI 13 remains the exclusive upper boundary');
    $check(function_exists('plugin_init_g4freportsana'), 'Initialization callback is declared without executing');
    $check(function_exists('plugin_g4freportsana_check_prerequisites'), 'Prerequisite callback is declared');
    $check(function_exists('plugin_g4freportsana_check_config'), 'Configuration callback is declared');
    $check(get_declared_classes() === $classesBefore, 'Metadata inspection must not load any runtime class');
    $check(spl_autoload_functions() === $loadersBefore, 'Metadata inspection must not register an autoloader');
    $check($autoloadAttempts === [], 'Metadata inspection must not attempt runtime class autoloading');
    $check(array_values(array_diff(get_included_files(), $includedBefore)) === [$root . '/setup.php'], 'Metadata inspection must include setup.php only');
    $check(($_SESSION ?? null) === $sessionBefore, 'Metadata inspection must not modify the session');
    $check(($GLOBALS['PLUGIN_HOOKS'] ?? null) === $hooksBefore, 'Metadata inspection must not initialize hooks');
    $check(!class_exists('CommonGLPI', false) && !class_exists('Config', false) && !class_exists('Plugin', false), 'Test does not supply or load GLPI core classes');
    echo "PASS: $checks dependency-free metadata discovery checks. No GLPI application, database or network was used.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: metadata discovery required runtime state or failed: ' . get_class($error) . ': ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    spl_autoload_unregister($autoloadTrap);
}
exit($exitCode);
