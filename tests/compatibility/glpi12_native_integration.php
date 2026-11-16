<?php
/**
 * Offline GLPI 12.0.1 integration checks. Never boots GLPI or opens a connection.
 * Usage: php glpi12_native_integration.php [path/to/glpi-12.0.1] [path/to/plugin]
 * Real upstream classes are loaded unchanged. Only framework/transport dependencies
 * are substituted; password encryption and native authorization logic are real.
 */
namespace Safe {
    class DateTime extends \DateTime {}
    function base64_decode(string $value, bool $strict = false): string {
        $result = \base64_decode($value, $strict);
        if ($result === false) { throw new \RuntimeException('Invalid test base64'); }
        return $result;
    }
    function sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(...$args): string {
        return \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(...$args);
    }
    function sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(...$args): string {
        $result = \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(...$args);
        if ($result === false) { throw new \RuntimeException('Test authentication failed'); }
        return $result;
    }
}
namespace Symfony\Component\HttpKernel\Exception {
    interface HttpExceptionInterface extends \Throwable {}
    class AccessDeniedHttpException extends \RuntimeException implements HttpExceptionInterface {}
}
namespace {
    use GlpiPlugin\G4freportsana\Security;
    use GlpiPlugin\G4freportsana\Database\ConnectionConfig;
    use GlpiPlugin\G4freportsana\Ana\RegisterHistory;

    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    $core = realpath($argv[1] ?? '');
    $plugin = realpath($argv[2] ?? dirname(__DIR__, 2));
    if (!isset($argv[1]) || !$core || !$plugin || !is_file($core . '/src/Session.php')) { throw new RuntimeException('Usage: php glpi12_native_integration.php /path/to/glpi-12.0.1 [/path/to/plugin]'); }
    error_reporting(E_ALL);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
        return false;
    });
    $keyDir = sys_get_temp_dir() . '/g4fra-glpi12-offline-' . bin2hex(random_bytes(8));
    mkdir($keyDir, 0700);
    file_put_contents($keyDir . '/glpicrypt.key', random_bytes(32));
    register_shutdown_function(static function () use ($keyDir): void {
        @unlink($keyDir . '/glpicrypt.key');
        @rmdir($keyDir);
    });
    define('GLPI_CONFIG_DIR', $keyDir);
    define('GLPI_ROOT', $core);
    define('GLPI_VERSION', '12.0.1');
    define('GLPI_DISABLE_REAUTH', false);
    define('MINUTE_TIMESTAMP', 60);
    define('READ', 1);
    define('UPDATE', 2);
    define('CREATE', 4);
    define('DELETE', 8);
    define('PURGE', 16);
    // Simulate an HTTP request; no kernel, HTTP listener or database is started.
    function isAPI(): bool { return false; }
    function isCommandLine(): bool { return false; }
    function __($text, $domain = null) { return $text; }
    class Toolbox {
        public static function deprecated($message): never {
            throw new RuntimeException('Deprecated GLPI API invoked: ' . $message);
        }
    }
    class JsonIntercept extends RuntimeException {
        public function __construct(public int $status, public array $payload) { parent::__construct('JSON response intercepted'); }
    }
    // Preserve the plugin response payload while replacing only HTTP exit/output.
    function g4fra_json_response(int $status, array $payload): void { throw new JsonIntercept($status, $payload); }
    $checks = 0;
    function check(bool $condition, string $message): void {
        global $checks;
        if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
        $checks++;
        echo 'PASS ' . $message . "\n";
    }

    foreach ([
        'CommonGLPIInterface.php', 'CommonGLPI.php', 'CommonDBTM.php', 'Config.php',
        'Session.php', 'DBmysql.php', 'GLPIKey.php', 'Glpi/Plugin/Hooks.php',
        'Glpi/Toolbox/SingletonTrait.php', 'Glpi/Security/ReAuth/ReAuthManager.php',
        'Glpi/Exception/Http/HttpExceptionInterface.php', 'Glpi/Exception/Http/HttpExceptionTrait.php',
        'Glpi/Exception/Http/AccessDeniedHttpException.php',
    ] as $source) {
        require_once $core . '/src/' . $source;
    }
    require_once $plugin . '/setup.php';
    plugin_init_g4freportsana();
    check(is_subclass_of(\GlpiPlugin\G4freportsana\Config::class, \Config::class), 'Plugin Config loads against actual GLPI12 Config/CommonDBTM/CommonGLPI');
    check(is_subclass_of(PluginG4freportsanaMenu::class, \CommonGLPI::class), 'Legacy menu alias loads against actual typed CommonGLPI');
    check(plugin_g4freportsana_check_prerequisites(), 'Package version gate accepts GLPI12.0.1');
    check(Security::csrfToken() === '', 'GLPI12 token helper avoids deprecated native Session token method');

    // Actual native DB lock methods run; only network transport and escaping are replaced.
    class OfflineDb extends DBmysql {
        public array $queries = [];
        public int $lockResult = 1;
        public function __construct($choice = null) { $this->dbdefault = 'offline_glpi'; }
        public function doQuery(\Glpi\DBAL\Parts\BasePart|string $query): true|\mysqli_result {
            $this->queries[] = (string)$query;
            return true;
        }
        public function fetchRow($result) { return [$this->lockResult]; }
        public function escape($value) { return str_replace("'", "''", $value); }
    }
    $DB = new OfflineDb();
    $CFG_GLPI = ['root_doc' => '/glpi'];
    $_SESSION = ['glpiID' => 3, 'glpiactiveprofile' => ['config' => UPDATE], 'glpi_currenttime' => '2026-10-29 12:00:00'];
    check(Security::currentUserId() === 3, 'Native Session login user lookup is compatible');
    check(Security::canConfigure(), 'Configuration right uses actual Session::haveRight');
    $rootFound = null;
    check(g4fra_bootstrap_glpi_runtime($rootFound) && $rootFound === $core, 'Already booted GLPI12 skips obsolete inc/includes.php');
    check(Security::configurationReauthenticationNeeded(), 'Native Config detects expired reauthentication window');
    try {
        Security::requireConfigurationReauthenticationJson();
        throw new RuntimeException('Expired JSON request was allowed');
    } catch (JsonIntercept $e) {
        check($e->status === 403 && $e->payload['reason'] === 'reauth_required'
            && $e->payload['reauth_url'] === '/glpi/front/config.form.php', 'Expired JSON request yields native configuration recovery URL');
    }
    check(!isset($_SESSION['glpi_reauth_until']), 'Plugin does not create or extend native reauthentication window');
    $_SESSION['glpi_reauth_until'] = strtotime($_SESSION['glpi_currenttime']) + 300;
    $validUntil = $_SESSION['glpi_reauth_until'];
    check(!Security::configurationReauthenticationNeeded(), 'Native Config accepts valid reauthentication window');
    Security::requireConfigurationReauthentication();
    Security::requireConfigurationReauthenticationJson();
    check($_SESSION['glpi_reauth_until'] === $validUntil, 'Valid configuration requests preserve native expiry');
    $_SESSION['glpiactiveprofile']['config'] = READ;
    check(!Security::canConfigure(), 'Read-only configuration permission does not authorize updates');
    try {
        Security::requireConfigurationReauthentication();
        throw new RuntimeException('Unauthorized configuration request was allowed');
    } catch (\Glpi\Exception\Http\AccessDeniedHttpException $e) {
        check(true, 'Native configuration guard denies user without UPDATE');
    }
    try {
        Security::requireConfigurationReauthenticationJson();
        throw new RuntimeException('Unauthorized JSON request was allowed');
    } catch (JsonIntercept $e) {
        check($e->status === 403 && !isset($e->payload['reauth_required']), 'JSON guard denies rights independently of reauthentication');
    }

    // Execute native Config::setConfigurationValues unchanged, replacing its persistence only.
    class OfflineConfig extends Config {
        public static array $stored = [];
        public function __construct() {}
        public function getFromDBByCrit(array $criteria) { return false; }
        public function add(array $input, $options = [], $history = true) {
            self::$stored[$input['context']][$input['name']] = $input['value'];
            return 1;
        }
    }
    $_SESSION['glpiID'] = 0; // Native setter need not load a logged-in user's preferences.
    $key = new GLPIKey();
    check($key->isConfigSecured('plugin:g4freportsana', 'ana_db_password')
        && $key->isConfigSecured('plugin:g4freportsana', 'callcenter_db_password'), 'Native GLPIKey recognizes both registered plugin secured fields');
    foreach (['0', ' leading and trailing spaces ', 'Senha-áç-123'] as $password) {
        $input = ConnectionConfig::prepareUpdate(['ana_db_enabled' => 1, 'ana_db_password' => $password]);
        OfflineConfig::setConfigurationValues('plugin:g4freportsana', $input);
        $saved = OfflineConfig::$stored['plugin:g4freportsana'];
        check($saved['ana_db_password'] !== $input['ana_db_password'] && $saved['ana_db_password'] !== $password,
            'Native Config setter encrypts nonempty password envelope');
        check(ConnectionConfig::fromConfig('ana', $saved)['password'] === $password,
            'Native GLPIKey encryption roundtrip preserves password bytes');
        check(ConnectionConfig::redact($saved)['ana_db_password'] === '', 'View redaction removes native encrypted secret');
    }
    check(!array_key_exists('ana_db_password', ConnectionConfig::prepareUpdate(['ana_db_password' => ''])),
        'Empty password submission preserves existing stored secret');
    $lock = RegisterHistory::lock();
    RegisterHistory::unlock($lock);
    check($DB->queries === ["SELECT GET_LOCK('offline_glpi.$lock', 0)", "SELECT RELEASE_LOCK('offline_glpi.$lock')"],
        'Register history uses actual native DBmysql lock methods and database namespace');
    $DB->lockResult = 0;
    try {
        RegisterHistory::lock();
        throw new LogicException('Contended lock was accepted');
    } catch (RuntimeException $e) {
        check(true, 'Register history fails when native lock is unavailable');
    }
    $expectedNative = [
        Config::class => '/src/Config.php', CommonGLPI::class => '/src/CommonGLPI.php',
        Session::class => '/src/Session.php', GLPIKey::class => '/src/GLPIKey.php', DBmysql::class => '/src/DBmysql.php',
        \Glpi\Security\ReAuth\ReAuthManager::class => '/src/Glpi/Security/ReAuth/ReAuthManager.php',
    ];
    foreach ($expectedNative as $class => $relative) {
        check((new ReflectionClass($class))->getFileName() === $core . $relative, 'Verified unchanged native source origin: ' . $class);
    }
    echo "OK: $checks native integration checks; no database/network connection opened.\n";
}
