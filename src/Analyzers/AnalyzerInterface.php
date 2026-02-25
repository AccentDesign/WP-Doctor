<?php

namespace AccentDesign\WPDoctor\Analyzers;

interface AnalyzerInterface
{
    /**
     * Analyze multiple files
     *
     * @param array $files List of file paths
     * @return array Array of diagnostics
     */
    public function analyze(array $files): array;

    /**
     * Analyze a single file
     *
     * @param string $filePath Path to file
     * @return array Array of diagnostics
     */
    public function analyzeFile(string $filePath): array;

    /**
     * Get analyzer category name
     */
    public function getCategory(): string;
}
