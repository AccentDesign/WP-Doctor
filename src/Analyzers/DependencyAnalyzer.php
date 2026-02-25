<?php

namespace AccentDesign\WPDoctor\Analyzers;

/**
 * Detects dependency issues and conflicts in WordPress plugins/themes.
 */
class DependencyAnalyzer extends BaseAnalyzer
{
    public function getCategory(): string
    {
        return 'dependencies';
    }

    public function analyzeFile(string $filePath): array
    {
        $diagnostics = [];
        $content = $this->readFile($filePath);

        if ($content === null) {
            return $diagnostics;
        }

        // Check for hardcoded plugin dependencies
        $diagnostics = array_merge($diagnostics, $this->checkPluginDependencies($filePath, $content));

        // Check for class_exists checks without fallbacks
        $diagnostics = array_merge($diagnostics, $this->checkClassExistsPatterns($filePath, $content));

        // Check for function_exists checks
        $diagnostics = array_merge($diagnostics, $this->checkFunctionExistsPatterns($filePath, $content));

        // Check for PHP version requirements
        $diagnostics = array_merge($diagnostics, $this->checkPhpVersionRequirements($filePath, $content));

        // Check for WordPress version requirements
        $diagnostics = array_merge($diagnostics, $this->checkWpVersionRequirements($filePath, $content));

        return $diagnostics;
    }

    /**
     * Check for hardcoded plugin dependencies
     */
    private function checkPluginDependencies(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Check for is_plugin_active without including plugin.php
        if (preg_match_all('/\bis_plugin_active\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);

                // Check if plugin.php is included
                if (strpos($content, 'include_once') === false || strpos($content, 'plugin.php') === false) {
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'is-plugin-active-include',
                        'is_plugin_active() used without including plugin.php',
                        'Add: include_once ABSPATH . \'wp-admin/includes/plugin.php\';'
                    );
                }
            }
        }

