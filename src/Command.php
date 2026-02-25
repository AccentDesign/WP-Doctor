<?php

namespace AccentDesign\WPDoctor;

use WP_CLI;
use WP_CLI_Command;

/**
 * Diagnose and fix WordPress code issues.
 *
 * SAFETY: Read-only by default. Never modifies DB or breaks site setup.
 */
class Command extends WP_CLI_Command
{
    /**
     * Scan WordPress installation for issues.
     *
     * ## OPTIONS
     *
     * [--path=<path>]
     * : Path to scan. Defaults to current WordPress installation.
     *
     * [--format=<format>]
     * : Output format. Options: table, json, summary. Default: table.
     *
     * [--category=<category>]
     * : Only run specific category. Options: all, deprecations, null-safety, dependencies, security, performance, dead-code.
     *
     * [--verbose]
     * : Show detailed file locations and line numbers.
     *
     * [--plugins]
     * : Scan plugins directory only.
     *
     * [--themes]
     * : Scan themes directory only.
     *
     * [--active-only]
     * : Only scan active plugins and current theme.
     *
     * ## EXAMPLES
     *
     *     # Full scan with summary
     *     wp doctor scan
     *
     *     # Scan with JSON output for MCP
     *     wp doctor scan --format=json
     *
     *     # Scan only plugins for deprecations
     *     wp doctor scan --plugins --category=deprecations
     *
     *     # Verbose output with line numbers
     *     wp doctor scan --verbose
     *
     * @when after_wp_load
     */
    public function scan($args, $assoc_args)
    {
        $path = $assoc_args['path'] ?? ABSPATH;
        $format = $assoc_args['format'] ?? 'table';
        $category = $assoc_args['category'] ?? 'all';
        $verbose = isset($assoc_args['verbose']);
        $pluginsOnly = isset($assoc_args['plugins']);
        $themesOnly = isset($assoc_args['themes']);
        $activeOnly = isset($assoc_args['active-only']);

        WP_CLI::log('WP Doctor - Scanning WordPress installation...');
        WP_CLI::log('');

        $doctor = new Doctor([
            'path' => $path,
            'category' => $category,
            'verbose' => $verbose,
            'plugins_only' => $pluginsOnly,
            'themes_only' => $themesOnly,
            'active_only' => $activeOnly,
        ]);

        $result = $doctor->diagnose();

        $reporter = new Reporters\Reporter($format, $verbose);
        $reporter->output($result);

        // Return exit code based on score
        if ($result['score'] < 50) {
            WP_CLI::halt(2); // Critical
        } elseif ($result['score'] < 75) {
            WP_CLI::halt(1); // Needs work
        }
    }

    /**
     * Get WordPress project info without full scan.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Options: table, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp doctor info
     *     wp doctor info --format=json
     *
     * @when after_wp_load
     */
    public function info($args, $assoc_args)
    {
        $format = $assoc_args['format'] ?? 'table';

        global $wp_version;

        $info = [
            'wordpress_version' => $wp_version,
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->getMySQLVersion(),
            'active_theme' => get_stylesheet(),
            'parent_theme' => get_template() !== get_stylesheet() ? get_template() : null,
            'active_plugins' => count(get_option('active_plugins', [])),
            'multisite' => is_multisite(),
            'debug_mode' => WP_DEBUG,
            'memory_limit' => WP_MEMORY_LIMIT,
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ];

        if ($format === 'json') {
            WP_CLI::log(json_encode($info, JSON_PRETTY_PRINT));
        } else {
            WP_CLI\Utils\format_items('table', [$info], array_keys($info));
        }
    }

    /**
     * Check a specific file for issues.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the file to check.
     *
     * [--format=<format>]
     * : Output format. Options: table, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp doctor check wp-content/plugins/my-plugin/my-plugin.php
     *
     * @when after_wp_load
     */
    public function check($args, $assoc_args)
    {
        $file = $args[0] ?? null;

        if (!$file || !file_exists($file)) {
            WP_CLI::error("File not found: {$file}");
        }

        $format = $assoc_args['format'] ?? 'table';

        $doctor = new Doctor(['path' => dirname($file)]);
        $result = $doctor->checkFile($file);

        $reporter = new Reporters\Reporter($format, true);
        $reporter->outputFileResult($file, $result);
    }

