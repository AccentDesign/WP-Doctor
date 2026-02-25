<?php

namespace AccentDesign\WPDoctor\Analyzers;

/**
 * Detects potential performance issues in WordPress code.
 */
class PerformanceAnalyzer extends BaseAnalyzer
{
    public function getCategory(): string
    {
        return 'performance';
    }

    public function analyzeFile(string $filePath): array
    {
        $diagnostics = [];
        $content = $this->readFile($filePath);

        if ($content === null) {
            return $diagnostics;
        }

        // Check for query_posts usage
        $diagnostics = array_merge($diagnostics, $this->checkQueryPosts($filePath, $content));

        // Check for inefficient database queries
        $diagnostics = array_merge($diagnostics, $this->checkInefficientQueries($filePath, $content));

        // Check for missing transient caching
        $diagnostics = array_merge($diagnostics, $this->checkMissingCaching($filePath, $content));

        // Check for N+1 query patterns
        $diagnostics = array_merge($diagnostics, $this->checkNPlusOneQueries($filePath, $content));

        // Check for expensive operations in loops
        $diagnostics = array_merge($diagnostics, $this->checkLoopOperations($filePath, $content));

        // Check for unoptimized meta queries
        $diagnostics = array_merge($diagnostics, $this->checkMetaQueries($filePath, $content));

        return $diagnostics;
    }

    /**
     * Check for query_posts usage
     */
    private function checkQueryPosts(string $filePath, string $content): array
    {
        $diagnostics = [];

        if (preg_match_all('/\bquery_posts\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'error',
                    'query-posts',
                    'query_posts() modifies the main query and causes performance issues',
                    'Use WP_Query or get_posts() instead. query_posts() also breaks pagination.'
                );
            }
        }

