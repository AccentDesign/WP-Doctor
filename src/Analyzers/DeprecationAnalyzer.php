<?php

namespace AccentDesign\WPDoctor\Analyzers;

/**
 * Detects deprecated WordPress functions and patterns.
 */
class DeprecationAnalyzer extends BaseAnalyzer
{
    /**
     * WordPress deprecated functions with their replacements
     */
    private const DEPRECATED_FUNCTIONS = [
        // WordPress 6.x deprecations
        'wp_localize_jquery_ui_datepicker' => ['since' => '6.4', 'alternative' => 'wp_enqueue_script("jquery-ui-datepicker")'],
        'get_posts_by_author_sql' => ['since' => '6.3', 'alternative' => 'WP_User_Query'],
        'get_page_by_title' => ['since' => '6.2', 'alternative' => 'WP_Query'],
        'utf8_uri_encode' => ['since' => '6.1', 'alternative' => 'rawurlencode()'],
        'wp_no_robots' => ['since' => '5.7', 'alternative' => 'wp_robots_no_robots()'],
        'wp_sensitive_page_meta' => ['since' => '5.7', 'alternative' => 'wp_robots_sensitive_page()'],

        // WordPress 5.x deprecations
        'the_block_template_skip_link' => ['since' => '5.9', 'alternative' => 'wp_enqueue_block_template_skip_link()'],
        'block_core_navigation_get_menu_items_at_location' => ['since' => '5.9', 'alternative' => 'wp_nav_menu()'],
        'wp_get_attachment_id3_keys' => ['since' => '5.9', 'alternative' => 'wp_get_attachment_metadata()'],
        '_register_widget_form_callback' => ['since' => '5.8', 'alternative' => 'WP_Widget'],
        '_register_widget_update_callback' => ['since' => '5.8', 'alternative' => 'WP_Widget'],
        'is_rtl' => null, // Not deprecated, but often misused
        'wp_localize_script' => null, // Not deprecated, but wp_add_inline_script preferred for non-l10n

        // Classic deprecations
        'get_bloginfo' => null, // Not deprecated but 'url' argument deprecated
        'query_posts' => ['since' => '3.0', 'alternative' => 'WP_Query or get_posts()'],
        'create_function' => ['since' => 'PHP 7.2', 'alternative' => 'anonymous functions'],
        'mysql_*' => ['since' => 'PHP 7.0', 'alternative' => 'mysqli_* or PDO'],
        'ereg' => ['since' => 'PHP 7.0', 'alternative' => 'preg_match()'],
        'eregi' => ['since' => 'PHP 7.0', 'alternative' => 'preg_match() with i flag'],
        'split' => ['since' => 'PHP 7.0', 'alternative' => 'preg_split() or explode()'],
        'get_currentuserinfo' => ['since' => '4.5', 'alternative' => 'wp_get_current_user()'],
        'get_user_by_email' => ['since' => '3.3', 'alternative' => 'get_user_by("email", $email)'],
        'get_userdatabylogin' => ['since' => '3.3', 'alternative' => 'get_user_by("login", $login)'],
        'get_user_id_from_string' => ['since' => '3.6', 'alternative' => 'get_user_by()'],
        'wp_convert_bytes_to_hr' => ['since' => '3.6', 'alternative' => 'size_format()'],
        'user_pass_ok' => ['since' => '3.5', 'alternative' => 'wp_authenticate()'],
        'get_all_category_ids' => ['since' => '4.0', 'alternative' => 'get_terms()'],
        'like_escape' => ['since' => '4.0', 'alternative' => '$wpdb->esc_like()'],
        'url_is_accessable_via_ssl' => ['since' => '4.0', 'alternative' => 'Check is_ssl()'],
        'wp_htmledit_pre' => ['since' => '4.3', 'alternative' => 'format_for_editor()'],
        'wp_richedit_pre' => ['since' => '4.3', 'alternative' => 'format_for_editor()'],
        'get_author_name' => ['since' => '2.8', 'alternative' => 'get_the_author_meta("display_name")'],
        'the_author_description' => ['since' => '2.8', 'alternative' => 'the_author_meta("description")'],
        'the_author_login' => ['since' => '2.8', 'alternative' => 'the_author_meta("login")'],
        'the_author_firstname' => ['since' => '2.8', 'alternative' => 'the_author_meta("first_name")'],
        'the_author_lastname' => ['since' => '2.8', 'alternative' => 'the_author_meta("last_name")'],
        'the_author_nickname' => ['since' => '2.8', 'alternative' => 'the_author_meta("nickname")'],
        'the_author_ID' => ['since' => '2.8', 'alternative' => 'the_author_meta("ID")'],
        'the_author_email' => ['since' => '2.8', 'alternative' => 'the_author_meta("email")'],
        'the_author_url' => ['since' => '2.8', 'alternative' => 'the_author_meta("url")'],
        'the_author_aim' => ['since' => '2.8', 'alternative' => 'the_author_meta("aim")'],
        'the_author_yim' => ['since' => '2.8', 'alternative' => 'the_author_meta("yim")'],
        'the_author_icq' => ['since' => '2.8', 'alternative' => 'removed'],
        'update_usermeta' => ['since' => '3.0', 'alternative' => 'update_user_meta()'],
        'delete_usermeta' => ['since' => '3.0', 'alternative' => 'delete_user_meta()'],
        'get_usermeta' => ['since' => '3.0', 'alternative' => 'get_user_meta()'],
        'update_category_cache' => ['since' => '3.1', 'alternative' => 'No alternative needed'],
        'get_the_author_icq' => ['since' => '2.8', 'alternative' => 'removed'],
        'clean_url' => ['since' => '3.0', 'alternative' => 'esc_url()'],
        'js_escape' => ['since' => '2.8', 'alternative' => 'esc_js()'],
        'wp_specialchars' => ['since' => '2.8', 'alternative' => 'esc_html()'],
        'attribute_escape' => ['since' => '2.8', 'alternative' => 'esc_attr()'],
    ];

