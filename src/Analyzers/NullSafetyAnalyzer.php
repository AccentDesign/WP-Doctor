<?php

namespace AccentDesign\WPDoctor\Analyzers;

/**
 * Detects potential null safety issues for PHP 8 compatibility.
 */
class NullSafetyAnalyzer extends BaseAnalyzer
{
    /**
     * Functions that commonly receive null and trigger warnings in PHP 8
     */
    private const NULL_SENSITIVE_FUNCTIONS = [
        'strlen',
        'strpos',
        'str_replace',
        'str_ireplace',
        'substr',
        'strtolower',
        'strtoupper',
        'trim',
        'ltrim',
        'rtrim',
        'stripslashes',
        'htmlspecialchars',
        'htmlentities',
        'html_entity_decode',
        'preg_match',
        'preg_replace',
        'preg_split',
        'explode',
        'implode',
        'array_key_exists',
        'in_array',
        'array_search',
        'count',
        'json_decode',
        'json_encode',
        'sprintf',
        'printf',
        'number_format',
        'round',
        'floor',
        'ceil',
        'abs',
        'intval',
        'floatval',
        'strval',
    ];

    /**
     * WordPress functions that may return null
     */
    private const WP_NULLABLE_RETURNS = [
        'get_post_meta' => 'May return empty string, array, or false',
        'get_option' => 'Returns false if not found, or mixed',
        'get_user_meta' => 'May return empty string, array, or false',
        'get_term_meta' => 'May return empty string, array, or false',
        'get_post' => 'Returns null if not found',
        'get_term' => 'Returns null or WP_Error',
        'get_user_by' => 'Returns false if not found',
        'get_userdata' => 'Returns false if not found',
        'get_the_title' => 'May return empty string',
        'get_the_content' => 'May return empty string',
        'get_the_excerpt' => 'May return empty string',
        'wp_get_attachment_url' => 'Returns false on failure',
        'get_permalink' => 'Returns false on failure',
        'get_page_by_path' => 'Returns null if not found',
        'get_posts' => 'Returns empty array if none found',
        'get_terms' => 'Returns WP_Error on failure',
    ];

    public function getCategory(): string
    {
        return 'null-safety';
    }

    public function analyzeFile(string $filePath): array
    {
        $diagnostics = [];
        $content = $this->readFile($filePath);

        if ($content === null) {
            return $diagnostics;
        }

        $lines = $this->getLines($filePath);

        // Check for direct use of nullable WordPress functions in string operations
        foreach (self::NULL_SENSITIVE_FUNCTIONS as $func) {
            // Pattern: func(get_post_meta(...)) or func($var) where $var could be null
            foreach (self::WP_NULLABLE_RETURNS as $wpFunc => $note) {
                $pattern = '/\b' . preg_quote($func, '/') . '\s*\(\s*' . preg_quote($wpFunc, '/') . '\s*\(/';

                if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $line = $this->findLineNumber($content, $match[1]);
                        $lineContent = $lines[$line - 1] ?? '';

                        // Skip if line has null coalescing or ternary fallback
                        if (strpos($lineContent, '??') !== false || strpos($lineContent, '?:') !== false) {
                            continue;
                        }

                        $diagnostics[] = $this->createDiagnostic(
                            $filePath,
                            $line,
                            'warning',
                            'null-to-string-function',
                            sprintf('%s() called with %s() which may return null/false', $func, $wpFunc),
                            sprintf('Add null coalescing (?? \'\') or check return value. %s', $note)
                        );
                    }
                }
            }
        }

        // Check for potentially unsafe stripslashes usage
        if (preg_match_all('/stripslashes\s*\(\s*\$([a-zA-Z_][a-zA-Z0-9_]*(?:\[[^\]]*\])*(?:->[\w]+)*)\s*\)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $varName = $matches[1][$i][0];
                $line = $this->findLineNumber($content, $match[1]);

                // Check if there's a null coalescing, ternary, or null check nearby
                $lineContent = $lines[$line - 1] ?? '';
                if (strpos($lineContent, '??') === false &&
                    strpos($lineContent, 'if') === false &&
                    strpos($lineContent, '!== null') === false &&
                    strpos($lineContent, '!= null') === false &&
                    strpos($lineContent, '?') === false) {
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'stripslashes-null',
                        sprintf('stripslashes($%s) may receive null in PHP 8', $varName),
                        'Use stripslashes($var ?? \'\') or check for null first'
                    );
                }
            }
        }

        // Check for array_key_exists on potentially null arrays
        if (preg_match_all('/array_key_exists\s*\(\s*[^,]+,\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $varName = $matches[1][$i][0];
                $line = $this->findLineNumber($content, $match[1]);
                $lineContent = $lines[$line - 1] ?? '';

                // Skip if line has is_array check or null coalescing
                if (strpos($lineContent, 'is_array') !== false || strpos($lineContent, '??') !== false) {
                    continue;
                }

                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'array-key-exists-null',
                    sprintf('array_key_exists() on $%s - ensure array is not null', $varName),
                    'Add is_array() check or use isset() which handles null safely'
                );
            }
        }

        // Check for count() on potentially null values
        if (preg_match_all('/\bcount\s*\(\s*\$([a-zA-Z_][a-zA-Z0-9_]*(?:\[[^\]]*\])*)\s*\)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $varName = $matches[1][$i][0];
                $line = $this->findLineNumber($content, $match[1]);
                $lineContent = $lines[$line - 1] ?? '';

                // Skip if already has is_array check or null coalescing
                if (strpos($lineContent, 'is_array') === false && strpos($lineContent, '??') === false) {
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'count-null',
                        sprintf('count($%s) - passing null to count() is deprecated in PHP 8', $varName),
                        'Use count($var ?? []) or is_countable() check'
                    );
                }
            }
        }

        // Check for foreach on potentially null values from WP functions
        foreach (self::WP_NULLABLE_RETURNS as $wpFunc => $note) {
            $pattern = '/foreach\s*\(\s*' . preg_quote($wpFunc, '/') . '\s*\([^)]*\)\s+as\b/';

            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'foreach-nullable',
                        sprintf('foreach on %s() result without null check', $wpFunc),
                        'Assign to variable first and check with is_array() or use ?? []'
                    );
                }
            }
        }

        return $diagnostics;
    }
}
