<?php

namespace AccentDesign\WPDoctor\Reporters;

/**
 * Formats and outputs diagnostic results.
 * Works standalone without WP_CLI dependency.
 */
class Reporter
{
    private string $format;
    private bool $verbose;

    // ANSI colors
    private const RESET = "\033[0m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const CYAN = "\033[36m";
    private const BOLD = "\033[1m";

    public function __construct(string $format = 'table', bool $verbose = false)
    {
        $this->format = $format;
        $this->verbose = $verbose;
    }

    /**
     * Output full scan results
     */
    public function output(array $result): void
    {
        switch ($this->format) {
            case 'json':
                $this->outputJson($result);
                break;
            case 'summary':
                $this->outputSummary($result);
                break;
            case 'table':
            default:
                $this->outputTable($result);
                break;
        }
    }

    /**
     * Output results for a single file
     */
    public function outputFileResult(string $filePath, array $diagnostics): void
    {
        $this->log(sprintf('File: %s', $filePath));
        $this->log(sprintf('Issues: %d', count($diagnostics)));
        $this->log('');

        if (empty($diagnostics)) {
            $this->success('No issues found!');
            return;
        }

        if ($this->format === 'json') {
            $this->log(json_encode($diagnostics, JSON_PRETTY_PRINT));
            return;
        }

        foreach ($diagnostics as $diagnostic) {
            $this->outputDiagnostic($diagnostic);
        }
    }

    /**
     * Output as JSON
     */
    private function outputJson(array $result): void
    {
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * Output as summary
     */
    private function outputSummary(array $result): void
    {
        $this->outputScoreCard($result);
        $this->log('');

        // Category breakdown
        $categories = [];
        foreach ($result['diagnostics'] as $d) {
            $cat = $d['category'] ?? 'unknown';
            if (!isset($categories[$cat])) {
                $categories[$cat] = ['errors' => 0, 'warnings' => 0];
            }
            if ($d['severity'] === 'error') {
                $categories[$cat]['errors']++;
            } else {
                $categories[$cat]['warnings']++;
            }
        }

        if (!empty($categories)) {
            $this->log('Issues by category:');
            foreach ($categories as $cat => $counts) {
                $this->log(sprintf(
                    '  %-20s %d errors, %d warnings',
                    $cat,
                    $counts['errors'],
                    $counts['warnings']
                ));
            }
        }

        $this->log('');
        $this->log('Run with --verbose for detailed file locations.');
    }

    /**
     * Output as table format
     */
    private function outputTable(array $result): void
    {
        $this->outputScoreCard($result);
        $this->log('');

        if (empty($result['diagnostics'])) {
            $this->success('No issues found!');
            return;
        }

        // Group by category
        $byCategory = [];
        foreach ($result['diagnostics'] as $d) {
            $cat = $d['category'] ?? 'unknown';
            $byCategory[$cat][] = $d;
        }

        foreach ($byCategory as $category => $diagnostics) {
            $this->log(sprintf('─── %s (%d) ───', strtoupper($category), count($diagnostics)));
            $this->log('');

            foreach ($diagnostics as $diagnostic) {
                $this->outputDiagnostic($diagnostic);
            }

            $this->log('');
        }

        // Stats summary
        $this->log(sprintf(
            'Scanned %d files in %dms',
            $result['stats']['files_scanned'],
            $result['stats']['elapsed_ms']
        ));
    }

    /**
     * Output the score card
     */
    private function outputScoreCard(array $result): void
    {
        $score = $result['score']['score'];
        $label = $result['score']['label'];

        // Color based on score
        if ($score >= 75) {
            $color = self::GREEN;
        } elseif ($score >= 50) {
            $color = self::YELLOW;
        } else {
            $color = self::RED;
        }

        $this->log('╔════════════════════════════════════════╗');
        $this->log('║           WP DOCTOR REPORT             ║');
        $this->log('╠════════════════════════════════════════╣');
        $this->log(sprintf('║  Health Score: %s%-3d%s (%s)%s║',
            $color,
            $score,
            self::RESET,
            str_pad($label, 12),
            str_repeat(' ', 9)
        ));
        $this->log(sprintf('║  Errors: %-5d    Warnings: %-5d    ║',
            $result['stats']['errors'],
            $result['stats']['warnings']
        ));
        $this->log('╚════════════════════════════════════════╝');

        // Project info
        if (isset($result['project'])) {
            $this->log('');
            if (isset($result['project']['wordpress_version'])) {
                $this->log(sprintf('WordPress %s | PHP %s | Theme: %s',
                    $result['project']['wordpress_version'],
                    $result['project']['php_version'],
                    $result['project']['active_theme'] ?? 'unknown'
                ));
            } else {
                $this->log(sprintf('PHP %s | Path: %s',
                    $result['project']['php_version'],
                    $result['project']['path'] ?? 'unknown'
                ));
            }
        }
    }

    /**
     * Output a single diagnostic
     */
    private function outputDiagnostic(array $diagnostic): void
    {
        $icon = $diagnostic['severity'] === 'error' ? '✖' : '⚠';
        $color = $diagnostic['severity'] === 'error' ? self::RED : self::YELLOW;

        if ($this->verbose) {
            // Verbose: show file path and line
            $this->log(sprintf(
                '%s%s%s %s:%d',
                $color,
                $icon,
                self::RESET,
                $this->shortenPath($diagnostic['file']),
                $diagnostic['line']
            ));
            $this->log(sprintf('    %s', $diagnostic['message']));
            if (!empty($diagnostic['suggestion'])) {
                $this->log(sprintf('    %s→%s %s', self::CYAN, self::RESET, $diagnostic['suggestion']));
            }
        } else {
            // Compact: single line
            $this->log(sprintf(
                '%s%s%s [%s] %s',
                $color,
                $icon,
                self::RESET,
                $diagnostic['rule'],
                $diagnostic['message']
            ));
        }
    }

    /**
     * Shorten file path for display
     */
    private function shortenPath(string $path): string
    {
        // Remove wp-content prefix and show from there
        if (strpos($path, 'wp-content/') !== false) {
            return substr($path, strpos($path, 'wp-content/'));
        }

        return $path;
    }

    /**
     * Log a message
     */
    private function log(string $message): void
    {
        echo $message . "\n";
    }

    /**
     * Log a success message
     */
    private function success(string $message): void
    {
        echo self::GREEN . "✓ " . $message . self::RESET . "\n";
    }

    /**
     * Log a warning message
     */
    private function warning(string $message): void
    {
        echo self::YELLOW . "⚠ " . $message . self::RESET . "\n";
    }

    /**
     * Log an error message
     */
    private function error(string $message): void
    {
        echo self::RED . "✖ " . $message . self::RESET . "\n";
    }
}
