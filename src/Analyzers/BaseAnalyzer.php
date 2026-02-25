<?php

namespace AccentDesign\WPDoctor\Analyzers;

abstract class BaseAnalyzer implements AnalyzerInterface
{
    protected array $options;
    protected array $diagnostics = [];

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function analyze(array $files): array
    {
        $this->diagnostics = [];

        foreach ($files as $file) {
            $fileDiagnostics = $this->analyzeFile($file);
            $this->diagnostics = array_merge($this->diagnostics, $fileDiagnostics);
        }

        return $this->diagnostics;
    }

    /**
     * Create a diagnostic entry
     */
    protected function createDiagnostic(
        string $filePath,
        int $line,
        string $severity,
        string $rule,
        string $message,
        string $help = ''
    ): array {
        return [
            'file' => $filePath,
            'line' => $line,
            'severity' => $severity, // 'error' or 'warning'
            'category' => $this->getCategory(),
            'rule' => $rule,
            'message' => $message,
            'help' => $help,
        ];
    }

    /**
     * Read file content
     */
    protected function readFile(string $filePath): ?string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }

        return file_get_contents($filePath);
    }

    /**
     * Read file content with JavaScript stripped (for PHP-only analysis)
     */
    protected function readFilePHPOnly(string $filePath): ?string
    {
        $content = $this->readFile($filePath);
        if ($content === null) {
            return null;
        }

        // Remove script tags and their content to avoid JS false positives
        $content = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '/* JS_REMOVED */', $content);

        return $content;
    }

    /**
     * Get file lines as array
     */
    protected function getLines(string $filePath): array
    {
        $content = $this->readFile($filePath);
        if ($content === null) {
            return [];
        }

        return explode("\n", $content);
    }

    /**
     * Find line number for a pattern match
     */
    protected function findLineNumber(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }
}