        return $diagnostics;
    }

    /**
     * Check for inefficient database queries
     */
    private function checkInefficientQueries(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Check for posts_per_page = -1
        if (preg_match_all('/[\'"]posts_per_page[\'"]\s*=>\s*-1/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'unlimited-posts',
                    'posts_per_page => -1 loads all posts into memory',
                    'Use pagination or set a reasonable limit. Consider wp_count_posts() if only counting.'
                );
            }
        }

        // Check for numberposts = -1
        if (preg_match_all('/[\'"]numberposts[\'"]\s*=>\s*-1/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'unlimited-posts',
                    'numberposts => -1 loads all posts into memory',
                    'Set a reasonable limit or use pagination'
                );
            }
        }

        // Check for suppress_filters => false in critical queries
        if (preg_match_all('/[\'"]suppress_filters[\'"]\s*=>\s*false/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'suppress-filters-false',
                    'suppress_filters => false may bypass object caching',
                    'Consider using suppress_filters => true if caching is important'
                );
            }
        }

        return $diagnostics;
    }

    /**
     * Check for missing transient caching
     */
    private function checkMissingCaching(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Check for remote requests without caching
        $remoteFunctions = ['wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'file_get_contents', 'curl_exec'];

        foreach ($remoteFunctions as $func) {
            if (preg_match_all('/\b' . preg_quote($func, '/') . '\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);

                    // Check if transients are used nearby
                    $context = substr($content, max(0, $match[1] - 500), 1500);
                    $hasTransient =
                        strpos($context, 'get_transient') !== false ||
                        strpos($context, 'set_transient') !== false ||
                        strpos($context, 'wp_cache_get') !== false;

                    if ($hasTransient) {
                        continue;
                    }

                    // Skip php://input reads (POST body - not cacheable)
                    $lineContent = substr($content, $match[1], 200);
                    if (strpos($lineContent, 'php://input') !== false) {
                        continue;
                    }

                    // Skip local file reads (file_get_contents with local paths)
                    if ($func === 'file_get_contents') {
                        // Check if it's reading a local file (not http/https)
                        if (strpos($lineContent, 'http') === false &&
                            strpos($lineContent, '://') === false) {
                            continue;
                        }
                    }

                    // Skip reCAPTCHA/CAPTCHA verification (must be real-time)
                    if (strpos($context, 'recaptcha') !== false ||
                        strpos($context, 'captcha') !== false ||
                        strpos($context, 'siteverify') !== false ||
                        strpos($filePath, 'ReCaptcha') !== false ||
                        strpos($filePath, 'recaptcha') !== false ||
                        strpos($filePath, 'Captcha') !== false) {
                        continue;
                    }

                    // Skip curl write operations (POST/PUT/DELETE - not cacheable)
                    if ($func === 'curl_exec') {
                        if (strpos($context, 'CURLOPT_POST') !== false ||
                            strpos($context, 'CURLOPT_POSTFIELDS') !== false ||
                            strpos($context, 'CURLOPT_CUSTOMREQUEST') !== false ||
                            strpos($context, '"PUT"') !== false ||
                            strpos($context, '"DELETE"') !== false ||
                            strpos($context, "'PUT'") !== false ||
                            strpos($context, "'DELETE'") !== false) {
                            continue;
                        }
                    }

                    // Skip webhook/callback/REST handlers (real-time, not cacheable)
                    if (strpos($context, 'webhook') !== false ||
                        strpos($context, 'callback') !== false ||
                        strpos($context, 'WP_REST_Request') !== false ||
                        strpos($context, 'rest_api') !== false ||
                        strpos($filePath, 'rest_endpoint') !== false ||
                        strpos($filePath, 'rest-endpoint') !== false ||
                        strpos($filePath, 'api_manager') !== false ||
                        strpos($filePath, 'api-manager') !== false ||
                        strpos($filePath, 'data_manager') !== false ||
                        strpos($filePath, 'data-manager') !== false) {
                        continue;
                    }

                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'uncached-remote-request',
                        sprintf('%s() without visible transient caching', $func),
                        'Cache remote requests using set_transient() or wp_cache_set()'
                    );
                }
            }
        }

        return $diagnostics;
    }

    /**
     * Check for N+1 query patterns
     */
    private function checkNPlusOneQueries(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Skip admin/backend/cron code - N+1 queries less critical there
        $fileName = basename($filePath);
        if (strpos($filePath, '/admin/') !== false ||
            strpos($filePath, '/emails/') !== false ||
            strpos($filePath, '/cron') !== false ||
            strpos($fileName, '_page.php') !== false ||
            strpos($fileName, '_list.php') !== false ||
            strpos($fileName, 'settings') !== false ||
            strpos($fileName, 'data_manager') !== false ||
            strpos($fileName, 'page_manager') !== false ||
            strpos($fileName, 'ajax_manager') !== false ||
            strpos($fileName, 'cron') !== false ||
            strpos($fileName, 'admin') !== false) {
            return $diagnostics;
        }

        // Check for database queries inside foreach/while loops
        $loopPatterns = [
            '/foreach\s*\([^)]+\)\s*\{[^}]*\$wpdb\s*->/',
            '/while\s*\([^)]+\)\s*\{[^}]*\$wpdb\s*->/',
            '/foreach\s*\([^)]+\)\s*\{[^}]*get_post_meta\s*\(/',
            '/foreach\s*\([^)]+\)\s*\{[^}]*get_user_meta\s*\(/',
            '/foreach\s*\([^)]+\)\s*\{[^}]*get_term_meta\s*\(/',
        ];

        foreach ($loopPatterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'n-plus-one-query',
                        'Database query inside loop (N+1 query pattern)',
                        'Fetch all data before the loop or use batch queries. Consider update_meta_cache().'
                    );
                }
            }
        }

        return $diagnostics;
    }

    /**
     * Check for expensive operations in loops
     */
    private function checkLoopOperations(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Check for WP_Query inside loops
        if (preg_match_all('/(foreach|while|for)\s*\([^)]+\)\s*\{[^}]*new\s+WP_Query/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'error',
                    'query-in-loop',
                    'WP_Query instantiated inside loop',
                    'Move query outside loop or use a single query with post__in'
                );
            }
        }

        // Check for get_posts inside loops
        if (preg_match_all('/(foreach|while|for)\s*\([^)]+\)\s*\{[^}]*get_posts\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'query-in-loop',
                    'get_posts() called inside loop',
                    'Move query outside loop or consolidate into a single query'
                );
            }
        }

        return $diagnostics;
    }

    /**
     * Check for unoptimized meta queries
     */
    private function checkMetaQueries(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Check for meta_query without proper indexing hints
        if (preg_match_all('/[\'"]meta_query[\'"]\s*=>\s*\[/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);

                // Get the meta_query context
                $context = substr($content, $match[1], 500);

                // Check if using LIKE comparison (slow)
                if (strpos($context, 'LIKE') !== false) {
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'meta-query-like',
                        'meta_query using LIKE comparison is slow',
                        'Consider using exact matches or indexing the meta key'
                    );
                }

                // Check for multiple meta queries without relation
                if (preg_match_all('/\[[\s\S]*?key[\s\S]*?\]/', $context, $metaMatches) && count($metaMatches[0]) > 2) {
                    // Just informational
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'complex-meta-query',
                        'Complex meta_query with multiple conditions',
                        'Consider caching results or using a custom table for complex queries'
                    );
                }
            }
        }

        // Check for orderby meta_value without specifying type
        if (preg_match_all('/[\'"]orderby[\'"]\s*=>\s*[\'"]meta_value[\'"]/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $context = substr($content, $match[1], 200);

                if (strpos($context, 'meta_value_num') === false) {
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'meta-orderby-type',
                        'orderby meta_value without specifying type',
                        'Use meta_value_num for numeric values for proper sorting'
                    );
                }
            }
        }

        return $diagnostics;
    }
}
