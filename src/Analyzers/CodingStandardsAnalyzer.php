<?php

namespace AccentDesign\WPDoctor\Analyzers;

/**
 * Checks for WordPress coding standards violations.
 */
class CodingStandardsAnalyzer extends BaseAnalyzer
{
    public function getCategory(): string
    {
        return 'coding-standards';
    }

    public function analyzeFile(string $filePath): array
    {
        $diagnostics = [];
        $content = $this->readFile($filePath);

        if ($content === null) {
            return $diagnostics;
        }

        // Check for Yoda conditions
        $diagnostics = array_merge($diagnostics, $this->checkYodaConditions($filePath, $content));

        // Check for proper spacing
        $diagnostics = array_merge($diagnostics, $this->checkSpacing($filePath, $content));

        // Check for proper escaping in output
        $diagnostics = array_merge($diagnostics, $this->checkOutputEscaping($filePath, $content));

        // Check for proper text domain usage
        $diagnostics = array_merge($diagnostics, $this->checkTextDomain($filePath, $content));

        // Check for proper file organization
        $diagnostics = array_merge($diagnostics, $this->checkFileOrganization($filePath, $content));

        return $diagnostics;
    }

    /**
     * Check for Yoda conditions (WP prefers them for security)
     */
    private function checkYodaConditions(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Pattern: $variable === 'constant' (non-Yoda)
        // Should be: 'constant' === $variable (Yoda)
        if (preg_match_all('/if\s*\(\s*\$\w+\s*(===?|!==?)\s*([\'"][^\'"]*[\'"]|true|false|null|\d+)\s*\)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'yoda-condition',
                    'Non-Yoda condition detected',
                    'WordPress coding standards prefer Yoda conditions: \'value\' === $variable'
                );
            }
        }

        return $diagnostics;
    }

    /**
     * Check for proper spacing around operators and parentheses
     */
    private function checkSpacing(string $filePath, string $content): array
    {
        $diagnostics = [];
        $lines = $this->getLines($filePath);

        foreach ($lines as $lineNum => $line) {
            // Skip lines that are comments
            $trimmed = trim($line);
            if (strpos($trimmed, '//') === 0 || strpos($trimmed, '*') === 0 || strpos($trimmed, '#') === 0) {
                continue;
            }

            // Check for missing space after comma
            if (preg_match('/,[^\s\'"\d]/', $line) && strpos($line, '//') === false) {
                // Avoid false positives in strings
                if (!preg_match('/[\'"][^\'"]*(,[^\s])[^\'"]*[\'"]/', $line)) {
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $lineNum + 1,
                        'warning',
                        'spacing-comma',
                        'Missing space after comma',
                        'Add space after commas for readability'
                    );
                }
            }

            // Check for space before opening parenthesis in function calls
            // WordPress standard: no space before parenthesis
            if (preg_match('/\w\s+\(/', $line)) {
                // Exclude control structures which should have space
                if (!preg_match('/\b(if|else|elseif|for|foreach|while|switch|catch|function|array)\s+\(/', $line)) {
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $lineNum + 1,
                        'warning',
                        'spacing-paren',
                        'Extra space before parenthesis',
                        'Function calls should have no space before parenthesis: function_name()'
                    );
                }
            }
        }

        return $diagnostics;
    }

    /**
     * Check for proper output escaping
     */
    private function checkOutputEscaping(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Check for printf/sprintf with translated strings
        if (preg_match_all('/printf\s*\(\s*__\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);

                // Check if escaped version is used
                $context = substr($content, max(0, $match[1] - 50), 100);
                if (strpos($context, 'esc_html') === false && strpos($context, 'esc_attr') === false) {
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'printf-escape',
                        'printf() with __() should use esc_html__() or esc_attr__()',
                        'Use esc_html__() for HTML context or esc_attr__() for attributes'
                    );
                }
            }
        }

        // Check for direct output of translated strings without escaping
        if (preg_match_all('/echo\s+__\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'echo-translate-escape',
                    'echo __() should use esc_html_e() or proper escaping',
                    'Use esc_html_e() for direct output of translated strings'
                );
            }
        }

        return $diagnostics;
    }

    /**
     * Check for proper text domain usage
     */
    private function checkTextDomain(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Find translation function calls
        $translationFunctions = ['__', '_e', '_x', '_ex', '_n', '_nx', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e'];

        foreach ($translationFunctions as $func) {
            $pattern = '/\b' . preg_quote($func, '/') . '\s*\(\s*[\'"][^\'"]+[\'"]\s*\)/';

            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'missing-textdomain',
                        sprintf('%s() called without text domain', $func),
                        'Add text domain as second parameter for proper i18n'
                    );
                }
            }
        }

        // Check for hardcoded 'default' text domain
        if (preg_match_all('/(__|\b_[enx]+)\s*\([^)]+,\s*[\'"]default[\'"]\s*\)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'default-textdomain',
                    'Using \'default\' as text domain',
                    'Use your plugin/theme\'s text domain instead of \'default\''
                );
            }
        }

        return $diagnostics;
    }

    /**
     * Check for proper file organization
     */
    private function checkFileOrganization(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Check for multiple classes in one file
        if (preg_match_all('/\bclass\s+\w+/', $content, $matches)) {
            if (count($matches[0]) > 1) {
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    1,
                    'warning',
                    'multiple-classes',
                    sprintf('%d classes defined in single file', count($matches[0])),
                    'WordPress coding standards recommend one class per file'
                );
            }
        }

        // Check for missing file header comment
        $firstNonEmpty = '';
        $lines = $this->getLines($filePath);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && $trimmed !== '<?php') {
                $firstNonEmpty = $trimmed;
                break;
            }
        }

        if ($firstNonEmpty !== '' && strpos($firstNonEmpty, '/**') !== 0 && strpos($firstNonEmpty, '/*') !== 0) {
            $diagnostics[] = $this->createDiagnostic(
                $filePath,
                1,
                'warning',
                'missing-file-header',
                'Missing file header docblock',
                'Add a file header comment with @package and description'
            );
        }

        // Check for closing PHP tag (should be omitted in pure PHP files)
        $trimmedContent = rtrim($content);
        if (preg_match('/\?>\s*$/', $trimmedContent)) {
            // Only flag if it's a pure PHP file (no HTML mixed in)
            if (substr_count($content, '<?php') === 1 && strpos($content, '?>') === strlen($trimmedContent) - 2) {
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    count($lines),
                    'warning',
                    'closing-php-tag',
                    'Closing PHP tag at end of file',
                    'Omit the closing ?> tag in pure PHP files to prevent whitespace issues'
                );
            }
        }

        return $diagnostics;
    }
}
