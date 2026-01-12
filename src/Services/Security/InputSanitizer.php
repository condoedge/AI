<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Illuminate\Support\Facades\Log;

/**
 * InputSanitizer
 *
 * Detects and mitigates prompt injection attempts in user input.
 * Provides both analysis (detection) and sanitization (removal) capabilities.
 *
 * @package Condoedge\Ai\Services\Security
 */
class InputSanitizer
{
    /**
     * Patterns that indicate potential prompt injection
     */
    protected array $injectionPatterns = [
        // Direct instruction overrides
        '/ignore\s+(all\s+)?(previous|prior|above)\s+(instructions?|rules?|guidelines?)/i',
        '/forget\s+(your|all|the)\s+(rules?|instructions?)/i',
        '/disregard\s+(all|the|previous)/i',

        // System-level impersonation
        '/^SYSTEM\s*:/mi',
        '/^\[\[.*\]\]\s*/mi',
        '/^<system>/mi',

        // Role manipulation
        '/you\s+are\s+now\s+(a|an|in)/i',
        '/pretend\s+(to\s+be|you\s+are)/i',
        '/act\s+as\s+(if|though|a)/i',

        // Code block injection (trying to inject instructions)
        '/```[\s\S]*?(instruction|system|rule|override)[\s\S]*?```/i',

        // Access control bypasses
        '/(bypass|ignore|disable|override)\s+(access|security|permission|restriction)/i',
        '/show\s+me\s+(restricted|hidden|private|sensitive)/i',
    ];

    /**
     * Patterns to remove during sanitization
     */
    protected array $sanitizePatterns = [
        '/^SYSTEM\s*:.*$/mi' => '',
        '/SYSTEM\s*:[^.]*\.?/i' => '',
        '/^\[\[.*\]\].*$/mi' => '',
        '/```[\s\S]*?(instruction|system|override)[\s\S]*?```/i' => '[code block removed]',
    ];

    /**
     * Analyze input for injection risk
     *
     * @param string $input User input to analyze
     * @return array Analysis result with has_injection_risk, patterns_matched, risk_level
     */
    public function analyze(string $input): array
    {
        $matchedPatterns = [];

        foreach ($this->injectionPatterns as $pattern) {
            if (preg_match($pattern, $input, $matches)) {
                $matchedPatterns[] = [
                    'pattern' => $pattern,
                    'matched' => $matches[0] ?? '',
                ];
            }
        }

        $hasRisk = !empty($matchedPatterns);
        $riskLevel = $this->calculateRiskLevel($matchedPatterns);

        if ($hasRisk) {
            Log::warning('Potential prompt injection detected', [
                'input_preview' => substr($input, 0, 100),
                'patterns_matched' => count($matchedPatterns),
                'risk_level' => $riskLevel,
            ]);
        }

        return [
            'has_injection_risk' => $hasRisk,
            'patterns_matched' => $matchedPatterns,
            'risk_level' => $riskLevel,
        ];
    }

    /**
     * Sanitize input by removing dangerous patterns
     *
     * @param string $input Input to sanitize
     * @return string Sanitized input
     */
    public function sanitize(string $input): string
    {
        foreach ($this->sanitizePatterns as $pattern => $replacement) {
            $input = preg_replace($pattern, $replacement, $input);
        }

        return trim($input);
    }

    /**
     * Analyze and optionally sanitize, returning both
     *
     * @param string $input Input to process
     * @param bool $sanitize Whether to sanitize
     * @return array Result with analysis and optionally sanitized input
     */
    public function process(string $input, bool $sanitize = true): array
    {
        $analysis = $this->analyze($input);

        return [
            'original' => $input,
            'sanitized' => $sanitize ? $this->sanitize($input) : $input,
            'analysis' => $analysis,
        ];
    }

    /**
     * Calculate risk level based on matched patterns
     *
     * @param array $matchedPatterns Patterns that matched
     * @return string Risk level: low, medium, high
     */
    protected function calculateRiskLevel(array $matchedPatterns): string
    {
        $count = count($matchedPatterns);

        if ($count === 0) {
            return 'none';
        }

        if ($count === 1) {
            return 'low';
        }

        if ($count <= 3) {
            return 'medium';
        }

        return 'high';
    }

    /**
     * Add custom injection pattern
     *
     * @param string $pattern Regex pattern
     * @return self
     */
    public function addPattern(string $pattern): self
    {
        $this->injectionPatterns[] = $pattern;
        return $this;
    }
}
