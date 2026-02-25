<?php

namespace AccentDesign\WPDoctor\Analyzers;

/**
 * Detects issues with WordPress hooks (actions and filters).
 */
class HookAnalyzer extends BaseAnalyzer
{
    public function getCategory(): string
    {
        return 'hooks';
    }

    public function analyzeFile(string $filePath): array
    {
        $diagnostics = [];
        $content = $this->readFile($filePath);

        if ($content === null) {
            return $diagnostics;
        }

        // Check for hook priority issues
        $diagnostics = array_merge($diagnostics, $this->checkPriorityIssues($filePath, $content));

        // Check for incorrect hook usage
        $diagnostics = array_merge($diagnostics, $this->checkIncorrectHookUsage($filePath, $content));

        // Check for missing unhook patterns
        $diagnostics = array_merge($diagnostics, $this->checkMissingUnhooks($filePath, $content));

        // Check for deprecated hooks
        $diagnostics = array_merge($diagnostics, $this->checkDeprecatedHooks($filePath, $content));

        // Check for hook callback issues
        $diagnostics = array_merge($diagnostics, $this->checkCallbackIssues($filePath, $content));

        return $diagnostics;
    }

    /**
     * Check for hook priority issues
     */
    private function checkPriorityIssues(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Check for very high priorities (PHP_INT_MAX or 9999+)
        if (preg_match_all('/(add_action|add_filter)\s*\([^,]+,[^,]+,\s*(PHP_INT_MAX|\d{4,})\s*[,)]/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $priority = $matches[2][$i][0];
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'extreme-hook-priority',
                    sprintf('Hook with extreme priority (%s)', $priority),
                    'Very high priorities can cause conflicts. Consider using a reasonable priority (10-100).'
                );
            }
        }

        // Check for negative priorities
        if (preg_match_all('/(add_action|add_filter)\s*\([^,]+,[^,]+,\s*-\d+\s*[,)]/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'negative-hook-priority',
                    'Hook with negative priority',
                    'Negative priorities are unusual and may indicate a code smell'
                );
            }
        }

        return $diagnostics;
    }

    /**
     * Check for incorrect hook usage
     */
    private function checkIncorrectHookUsage(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Check for do_action with return value assignment
        if (preg_match_all('/\$\w+\s*=\s*do_action\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'error',
                    'do-action-return',
                    'do_action() does not return a value',
                    'Use apply_filters() if you need a return value'
                );
            }
        }

        // Check for apply_filters without capturing return value
        if (preg_match_all('/^\s*apply_filters\s*\([^;]+;/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);

                // Check if return value is used
                $lineContent = $match[0];
                if (strpos($lineContent, '=') === false && strpos($lineContent, 'return') === false) {
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'unused-filter-result',
                        'apply_filters() result not used',
                        'apply_filters() returns a value that should be used. Did you mean do_action()?'
                    );
                }
            }
        }

        // Check for hooks being added too late
        $lateHooks = [
            'wp_enqueue_scripts' => 'wp_enqueue_scripts',
            'admin_enqueue_scripts' => 'admin_enqueue_scripts',
            'login_enqueue_scripts' => 'login_enqueue_scripts',
        ];

        foreach ($lateHooks as $hook => $description) {
            // Check if hook is added inside template files (too late)
            if (strpos($filePath, 'template') !== false || strpos($filePath, 'page-') !== false) {
                if (preg_match_all('/add_action\s*\(\s*[\'"]' . preg_quote($hook, '/') . '[\'"]/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $line = $this->findLineNumber($content, $match[1]);
                        $diagnostics[] = $this->createDiagnostic(
                            $filePath,
                            $line,
                            'error',
                            'late-hook-registration',
                            sprintf('%s hook registered too late (in template file)', $hook),
                            'Register this hook in functions.php or a plugin file, not in templates'
                        );
                    }
                }
            }
        }

        return $diagnostics;
    }

    /**
     * Check for missing unhook patterns
     */
    private function checkMissingUnhooks(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Check for add_action/filter with $this without checking if already added
        if (preg_match_all('/(add_action|add_filter)\s*\([^,]+,\s*\[\s*\$this/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            // Check if the class uses singleton pattern or has already-added checks
            $hasSingleton = strpos($content, 'private static $instance') !== false ||
                           strpos($content, 'self::$instance') !== false;

            $hasAddedCheck = strpos($content, 'has_action') !== false ||
                            strpos($content, 'has_filter') !== false ||
                            strpos($content, 'did_action') !== false;

            if (!$hasSingleton && !$hasAddedCheck) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'hook-duplication-risk',
                        'Hook with $this callback may be added multiple times',
                        'Use singleton pattern or check with has_action()/has_filter() first'
                    );
                }
            }
        }

        return $diagnostics;
    }

    /**
     * Check for deprecated hooks
     */
    private function checkDeprecatedHooks(string $filePath, string $content): array
    {
        $diagnostics = [];

        $deprecatedHooks = [
            'wp_no_robots' => ['since' => '5.7', 'alternative' => 'wp_robots_no_robots'],
            'login_headertitle' => ['since' => '5.2', 'alternative' => 'login_headertext'],
            'login_headerurl' => ['since' => '5.2', 'alternative' => 'login_headerurl (different behavior)'],
            'woocommerce_add_to_cart_fragments' => ['since' => 'WC 3.0', 'alternative' => 'woocommerce_add_to_cart_fragments'],
            'loop_shop_per_page' => ['since' => 'WC 3.3', 'alternative' => 'Use WC settings or pre_option filter'],
            'get_the_generator_html' => ['since' => '4.4', 'alternative' => 'get_the_generator_{{type}}'],
            'twentytwenty_site_logo_args' => ['since' => 'theme dependent', 'alternative' => 'Check theme documentation'],
        ];

        foreach ($deprecatedHooks as $hook => $info) {
            $pattern = '/(add_action|add_filter|remove_action|remove_filter|do_action|apply_filters)\s*\(\s*[\'"]' . preg_quote($hook, '/') . '[\'"]/';

            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'deprecated-hook',
                        sprintf('Hook \'%s\' is deprecated since %s', $hook, $info['since']),
                        sprintf('Use %s instead', $info['alternative'])
                    );
                }
            }
        }

        return $diagnostics;
    }

    /**
     * Check for callback issues
     */
    private function checkCallbackIssues(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Check for callback with wrong number of accepted args
        if (preg_match_all('/(add_filter)\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*[^,]+,\s*\d+\s*,\s*(\d+)\s*\)/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $acceptedArgs = (int)$matches[2][$i][0];
                $line = $this->findLineNumber($content, $match[1]);

                // Check if the callback function signature matches
                // This is a simplified check - just flag if accepting more than 4 args (unusual)
                if ($acceptedArgs > 4) {
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'many-hook-args',
                        sprintf('Filter callback accepts %d arguments', $acceptedArgs),
                        'Large number of arguments is unusual. Verify this is intentional.'
                    );
                }
            }
        }

        // Check for anonymous functions in hooks (can\'t be removed)
        if (preg_match_all('/(add_action|add_filter)\s*\([^,]+,\s*function\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'anonymous-hook-callback',
                    'Anonymous function used as hook callback',
                    'Anonymous callbacks cannot be removed with remove_action/remove_filter. Use named functions for extensibility.'
                );
            }
        }

        return $diagnostics;
    }
}
