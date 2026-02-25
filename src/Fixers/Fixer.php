<?php

namespace AccentDesign\WPDoctor\Fixers;

/**
 * Applies automated fixes to detected issues.
 *
 * SAFETY: Creates backups before modifying files.
 * Only applies safe, reversible fixes.
 */
class Fixer
{
    private array $diagnostics;
    private array $fixes = [];
    private string $backupDir;
    private string $basePath;

    /**
     * Rules that can be auto-fixed
     */
    private const FIXABLE_RULES = [
        'php82-curly-syntax' => 'fixCurlySyntax',
        'wp-null-string-deprecation' => 'fixNullToEmptyString',
        'yoda-condition' => 'fixYodaCondition',
        'stripslashes-null' => 'fixStripslashesNull',
    ];

    public function __construct(array $diagnostics, ?string $basePath = null)
    {
        $this->diagnostics = $diagnostics;
        $this->basePath = $basePath ?? (defined('WP_CONTENT_DIR') ? dirname(WP_CONTENT_DIR) : getcwd());
        $this->backupDir = $this->basePath . '/.wp-doctor-backups/' . date('Y-m-d_H-i-s');
    }

    /**
     * Preview fixes without applying
     */
    public function preview(?string $fileFilter = null): array
    {
        $fixes = [];

        foreach ($this->diagnostics as $diagnostic) {
            $rule = $diagnostic['rule'];

            if (!isset(self::FIXABLE_RULES[$rule])) {
                continue;
            }

            if ($fileFilter !== null && $diagnostic['file'] !== $fileFilter) {
                continue;
            }

            $fix = $this->generateFix($diagnostic);
            if ($fix !== null) {
                $fixes[] = $fix;
            }
        }

        return $fixes;
    }

    /**
     * Apply fixes
     */
    public function apply(?string $fileFilter = null): array
    {
        $fixes = $this->preview($fileFilter);

        if (empty($fixes)) {
            return [];
        }

        // Create backup directory
        if (!is_dir($this->backupDir)) {
            $this->mkdirRecursive($this->backupDir);
        }

        // Group fixes by file
        $fileFixesMap = [];
        foreach ($fixes as $fix) {
            $file = $fix['file'];
            if (!isset($fileFixesMap[$file])) {
                $fileFixesMap[$file] = [];
            }
            $fileFixesMap[$file][] = $fix;
        }

        $appliedFixes = [];

        foreach ($fileFixesMap as $file => $fileFixes) {
            // Backup the file
            $this->backupFile($file);

            // Read current content
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Apply fixes in reverse line order to maintain line numbers
            usort($fileFixes, fn($a, $b) => $b['line'] - $a['line']);

            foreach ($fileFixes as $fix) {
                $content = str_replace($fix['before'], $fix['after'], $content);
                $appliedFixes[] = $fix;
            }

            // Write updated content
            file_put_contents($file, $content);
        }

        return $appliedFixes;
    }

    /**
     * Generate a fix for a diagnostic
     */
    private function generateFix(array $diagnostic): ?array
    {
        $rule = $diagnostic['rule'];
        $method = self::FIXABLE_RULES[$rule] ?? null;

        if ($method === null || !method_exists($this, $method)) {
            return null;
        }

        return $this->$method($diagnostic);
    }

    /**
     * Fix ${var} to {$var} syntax
     */
    private function fixCurlySyntax(array $diagnostic): ?array
    {
        $content = file_get_contents($diagnostic['file']);
        if ($content === false) {
            return null;
        }

        $lines = explode("\n", $content);
        $lineContent = $lines[$diagnostic['line'] - 1] ?? null;

        if ($lineContent === null) {
            return null;
        }

        // Find ${...} pattern
        if (preg_match('/\$\{([^}]+)\}/', $lineContent, $match)) {
            $before = $match[0];
            $varName = $match[1];
            $after = '{$' . $varName . '}';

            return [
                'file' => $diagnostic['file'],
                'line' => $diagnostic['line'],
                'rule' => $diagnostic['rule'],
                'description' => 'Convert ${var} to {$var} syntax',
                'before' => $before,
                'after' => $after,
            ];
        }

        return null;
    }

