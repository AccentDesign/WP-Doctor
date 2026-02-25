<?php

namespace AccentDesign\WPDoctor\Analyzers;

/**
 * Detects potentially dead or unreachable code.
 */
class DeadCodeAnalyzer extends BaseAnalyzer
{
    public function getCategory(): string
    {
        return 'dead-code';
    }

    public function analyzeFile(string $filePath): array
    {
        $diagnostics = [];
        $content = $this->readFile($filePath);

        if ($content === null) {
            return $diagnostics;
        }

        // Check for unreachable code
        $diagnostics = array_merge($diagnostics, $this->checkUnreachableCode($filePath, $content));

        // DISABLED: Too many false positives (variables used in templates, extract(), compact())
        // $diagnostics = array_merge($diagnostics, $this->checkUnusedVariables($filePath, $content));

        // Check for empty catch blocks
        $diagnostics = array_merge($diagnostics, $this->checkEmptyCatchBlocks($filePath, $content));

        // DISABLED: Too noisy - commented code is often intentional
        // $diagnostics = array_merge($diagnostics, $this->checkCommentedCode($filePath, $content));

        // Check for debug code left behind
        $diagnostics = array_merge($diagnostics, $this->checkDebugCode($filePath, $content));

        return $diagnostics;
    }

    /**
     * Check for unreachable code after return/exit/die
     */
    private function checkUnreachableCode(string $filePath, string $content): array
    {
        $diagnostics = [];
        $lines = $this->getLines($filePath);

        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);