    /**
     * PHP 8.x deprecation patterns
     */
    private const PHP8_DEPRECATIONS = [
        'optional_before_required' => '/function\s+\w+\s*\([^)]*\$\w+\s*=\s*[^,)]+\s*,\s*\$\w+\s*[,)]/s',
        'curly_brace_syntax' => '/\$\{[^}]+\}/',
        'string_functions_null' => '/(str_replace|strpos|strlen|substr|strtolower|strtoupper|trim|ltrim|rtrim|stripslashes|htmlspecialchars|htmlentities)\s*\(\s*\$[^)]*\)/',
    ];

    public function getCategory(): string
    {
        return 'deprecations';
    }

    public function analyzeFile(string $filePath): array
    {
        $diagnostics = [];
        // Use PHP-only content to avoid false positives from JS split(), etc.
        $content = $this->readFilePHPOnly($filePath);

        if ($content === null) {
            return $diagnostics;
        }

        $lines = $this->getLines($filePath);

        // Check for deprecated WordPress functions
        foreach (self::DEPRECATED_FUNCTIONS as $func => $info) {
            if ($info === null) {
                continue; // Skip non-deprecated entries
            }

            // Match function calls: funcname(
            $pattern = '/\b' . preg_quote($func, '/') . '\s*\(/';

            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'deprecated-function',
                        sprintf('%s() is deprecated since %s', $func, $info['since']),
                        sprintf('Use %s instead', $info['alternative'])
                    );
                }
            }
        }

        // Check for optional parameters before required parameters
        if (preg_match_all('/function\s+(\w+)\s*\(([^)]*)\)/s', $content, $funcMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($funcMatches[0] as $i => $match) {
                $funcName = $funcMatches[1][$i][0];
                $params = $funcMatches[2][$i][0];

                if ($this->hasOptionalBeforeRequired($params)) {
                    $line = $this->findLineNumber($content, $match[1]);
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'php8-optional-before-required',
                        sprintf('Function %s() has optional parameters before required parameters', $funcName),
                        'In PHP 8.0+, this triggers a deprecation warning. Reorder parameters or add default values.'
                    );
                }
            }
        }

        // Check for ${} variable syntax (deprecated in PHP 8.2)
        if (preg_match_all('/\$\{([^}]+)\}/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'php82-curly-syntax',
                    '${expr} variable syntax is deprecated in PHP 8.2',
                    'Use {$expr} syntax instead'
                );
            }
        }

        // Check for add_submenu_page(null, ...) pattern
        if (preg_match_all('/add_submenu_page\s*\(\s*null\s*,/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'wp-null-string-deprecation',
                    'add_submenu_page(null) triggers PHP 8 deprecation warnings in WordPress core',
                    'Use add_submenu_page(\'\', ...) with empty string instead of null'
                );
            }
        }

        return $diagnostics;
    }

    /**
     * Check if function has optional parameters before required ones
     */
    private function hasOptionalBeforeRequired(string $params): bool
    {
        $params = trim($params);
        if (empty($params)) {
            return false;
        }

        $paramList = preg_split('/,(?![^(]*\))/', $params);
        $sawOptional = false;

        foreach ($paramList as $param) {
            $param = trim($param);
            if (empty($param)) {
                continue;
            }

            // Skip variadic parameters
            if (strpos($param, '...') !== false) {
                continue;
            }

            $hasDefault = strpos($param, '=') !== false;

            if ($hasDefault) {
                $sawOptional = true;
            } elseif ($sawOptional) {
                // Required parameter after optional
                return true;
            }
        }

        return false;
    }
}
