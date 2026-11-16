<?php

namespace GlpiPlugin\G4freportsana;

/** Local code information only: no connections, queries or configuration secrets. */
final class RuntimeInfo
{
    public const BUILD_VERSION = '2.0.6';
    public const CONFIG_TEMPLATE = 'config_204.html.twig';

    public static function collect(): array
    {
        $root = dirname(__DIR__);
        $configFile = (new \ReflectionClass(Config::class))->getFileName();
        $configRoot = is_string($configFile) ? dirname($configFile, 2) : $root;
        $template = $configRoot . '/templates/' . self::CONFIG_TEMPLATE;
        $resolvedRoot = null;
        if (class_exists('Plugin') && method_exists('Plugin', 'getPhpDir')) {
            $resolvedRoot = \Plugin::getPhpDir('g4freportsana') ?: null;
        }
        $candidates = [];
        foreach (['GLPI_PLUGINS_DIRECTORIES', 'PLUGINS_DIRECTORIES'] as $constant) {
            if (!defined($constant) || !is_array(constant($constant))) {
                continue;
            }
            foreach (constant($constant) as $directory) {
                $candidate = rtrim((string)$directory, '/\\') . '/g4freportsana';
                if (is_dir($candidate)) {
                    $candidates[self::canonical($candidate)] = true;
                }
            }
        }

        return [
            'loaded_build_version' => self::BUILD_VERSION,
            'plugin_version_constant' => defined('PLUGIN_G4FREPORTSANA_VERSION') ? PLUGIN_G4FREPORTSANA_VERSION : null,
            'php_version' => PHP_VERSION,
            'loaded_config_class_file' => $configFile ?: null,
            'config_file_sha256_on_disk' => is_string($configFile) && is_file($configFile) ? hash_file('sha256', $configFile) : null,
            'loaded_diagnostic_source_directory' => self::canonical($root),
            'glpi_resolved_plugin_directory' => $resolvedRoot,
            'source_matches_glpi_directory' => $resolvedRoot !== null ? self::canonical($configRoot) === self::canonical($resolvedRoot) : null,
            'config_template_file' => $template,
            'config_template_exists' => is_file($template),
            'config_template_sha256_on_disk' => is_file($template) ? hash_file('sha256', $template) : null,
            'plugin_directory_candidates' => array_keys($candidates),
            'opcache_enabled' => filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN),
            'opcache_validate_timestamps' => filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private static function canonical(string $path): string
    {
        return str_replace('\\', '/', realpath($path) ?: rtrim($path, '/\\'));
    }
}