            // Check for code after return - look for statement ending semicolon followed by code
            // Pattern: return followed by statement end (;) with code after, excluding strings containing ;
            if (preg_match('/^return\s+[^\'\"]*;\s*\S+/', $trimmed) &&
                !preg_match('/^return\s+[^\'\"]*;\s*(\/\/|\/\*|\}|$)/', $trimmed)) {
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $lineNum + 1,
                    'warning',
                    'unreachable-code',
                    'Code after return statement',
                    'This code will never be executed'
                );
            }

            // Check for code after die/exit
            if (preg_match('/^(die|exit)\s*\([^)]*\)\s*;\s*\S+/', $trimmed)) {
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $lineNum + 1,
                    'warning',
                    'unreachable-code',
                    'Code after die/exit statement',
                    'This code will never be executed'
                );
            }

            // Check for code after wp_die
            if (preg_match('/^wp_die\s*\([^)]*\)\s*;\s*\S+/', $trimmed)) {
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $lineNum + 1,
                    'warning',
                    'unreachable-code',
                    'Code after wp_die() statement',
                    'This code will never be executed'
                );
            }
        }

        // Check for if(false) or if(0) patterns
        if (preg_match_all('/if\s*\(\s*(false|0)\s*\)\s*\{/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'dead-conditional',
                    'Condition is always false',
                    'This code block will never execute. Remove or fix the condition.'
                );
            }
        }

        return $diagnostics;
    }

    /**
     * Check for unused variables (basic detection)
     */
    private function checkUnusedVariables(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Find variable assignments
        if (preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*[^=]/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                $varName = $match[0];
                $offset = $match[1];

                // Skip common loop/temp variables
                if (in_array($varName, ['i', 'j', 'k', 'key', 'value', 'item', 'row', '_', 'this'])) {
                    continue;
                }

                // Count occurrences after assignment
                $afterAssignment = substr($content, $offset + strlen($varName));
                $occurrences = substr_count($afterAssignment, '$' . $varName);

                // If variable only appears once (the assignment), it might be unused
                if ($occurrences === 0) {
                    $line = $this->findLineNumber($content, $offset);

                    // Check if it's a return value or passed to a function on the same line
                    $lineContent = substr($content, $offset, 200);
                    $firstNewline = strpos($lineContent, "\n");
                    if ($firstNewline !== false) {
                        $lineContent = substr($lineContent, 0, $firstNewline);
                    }

                    // Skip if likely used immediately
                    if (strpos($lineContent, 'return') !== false ||
                        strpos($lineContent, 'echo') !== false ||
                        strpos($lineContent, 'print') !== false) {
                        continue;
                    }

                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $line,
                        'warning',
                        'unused-variable',
                        sprintf('Variable $%s may be unused after assignment', $varName),
                        'Remove if unused, or verify it\'s used in included files'
                    );
                }
            }
        }

        return $diagnostics;
    }

    /**
     * Check for empty catch blocks
     */
    private function checkEmptyCatchBlocks(string $filePath, string $content): array
    {
        $diagnostics = [];

        // Pattern for empty catch blocks
        if (preg_match_all('/catch\s*\([^)]+\)\s*\{\s*\}/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'empty-catch',
                    'Empty catch block silently swallows exceptions',
                    'Log the exception or rethrow it. Silent failures are hard to debug.'
                );
            }
        }

        // Pattern for catch blocks with only comments
        if (preg_match_all('/catch\s*\([^)]+\)\s*\{\s*\/\/[^\n]*\s*\}/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'empty-catch',
                    'Catch block only contains comments',
                    'Add error logging or proper exception handling'
                );
            }
        }

        return $diagnostics;
    }

    /**
     * Check for commented-out code
     */
    private function checkCommentedCode(string $filePath, string $content): array
    {
        $diagnostics = [];
        $lines = $this->getLines($filePath);
        $commentedCodeCount = 0;
        $firstCommentedLine = 0;

        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);

            // Check for commented PHP code patterns
            if (preg_match('/^\/\/\s*(\$|if\s*\(|for\s*\(|foreach|while|return|echo|function|class|public|private|protected)/', $trimmed) ||
                preg_match('/^#\s*(\$|if\s*\(|for\s*\(|foreach|while|return|echo|function|class)/', $trimmed)) {
                $commentedCodeCount++;
                if ($firstCommentedLine === 0) {
                    $firstCommentedLine = $lineNum + 1;
                }
            } else {
                // If we had a streak of commented code, report it
                if ($commentedCodeCount >= 3) {
                    $diagnostics[] = $this->createDiagnostic(
                        $filePath,
                        $firstCommentedLine,
                        'warning',
                        'commented-code',
                        sprintf('%d lines of commented-out code', $commentedCodeCount),
                        'Remove commented code. Use version control to track old code.'
                    );
                }
                $commentedCodeCount = 0;
                $firstCommentedLine = 0;
            }
        }

        // Check final streak
        if ($commentedCodeCount >= 3) {
            $diagnostics[] = $this->createDiagnostic(
                $filePath,
                $firstCommentedLine,
                'warning',
                'commented-code',
                sprintf('%d lines of commented-out code', $commentedCodeCount),
                'Remove commented code. Use version control to track old code.'
            );
        }

        return $diagnostics;
    }

    /**
     * Check for debug code left behind
     */
    private function checkDebugCode(string $filePath, string $content): array
    {
        $diagnostics = [];

        $debugPatterns = [
            '/\bvar_dump\s*\(/' => 'var_dump()',
            '/\bprint_r\s*\(/' => 'print_r()',
            '/\bvar_export\s*\(/' => 'var_export()',
            '/\bdebug_backtrace\s*\(/' => 'debug_backtrace()',
            '/\bdebug_print_backtrace\s*\(/' => 'debug_print_backtrace()',
            '/\berror_log\s*\(/' => 'error_log()',
            '/\bconsole\.log\s*\(/' => 'console.log() (in PHP string)',
        ];

        $lines = $this->getLines($filePath);

        foreach ($debugPatterns as $pattern => $name) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = $this->findLineNumber($content, $match[1]);
                    $lineContent = trim($lines[$line - 1] ?? '');

                    // Skip if the line is commented out
                    if (strpos($lineContent, '//') === 0 || strpos($lineContent, '*') === 0 || strpos($lineContent, '/*') === 0) {
                        continue;
                    }

                    // Check if it's in a debug-specific context
                    $context = substr($content, max(0, $match[1] - 100), 200);
                    $isDebugContext = strpos($context, 'WP_DEBUG') !== false ||
                                     strpos($context, 'SCRIPT_DEBUG') !== false ||
                                     strpos($context, 'if (defined') !== false;

                    if (!$isDebugContext) {
                        $diagnostics[] = $this->createDiagnostic(
                            $filePath,
                            $line,
                            'warning',
                            'debug-code',
                            sprintf('Debug function %s found', $name),
                            'Remove debug code or wrap in WP_DEBUG check before production'
                        );
                    }
                }
            }
        }

        // Check for TODO/FIXME comments
        if (preg_match_all('/\/\/\s*(TODO|FIXME|XXX|HACK)[\s:]/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = $this->findLineNumber($content, $match[1]);
                $diagnostics[] = $this->createDiagnostic(
                    $filePath,
                    $line,
                    'warning',
                    'todo-comment',
                    'TODO/FIXME comment found',
                    'Address this item before release'
                );
            }
        }

        return $diagnostics;
    }
}