    /**
     * Fix add_submenu_page(null) to add_submenu_page('')
     */
    private function fixNullToEmptyString(array $diagnostic): ?array
    {
        $content = file_get_contents($diagnostic['file']);
        if ($content === false) {
            return null;
        }

        $lines = explode("\n", $content);
        $lineContent = $lines[$diagnostic['line'] - 1] ?? null;

        if ($lineContent === null) {
            return null;
        }

        // Find add_submenu_page(null, pattern
        if (preg_match('/(add_submenu_page\s*\(\s*)null(\s*,)/', $lineContent, $match)) {
            $before = $match[0];
            $after = $match[1] . "''" . $match[2];

            return [
                'file' => $diagnostic['file'],
                'line' => $diagnostic['line'],
                'rule' => $diagnostic['rule'],
                'description' => 'Replace null with empty string',
                'before' => $before,
                'after' => $after,
            ];
        }

        return null;
    }

    /**
     * Fix non-Yoda conditions to Yoda
     */
    private function fixYodaCondition(array $diagnostic): ?array
    {
        $content = file_get_contents($diagnostic['file']);
        if ($content === false) {
            return null;
        }

        $lines = explode("\n", $content);
        $lineContent = $lines[$diagnostic['line'] - 1] ?? null;

        if ($lineContent === null) {
            return null;
        }

        // Match: $var === 'value' or $var == value
        if (preg_match('/(\$\w+)\s*(===?|!==?)\s*([\'"][^\'"]*[\'"]|true|false|null|\d+)/', $lineContent, $match)) {
            $variable = $match[1];
            $operator = $match[2];
            $value = $match[3];

            $before = $match[0];
            $after = $value . ' ' . $operator . ' ' . $variable;

            return [
                'file' => $diagnostic['file'],
                'line' => $diagnostic['line'],
                'rule' => $diagnostic['rule'],
                'description' => 'Convert to Yoda condition',
                'before' => $before,
                'after' => $after,
            ];
        }

        return null;
    }

    /**
     * Fix stripslashes($var) to stripslashes($var ?? '')
     */
    private function fixStripslashesNull(array $diagnostic): ?array
    {
        $content = file_get_contents($diagnostic['file']);
        if ($content === false) {
            return null;
        }

        $lines = explode("\n", $content);
        $lineContent = $lines[$diagnostic['line'] - 1] ?? null;

        if ($lineContent === null) {
            return null;
        }

        // Match stripslashes($var) without existing ??
        if (preg_match('/stripslashes\s*\(\s*(\$[a-zA-Z_][a-zA-Z0-9_]*(?:\[[^\]]*\])*(?:->\w+)*)\s*\)/', $lineContent, $match)) {
            // Check if already has null coalescing
            if (strpos($match[0], '??') !== false) {
                return null;
            }

            $before = $match[0];
            $variable = $match[1];
            $after = 'stripslashes(' . $variable . " ?? '')";

            return [
                'file' => $diagnostic['file'],
                'line' => $diagnostic['line'],
                'rule' => $diagnostic['rule'],
                'description' => 'Add null coalescing to stripslashes()',
                'before' => $before,
                'after' => $after,
            ];
        }

        return null;
    }

    /**
     * Backup a file before modification
     */
    private function backupFile(string $filePath): bool
    {
        $relativePath = str_replace($this->basePath . '/', '', $filePath);
        $backupPath = $this->backupDir . '/' . $relativePath;
        $backupDir = dirname($backupPath);

        if (!is_dir($backupDir)) {
            $this->mkdirRecursive($backupDir);
        }

        return copy($filePath, $backupPath);
    }

    /**
     * Create directory recursively (works without WordPress)
     */
    private function mkdirRecursive(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }
        return mkdir($path, 0755, true);
    }

    /**
     * Get backup directory path
     */
    public function getBackupDir(): string
    {
        return $this->backupDir;
    }
}
