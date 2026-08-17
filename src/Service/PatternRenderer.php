<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Service;

/**
 * Renders naming patterns with {const:name} substitution.
 *
 * Syntax: {const:name}
 * Example: "report_{const:date}-{const:counter}" renders as "report_2026-03-16-1"
 *
 * Uses the same token syntax as the DTMPL {const:name} tag, so patterns are
 * human-readable and can be embedded in DTMPL templates without escaping.
 */
final readonly class PatternRenderer
{
    /**
     * Render a pattern string by substituting {const:name} placeholders.
     *
     * @param string               $pattern e.g. "report_{const:date}-{const:counter}"
     * @param array<string, mixed> $context e.g. ['date' => '2026-03-16', 'counter' => 1]
     */
    public function render(string $pattern, array $context): string
    {
        return preg_replace_callback(
            '/\{const:([^}]+)\}/',
            static fn (array $m) => isset($context[$m[1]]) ? (string) $context[$m[1]] : $m[0],
            $pattern,
        ) ?? $pattern;
    }

    /**
     * Extract all token names from a pattern.
     *
     * @return string[]
     */
    public function extractTokens(string $pattern): array
    {
        preg_match_all('/\{const:([^}]+)\}/', $pattern, $matches);

        return array_unique($matches[1]);
    }

    /**
     * Build a standard VFS naming context.
     * Used by conflict resolution to generate unique filenames.
     *
     * @return array<string, string|int>
     */
    public function buildVfsContext(int $counter = 1): array
    {
        return [
            'counter' => $counter,
            'date' => date('Y-m-d'),
            'datetime' => date('Y-m-d_H-i-s'),
            'random' => 3
                    |> random_bytes(...)
                    |> bin2hex(...)
                    |> (fn ($x) => substr($x, 0, 6)),
        ];
    }
}
