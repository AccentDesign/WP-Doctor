<?php

namespace AccentDesign\WPDoctor\Analyzers;

/**
 * Detects potential security issues in WordPress code.
 */
class SecurityAnalyzer extends BaseAnalyzer
{
    public function getCategory(): string
    {
        return 'security';
    }

    public function analyzeFile(string $filePath): array
    {
        $diagnostics = [];
        $content = $this->readFile($filePath);

        if ($content === null) {
            return $diagnostics;
        }

        // Check for direct database queries without prepare
        $diagnostics = array_merge($diagnostics, $this->checkSqlInjection($filePath, $content));

        // Check for missing nonce verification
        $diagnostics = array_merge($diagnostics, $this->checkNonceVerification($filePath, $content));

        // Check for unescaped output
        $diagnostics = array_merge($diagnostics, $this->checkUnescapedOutput($filePath, $content));

        // Check for file inclusion vulnerabilities
        $diagnostics = array_merge($diagnostics, $this->checkFileInclusion($filePath, $content));

        // Check for eval and similar dangerous functions
        $diagnostics = array_merge($diagnostics, $this->checkDangerousFunctions($filePath, $content));

        // Check for capability checks
        $diagnostics = array_merge($diagnostics, $this->checkCapabilityChecks($filePath, $content));

        return $diagnostics;
    }

