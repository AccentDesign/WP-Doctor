<?php

namespace AccentDesign\WPDoctor;

use AccentDesign\WPDoctor\Analyzers\AnalyzerInterface;

/**
 * Core diagnostic engine.
 *
 * SAFETY: This class is READ-ONLY. It never modifies files or database.
 */
class Doctor
{
    private array $options;
    private array $analyzers = [];
    private array $diagnostics = [];

    /**
     * Analyzer categories and their classes
     */
    private const ANALYZER_MAP = [
        'deprecations' => Analyzers\DeprecationAnalyzer::class,
        'null-safety' => Analyzers\NullSafetyAnalyzer::class,
        'security' => Analyzers\SecurityAnalyzer::class,
        'performance' => Analyzers\PerformanceAnalyzer::class,
        'dead-code' => Analyzers\DeadCodeAnalyzer::class,
        // These require more context so disabled for standalone:
        // 'dependencies' => Analyzers\DependencyAnalyzer::class,
        // 'hooks' => Analyzers\HookAnalyzer::class,
        // 'coding-standards' => Analyzers\CodingStandardsAnalyzer::class,
    ];

    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'path' => getcwd(),
            'category' => 'all',
            'verbose' => false,
            'plugins_only' => false,
            'themes_only' => false,
            'active_only' => false,
            'ignore' => [],
        ], $options);

        $this->initAnalyzers();
    }

    /**
     * Initialize analyzers based on category selection
     */
    private function initAnalyzers(): void
    {
        $category = $this->options['category'];

        if ($category === 'all') {
            foreach (self::ANALYZER_MAP as $name => $class) {
                if (class_exists($class)) {
                    $this->analyzers[$name] = new $class($this->options);
                }
            }
        } elseif (isset(self::ANALYZER_MAP[$category])) {
            $class = self::ANALYZER_MAP[$category];
            if (class_exists($class)) {
                $this->analyzers[$category] = new $class($this->options);
            }
        }
    }

    /**
     * Run full diagnostic scan
     */
    public function diagnose(): array
    {
        $startTime = microtime(true);
        $this->diagnostics = [];

        $files = $this->getFilesToScan();
        $projectInfo = $this->getProjectInfo();

        foreach ($this->analyzers as $name => $analyzer) {
            $analyzerDiagnostics = $analyzer->analyze($files);
            $this->diagnostics = array_merge($this->diagnostics, $analyzerDiagnostics);
        }

        $score = $this->calculateScore();
        $elapsedMs = (microtime(true) - $startTime) * 1000;

        return [
            'project' => $projectInfo,
            'score' => $score,
            'diagnostics' => $this->diagnostics,
            'stats' => [
                'total_issues' => count($this->diagnostics),
                'errors' => count(array_filter($this->diagnostics, fn($d) => $d['severity'] === 'error')),
                'warnings' => count(array_filter($this->diagnostics, fn($d) => $d['severity'] === 'warning')),
                'files_scanned' => count($files),
                'elapsed_ms' => round($elapsedMs),
            ],
        ];
    }

    /**
     * Check a single file
     */
    public function checkFile(string $filePath): array
    {
        $this->diagnostics = [];

        foreach ($this->analyzers as $analyzer) {
            $analyzerDiagnostics = $analyzer->analyzeFile($filePath);
            $this->diagnostics = array_merge($this->diagnostics, $analyzerDiagnostics);
        }

        return $this->diagnostics;
    }

    /**
     * Get list of PHP files to scan
     */
    private function getFilesToScan(): array
    {
        $files = [];
        $basePath = $this->options['path'];

        // Determine what to scan
        $scanPaths = [];

        // Check if path is wp-content, plugins dir, or other
        $pluginsDir = null;
        $themesDir = null;
        $wpContentPath = null;

        if (basename($basePath) === 'wp-content') {
            $wpContentPath = $basePath;
            $pluginsDir = $basePath . '/plugins';
            $themesDir = $basePath . '/themes';
        } elseif (basename($basePath) === 'plugins') {
            // Direct plugins directory
            $pluginsDir = $basePath;
        } elseif (basename($basePath) === 'themes') {
            // Direct themes directory
            $themesDir = $basePath;
        } elseif (is_dir($basePath . '/plugins') && is_dir($basePath . '/themes')) {
            $wpContentPath = $basePath;
            $pluginsDir = $basePath . '/plugins';
            $themesDir = $basePath . '/themes';
        }

        // Use WPDetector for custom-only mode (default when scanning full wp-content)
        $customOnly = $this->options['custom_only'] ?? ($wpContentPath !== null);
        $interactive = $this->options['interactive'] ?? true;

        if ($customOnly && $wpContentPath) {
            $detector = new WPDetector($wpContentPath, $interactive);

            // Get custom plugins only
            if (!$this->options['themes_only']) {
                $pluginResult = $detector->getPluginsToScan();
                foreach ($pluginResult['custom'] as $slug => $data) {
                    $scanPaths[] = $data['path'];
                }
            }

            // Get active theme only
            if (!$this->options['plugins_only']) {
                $themePaths = $detector->getActiveThemePaths();
                $scanPaths = array_merge($scanPaths, $themePaths);
            }
        } else {
            // Legacy behavior: scan all plugins/themes
            if ($this->options['plugins_only'] && $pluginsDir) {
                $scanPaths[] = $pluginsDir;
            } elseif ($this->options['themes_only'] && $themesDir) {
                $scanPaths[] = $themesDir;
            } else {
                if ($pluginsDir && is_dir($pluginsDir)) {
                    $scanPaths[] = $pluginsDir;
                }
                if ($themesDir && is_dir($themesDir)) {
                    $scanPaths[] = $themesDir;
                }

                // Also scan mu-plugins if exists
                $muPluginsDir = dirname($basePath) . '/mu-plugins';
                if (is_dir($muPluginsDir)) {
                    $scanPaths[] = $muPluginsDir;
                }
            }
        }

        // If no standard paths found, scan the given path directly
        if (empty($scanPaths)) {
            $scanPaths[] = $basePath;
        }

        foreach ($scanPaths as $path) {
            if (is_dir($path)) {
                $files = array_merge($files, $this->scanDirectory($path));
            }
        }

        return $files;
    }

    /**
     * Recursively scan directory for PHP files
     */
    private function scanDirectory(string $dir): array
    {
        $files = [];

        // Skip vendor, node_modules, third-party libs, and user-specified ignores
        $skipDirs = ['vendor', 'node_modules', '.git', 'tests', 'test', 'aws', 'GuzzleHttp', 'Guzzle', 'Symfony', 'Psr'];
        $ignorePatterns = $this->options['ignore'] ?? [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                function ($file, $key, $iterator) use ($skipDirs, $ignorePatterns) {
                    $filename = $file->getFilename();
                    $path = $file->getPathname();

                    // Skip standard directories
                    if ($iterator->hasChildren() && in_array($filename, $skipDirs)) {
                        return false;
                    }

                    // Skip user-specified patterns
                    foreach ($ignorePatterns as $pattern) {
                        if (strpos($path, $pattern) !== false || fnmatch($pattern, $filename)) {
                            return false;
                        }
                    }

                    return true;
                }
            )
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Get project information (standalone mode - no WordPress)
     */
    private function getProjectInfo(): array
    {
        $path = $this->options['path'];

        return [
            'name' => basename(dirname($path)),
            'path' => $path,
            'php_version' => PHP_VERSION,
            'mode' => 'standalone',
        ];
    }

    /**
     * Calculate health score (0-100)
     *
     * Based on unique rules violated (same approach as react-doctor):
     * - Counts distinct rule types, not total violations
     * - ERROR_RULE_PENALTY = 1.5 per unique error rule
     * - WARNING_RULE_PENALTY = 0.75 per unique warning rule
     * - Score = 100 - penalties
     */
    private function calculateScore(): array
    {
        // Count unique rules (not total violations)
        $errorRules = [];
        $warningRules = [];

        foreach ($this->diagnostics as $d) {
            $ruleKey = $d['category'] . '/' . $d['rule'];
            if ($d['severity'] === 'error') {
                $errorRules[$ruleKey] = true;
            } else {
                $warningRules[$ruleKey] = true;
            }
        }

        $errorRuleCount = count($errorRules);
        $warningRuleCount = count($warningRules);

        // Same penalties as react-doctor
        $penalty = ($errorRuleCount * 1.5) + ($warningRuleCount * 0.75);
        $score = (int) max(0, min(100, round(100 - $penalty)));

        $label = match (true) {
            $score >= 75 => 'Great',
            $score >= 50 => 'Needs Work',
            default => 'Critical',
        };

        $grade = match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };

        return [
            'score' => $score,
            'label' => $label,
            'grade' => $grade,
            'error_rules' => $errorRuleCount,
            'warning_rules' => $warningRuleCount,
        ];
    }
}
