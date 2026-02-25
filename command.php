<?php
/**
 * WP Doctor - Diagnose and fix WordPress code issues
 *
 * SAFETY: This tool is READ-ONLY by default.
 * It will NEVER modify database or files unless --fix flag is explicitly used.
 */

if (!class_exists('WP_CLI')) {
    return;
}

// Load the Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

use AccentDesign\WPDoctor\Command;

WP_CLI::add_command('doctor', Command::class);