    /**
     * Check for potential SQL injection vulnerabilities
     * Only reports when variables are used without obvious sanitization
     */
    private function checkSqlInjection(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Pattern: $wpdb->query with concatenated/interpolated SQL
        $patterns = [
            '/\$wpdb\s*->\s*query\s*\(\s*["\'][^"\']*\$/' => '$wpdb->query() with variable interpolation',
            '/\$wpdb\s*->\s*get_results\s*\(\s*["\'][^"\']*\$/' => '$wpdb->get_results() with variable interpolation',
            '/\$wpdb\s*->\s*get_var\s*\(\s*["\'][^"\']*\$/' => '$wpdb->get_var() with variable interpolation',
            '/\$wpdb\s*->\s*get_row\s*\(\s*["\'][^"\']*\$/' => '$wpdb->get_row() with variable interpolation',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);

                    // Check broader context for sanitization
                    $contextStart = max(0, $match[1] - 500);
                    $context = substr($content, $contextStart, 1000);

                    // Skip if prepare, intval, absint, or esc_sql used
                    $isSafe = preg_match('/(prepare|intval|absint|esc_sql|sanitize_\w+)\s*\(/', $context);

                    if ($isSafe) {
                        continue;
                    }

                    // Extract just the SQL query string from the match
                    $queryStart = strpos($content, $match[0]);
                    $afterQuery = substr($content, $queryStart, 500);

                    // Find the quoted SQL string - look for closing quote/parenthesis
                    if (preg_match('/["\']([^"\']*)["\']/', $afterQuery, $sqlMatch)) {
                        $sqlString = $sqlMatch[1];

                        // Skip if only using safe WordPress variables in the SQL string
                        $sqlWithoutSafeVars = preg_replace('/\{?\$(?:this->)?(?:table_prefix|wpdb)\}?/', '', $sqlString);
                        if (strpos($sqlWithoutSafeVars, '$') === false) {
                            continue;
                        }
                    }

                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning', // Changed from error - needs manual review
                        'sql-injection',
                        $message,
                        'Use $wpdb->prepare() or sanitize input with intval/absint/esc_sql'
                    );
                }
            }
        }

        return $diagnostics;
    }

    /**
     * Check for missing nonce verification in form handlers
     * Only checks $_POST since $_GET is often used for safe read operations
     */
    private function checkNonceVerification(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Only check $_POST - $_GET is typically safe for read operations (pagination, filters)
        // $_REQUEST is a mix, but often used for safe operations
        $pattern = '/\$_POST\s*\[/';

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            // Check if file has PROPER nonce verification (not just generation or lenient checks)
            // wp_nonce_field/wp_create_nonce generate nonces but don't verify them
            // isset($_POST['nonce']) && wp_verify_nonce is lenient - passes if no nonce sent
            $hasWpVerifyNonce = strpos($content, 'wp_verify_nonce') !== false;
            $hasAdminReferer = strpos($content, 'check_admin_referer') !== false;
            $hasAjaxReferer = strpos($content, 'check_ajax_referer') !== false;

            // Detect lenient pattern: isset($_POST['nonce']) && ... wp_verify_nonce
            // This is lazy - it only checks nonce IF provided, so attacker can skip it
            $hasLenientPattern = preg_match('/isset\s*\(\s*\$_POST\s*\[\s*[\'"]nonce[\'"]\s*\]\s*\)\s*&&[^;]*wp_verify_nonce/', $content);

            // Only count as protected if has real verification, not lenient
            $hasFileNonceCheck = ($hasWpVerifyNonce && !$hasLenientPattern) || $hasAdminReferer || $hasAjaxReferer;

            // Check for reCAPTCHA verification - valid CSRF alternative for public forms
            $hasRecaptchaCheck =
                strpos($content, 'recaptcha') !== false ||
                strpos($content, 'ReCaptcha') !== false ||
                strpos($content, 'g-recaptcha-response') !== false ||
                strpos($content, 'verify_recaptcha') !== false;

            // Check for WordPress comment/user hooks which handle nonces internally
            $isWordPressHook =
                strpos($content, 'wp_insert_comment') !== false ||
                strpos($content, 'comment_post') !== false ||
                strpos($content, 'user_register') !== false ||
                strpos($content, 'profile_update') !== false ||
                strpos($content, 'redirect_post_location') !== false ||
                strpos($content, 'save_post') !== false;

            // If file has nonce checks, reCAPTCHA, or is a WP hook handler, assume proper validation
            if ($hasFileNonceCheck || $hasRecaptchaCheck || $isWordPressHook) {
                return $diagnostics;
            }

            // Only report once per file, not per usage
            $diagnostics[] = $this->createDiagnostic(
                $filePath,
                $this->findLineNumber($content, $matches[0][0][1]),
                'warning',
                'missing-nonce',
                '$_POST used without visible nonce verification in file',
                'Consider using wp_verify_nonce() or check_admin_referer()'
            );
        }

        return $diagnostics;
    }

    /**
     * Check for unescaped output - DISABLED
     * This check produces too many false positives because:
     * 1. Variables may be sanitized on input (stored safe in DB)
     * 2. Variables may be numeric IDs (always safe)
     * 3. Variables may come from trusted sources
     * 4. Context is hard to determine statically
     */
    private function checkUnescapedOutput(string $filePath, string $content): array
    {
        // Disabled - too many false positives without proper data flow analysis
        return [];
    }

    /**
     * Check for file inclusion vulnerabilities - DISABLED
     * Too many false positives - dynamic includes are commonly used with:
     * - __DIR__ . '/partials/' . $file
     * - plugin_dir_path() based paths
     * - Validated allowlist variables
     * Would need data flow analysis to be accurate.
     */
    private function checkFileInclusion(string $filePath, string $content): array
    {
        // Disabled - too many false positives
        return [];

        return $diagnostics;
    }

    /**
     * Check for dangerous functions - only truly dangerous patterns
     * Removed call_user_func (standard WP pattern) and shell functions
     * (used legitimately by WP for image processing, etc.)
     */
    private function checkDangerousFunctions(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Only flag truly dangerous functions that should almost never be used
        $dangerousFunctions = [
            'eval' => 'eval() is extremely dangerous and should be avoided',
            'assert' => 'assert() can evaluate code strings in older PHP',
            'create_function' => 'create_function() is deprecated and can execute arbitrary code',
        ];

        foreach ($dangerousFunctions as $func => $message) {
            $pattern = '/\b' . preg_quote($func, '/') . '\s*\(/';

            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning', // Changed from error - may be legitimate
                        'dangerous-function',
                        $message,
                        'Consider using safer alternatives'
                    );
                }
            }
        }

        // Special check for preg_replace with /e modifier (code execution)
        if (preg_match_all('/preg_replace\s*\(\s*["\'][^"\']*\/[a-zA-Z]*e[a-zA-Z]*["\']/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'error',
                    'preg-replace-eval',
                    'preg_replace() with /e modifier executes code (deprecated in PHP 7)',
                    'Use preg_replace_callback() instead'
                );
            }
        }

        return $diagnostics;
    }

    /**
     * Check for capability checks in admin/AJAX handlers
     */
    private function checkCapabilityChecks(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Check for admin-ajax handlers without capability checks
        if (preg_match_all('/add_action\s*\(\s*[\'"]wp_ajax_(nopriv_)?(\w+)[\'"]\s*,\s*(?:array\s*\(\s*\$this\s*,\s*[\'"](\w+)[\'"]\s*\)|[\'"](\w+)[\'"]|\[\s*\$this\s*,\s*[\'"](\w+)[\'"]\s*\])/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $isNopriv = !empty($matches[1][$i][0]);
                $actionName = $matches[2][$i][0];
                $line = $this->findLineNumber($content, $match[1]);

                // Extract callback method name
                $callbackMethod = '';
                if (!empty($matches[3][$i][0])) {
                    $callbackMethod = $matches[3][$i][0]; // array($this, 'method')
                } elseif (!empty($matches[4][$i][0])) {
                    $callbackMethod = $matches[4][$i][0]; // 'function_name'
                } elseif (!empty($matches[5][$i][0])) {
                    $callbackMethod = $matches[5][$i][0]; // [$this, 'method']
                }

                // Skip nopriv handlers - they're intentionally public
                if ($isNopriv) {
                    continue;
                }

                // Check if there's a paired nopriv handler (makes this a public endpoint)
                $hasNoprivPair = strpos($content, "wp_ajax_nopriv_{$actionName}") !== false;
                if ($hasNoprivPair) {
                    continue;
                }

                // First check surrounding context of add_action
                $context = substr($content, $match[1], 1000);
                $hasCapCheck =
                    strpos($context, 'current_user_can') !== false ||
                    strpos($context, 'is_admin') !== false ||
                    strpos($context, 'user_can') !== false;

                // If not found in context, check inside the callback method itself
                if (!$hasCapCheck && !empty($callbackMethod)) {
                    $methodBody = $this->extractMethodBody($content, $callbackMethod);
                    if ($methodBody) {
                        $hasCapCheck =
                            strpos($methodBody, 'current_user_can') !== false ||
                            strpos($methodBody, 'is_admin') !== false ||
                            strpos($methodBody, 'user_can') !== false ||
                            strpos($methodBody, 'verify_capability') !== false;
                    }
                }

                if (!$hasCapCheck) {
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'missing-capability-check',
                        sprintf('AJAX handler wp_ajax_%s may lack capability check', $actionName),
                        'Use current_user_can() to verify user permissions'
                    );
                }
            }
        }

        return $diagnostics;
    }

    /**
     * Extract the body of a method from class content
     */
    private function extractMethodBody(string $content, string $methodName): ?string
    {
        // Find the method definition
        $pattern = '/(?:public|private|protected)?\s*function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*\{/';
        if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $startPos = $matches[0][1];
        $braceCount = 0;
        $inString = false;
        $stringChar = '';
        $methodBody = '';
        $foundFirstBrace = false;

        for ($i = $startPos; $i < strlen($content); $i++) {
            $char = $content[$i];
            $prevChar = $i > 0 ? $content[$i - 1] : '';

            // Track string state
            if (($char === '"' || $char === "'") && $prevChar !== '\\') {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    $inString = false;
                }
            }

            // Track braces only outside strings
            if (!$inString) {
                if ($char === '{') {
                    $braceCount++;
                    $foundFirstBrace = true;
                } elseif ($char === '}') {
                    $braceCount--;
                }
            }

            $methodBody .= $char;

            // End of method
            if ($foundFirstBrace && $braceCount === 0) {
                break;
            }
        }

        return $methodBody;
    }
}
