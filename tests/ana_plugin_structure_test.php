<?php
/** Offline plugin lifecycle checks; native configuration writes are in-memory stubs. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
class CommonGLPI {}
class Config extends CommonGLPI
{
    public static array $stores = [];
    public static function getConfigurationValues($context) { return self::$stores[$context] ?? []; }
    public static function setConfigurationValues($context, $values) {
        self::$stores[$context] = array_replace(self::$stores[$context] ?? [], $values);
    }
    public function deleteByCriteria($criteria) { unset(self::$stores[$criteria['context']]); }
}
class Plugin
{
    public static array $registered = [];
    public static function registerClass($class, $attributes = []) { self::$registered[$class] = $attributes; }
}
class Session
{
    public static int $tokenCalls = 0;
    public static function getNewCSRFToken(): string {
        self::$tokenCalls++;
        if (version_compare(GLPI_VERSION, '12.0.0', '>=')) {
            throw new RuntimeException('GLPI 12 deprecated token API must not be called');
        }
        return 'native-glpi11-token';
    }
}
function __($text, $domain = null) { return $text; }
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
define('GLPI_VERSION', $argv[1] ?? '12.0.1');
$PLUGIN_HOOKS = [];
$CFG_GLPI = ['root_doc' => '/glpi'];
$root = dirname(__DIR__);
require $root . '/setup.php';
require $root . '/hook.php';
plugin_init_g4freportsana();
$version = plugin_version_g4freportsana();
$check($version['version'] === \GlpiPlugin\G4freportsana\RuntimeInfo::BUILD_VERSION, 'setup, common and runtime version must agree');
$check($version['requirements']['glpi']['min'] === '11.0.0', 'GLPI 11 minimum retained');
$check($version['requirements']['glpi']['max'] === '13.0.0', 'GLPI 13 exclusive upper boundary');
if (version_compare(GLPI_VERSION, '11.0.0', '<') || version_compare(GLPI_VERSION, '13.0.0', '>=')) {
    $check(!plugin_g4freportsana_check_prerequisites(), 'Unsupported GLPI version rejected');
    echo "PASS: $checks metadata checks; unsupported GLPI " . GLPI_VERSION . " rejected.\n";
    exit;
}
$check(plugin_g4freportsana_check_prerequisites(), 'Supported GLPI prerequisites');
$legacyToken = version_compare(GLPI_VERSION, '12.0.0', '<');
$check(\GlpiPlugin\G4freportsana\Security::csrfToken() === ($legacyToken ? 'native-glpi11-token' : ''), 'Native token behavior follows the installed GLPI version');
$check(Session::$tokenCalls === ($legacyToken ? 1 : 0), 'GLPI 12 never calls the deprecated token API');
$check(isset(Plugin::$registered['GlpiPlugin\\G4freportsana\\Config']['addtabon']), 'Native Config tab registered');
$menuClass = $PLUGIN_HOOKS['menu_toadd']['g4freportsana']['tools'];
$check(class_exists($menuClass), 'Menu class autoloads');
$check($menuClass::getMenuContent()['page'] === '/glpi/plugins/g4freportsana/front/ana.php', 'Subdirectory menu URL points to ANA');
$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$check(($composer['autoload']['psr-4']['GlpiPlugin\\G4freportsana\\'] ?? '') === 'src/', 'PSR-4 identity matches folder');
$check(is_file($root . '/templates/' . \GlpiPlugin\G4freportsana\RuntimeInfo::CONFIG_TEMPLATE), 'Pinned configuration template exists');
$originalContext = ['enabled' => 1, 'sentinel' => 'other-contract'];
Config::$stores['plugin:g4freports'] = $originalContext;
$check(plugin_g4freportsana_install(), 'Install callback');
$check(isset(Config::$stores['plugin:g4freportsana']['ana_db_password']), 'Dedicated ANA defaults installed');
Config::$stores['plugin:g4freportsana']['ana_db_password'] = 'encrypted-password-sentinel';
Config::$stores['plugin:g4freportsana']['default_profile_name'] = 'Existing profile';
$check(plugin_g4freportsana_install(), 'Update callback');
$check(Config::$stores['plugin:g4freportsana']['ana_db_password'] === 'encrypted-password-sentinel', 'Update does not reset or reencrypt password');
$check(Config::$stores['plugin:g4freportsana']['default_profile_name'] === 'Existing profile', 'Update preserves user configuration');
$check(plugin_g4freportsana_uninstall(), 'Uninstall callback');
$check(!isset(Config::$stores['plugin:g4freportsana']), 'Uninstall removes ANA context');
$check(Config::$stores['plugin:g4freports'] === $originalContext, 'Uninstall preserves original plugin context');
echo "PASS: $checks plugin structure, version and lifecycle checks using offline GLPI stubs.\n";