        // Check for direct plugin path references
        if (preg_match_all('/WP_PLUGIN_DIR\s*\.\s*[\'"]\/[^"\']+\.php[\'"]/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'hardcoded-plugin-path',
                    'Hardcoded plugin file path',
                    'Use class_exists() or function_exists() for soft dependencies'
                );
            }
        }

        return $diagnostics;
    }

    /**
     * Check for class_exists patterns
     */
    private function checkClassExistsPatterns(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Common WordPress classes that may not exist
        $optionalClasses = [
            'WooCommerce' => 'WooCommerce plugin',
            'ACF' => 'Advanced Custom Fields',
            'WPCF7' => 'Contact Form 7',
            'WP_REST_Controller' => 'WordPress REST API (WP 4.7+)',
            'WP_Customize_Control' => 'WordPress Customizer',
            'Elementor\\Plugin' => 'Elementor',
            'FLBuilder' => 'Beaver Builder',
            'ET_Builder_Module' => 'Divi Builder',
        ];

        foreach ($optionalClasses as $class => $description) {
            // Check if class is used without class_exists check
            $classPattern = '/\bnew\s+' . preg_quote($class, '/') . '\s*\(/';
            $staticPattern = '/' . preg_quote($class, '/') . '\s*::/';

            foreach ([$classPattern, $staticPattern] as $pattern) {
                if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $line = $this->findLineNumber($content, $match[1]);

                        // Check for class_exists nearby
                        $context = substr($content, max(0, $match[1] - 200), 400);
                        if (strpos($context, 'class_exists') === false) {
                            $diagnostics[] = $this->createDiagnostic(
                                $filePath,
                                $line,
                                'warning',
                                'missing-class-check',
                                sprintf('%s used without class_exists() check', $class),
                                sprintf('Class requires %s. Wrap in class_exists() check.', $description)
                            );
                        }
                    }
                }
            }
        }

        return $diagnostics;
    }

    /**
     * Check for function_exists patterns
     */
    private function checkFunctionExistsPatterns(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Common optional functions
        $optionalFunctions = [
            'wc_get_product' => 'WooCommerce',
            'get_field' => 'ACF',
            'get_sub_field' => 'ACF',
            'have_rows' => 'ACF',
            'pll__' => 'Polylang',
            'icl_t' => 'WPML',
            'FLBuilder::render_module' => 'Beaver Builder',
        ];

        foreach ($optionalFunctions as $func => $plugin) {
            $pattern = '/\b' . preg_quote($func, '/') . '\s*\(/';

            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);

                    // Check for function_exists nearby
                    $context = substr($content, max(0, $match[1] - 200), 400);
                    if (strpos($context, 'function_exists') === false) {
                        $diagnostics[] = $this->createDiagnostic(
                            $filePath,
                            $line,
                            'warning',
                            'missing-function-check',
                            sprintf('%s() used without function_exists() check', $func),
                            sprintf('Function requires %s. Wrap in function_exists() check.', $plugin)
                        );
                    }
                }
            }
        }

        return $diagnostics;
    }

    /**
     * Check for PHP version requirements
     */
    private function checkPhpVersionRequirements(string $filePath, string $content): array
    {
        $diagnostics = [];

        // PHP 8.0+ features
        $php8Features = [
            '/\?\->/' => ['feature' => 'Nullsafe operator (?->)', 'version' => '8.0'],
            '/\bmatch\s*\(/' => ['feature' => 'match expression', 'version' => '8.0'],
            '/#\[[\w\\\\]+\]/' => ['feature' => 'Attributes', 'version' => '8.0'],
            '/\|\s*null\b/' => ['feature' => 'Union types with null', 'version' => '8.0'],
            '/:\s*mixed\b/' => ['feature' => 'mixed type', 'version' => '8.0'],
            '/\benum\s+\w+/' => ['feature' => 'Enums', 'version' => '8.1'],
            '/readonly\s+(public|private|protected)/' => ['feature' => 'readonly properties', 'version' => '8.1'],
        ];

        foreach ($php8Features as $pattern => $info) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'php-version-requirement',
                        sprintf('%s requires PHP %s+', $info['feature'], $info['version']),
                        'Ensure your plugin\'s minimum PHP version is set correctly'
                    );
                }
            }
        }

        return $diagnostics;
    }

    /**
     * Check for WordPress version requirements
     */
    private function checkWpVersionRequirements(string $filePath, string $content): array
    {
        $diagnostics = [];

        // WordPress 5.0+ features
        $wpFeatures = [
            'register_block_type' => ['feature' => 'Block registration', 'version' => '5.0'],
            'wp_set_script_translations' => ['feature' => 'Script translations', 'version' => '5.0'],
            'rest_preload_api_request' => ['feature' => 'REST preloading', 'version' => '5.0'],
            'wp_get_environment_type' => ['feature' => 'Environment type', 'version' => '5.5'],
            'wp_robots' => ['feature' => 'Robots API', 'version' => '5.7'],
            'register_block_pattern' => ['feature' => 'Block patterns', 'version' => '5.5'],
            'wp_is_block_theme' => ['feature' => 'Block theme check', 'version' => '5.9'],
        ];

        foreach ($wpFeatures as $func => $info) {
            $pattern = '/\b' . preg_quote($func, '/') . '\s*\(/';

            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);

                    // Check for version check or function_exists
                    $context = substr($content, max(0, $match[1] - 300), 600);
                    $hasCheck = strpos($context, 'function_exists') !== false ||
                                strpos($context, 'version_compare') !== false;

                    if (!$hasCheck) {
                        $diagnostics[] = $this->createDiagnostic(
                            $filePath,
                            $line,
                            'warning',
                            'wp-version-requirement',
                            sprintf('%s() requires WordPress %s+', $func, $info['version']),
                            'Add function_exists() check for backwards compatibility'
                        );
                    }
                }
            }
        }

        return $diagnostics;
    }
}