    /**
     * Preview fixes without applying them (dry-run).
     *
     * ## OPTIONS
     *
     * [--category=<category>]
     * : Category of fixes to preview.
     *
     * [--file=<file>]
     * : Preview fixes for specific file only.
     *
     * ## EXAMPLES
     *
     *     wp doctor preview
     *     wp doctor preview --category=deprecations
     *
     * @when after_wp_load
     */
    public function preview($args, $assoc_args)
    {
        $category = $assoc_args['category'] ?? 'all';
        $file = $assoc_args['file'] ?? null;

        WP_CLI::log('WP Doctor - Preview Mode (no changes will be made)');
        WP_CLI::log('');

        $doctor = new Doctor(['category' => $category]);
        $result = $doctor->diagnose();

        $fixer = new Fixers\Fixer($result['diagnostics']);
        $fixes = $fixer->preview($file);

        if (empty($fixes)) {
            WP_CLI::success('No auto-fixable issues found.');
            return;
        }

        WP_CLI::log(sprintf('Found %d auto-fixable issues:', count($fixes)));
        WP_CLI::log('');

        foreach ($fixes as $fix) {
            WP_CLI::log(sprintf(
                "  %s:%d",
                $fix['file'],
                $fix['line']
            ));
            WP_CLI::log(sprintf("    - %s", $fix['description']));
            WP_CLI::log(sprintf("    - Before: %s", trim($fix['before'])));
            WP_CLI::log(sprintf("    + After:  %s", trim($fix['after'])));
            WP_CLI::log('');
        }

        WP_CLI::log('Run `wp doctor fix` to apply these changes.');
    }

    /**
     * Apply safe fixes to detected issues.
     *
     * SAFETY: Only applies safe, reversible fixes.
     * Creates backup before any changes.
     * Never modifies database.
     *
     * ## OPTIONS
     *
     * [--category=<category>]
     * : Category of fixes to apply.
     *
     * [--file=<file>]
     * : Apply fixes to specific file only.
     *
     * [--dry-run]
     * : Preview changes without applying.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp doctor fix
     *     wp doctor fix --category=deprecations
     *     wp doctor fix --dry-run
     *
     * @when after_wp_load
     */
    public function fix($args, $assoc_args)
    {
        $category = $assoc_args['category'] ?? 'all';
        $file = $assoc_args['file'] ?? null;
        $dryRun = isset($assoc_args['dry-run']);
        $yes = isset($assoc_args['yes']);

        if ($dryRun) {
            return $this->preview($args, $assoc_args);
        }

        WP_CLI::log('WP Doctor - Fix Mode');
        WP_CLI::log('');
        WP_CLI::warning('This will modify files in your WordPress installation.');
        WP_CLI::log('A backup will be created before any changes.');
        WP_CLI::log('');

        if (!$yes) {
            WP_CLI::confirm('Are you sure you want to proceed?');
        }

        $doctor = new Doctor(['category' => $category]);
        $result = $doctor->diagnose();

        $fixer = new Fixers\Fixer($result['diagnostics']);
        $fixes = $fixer->apply($file);

        if (empty($fixes)) {
            WP_CLI::success('No auto-fixable issues found.');
            return;
        }

        WP_CLI::success(sprintf('Applied %d fixes.', count($fixes)));

        foreach ($fixes as $fix) {
            WP_CLI::log(sprintf("  ✓ %s:%d - %s", $fix['file'], $fix['line'], $fix['description']));
        }
    }

    /**
     * Get MySQL version
     */
    private function getMySQLVersion()
    {
        global $wpdb;
        return $wpdb->get_var('SELECT VERSION()');
    }
}
