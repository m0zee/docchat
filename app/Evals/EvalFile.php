<?php

namespace App\Evals;

use InvalidArgumentException;

/**
 * Loads and validates an eval file: a JSON list of cases with question,
 * expected_keywords[], expected_document, expected_page and optional should_refuse.
 */
class EvalFile
{
    /**
     * @return list<EvalCase>
     */
    public static function load(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Eval file not found: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Eval file is not valid JSON: '.json_last_error_msg());
        }

        if (is_array($data) && isset($data['cases'])) {
            $data = $data['cases'];
        }

        if (! is_array($data) || ! array_is_list($data) || $data === []) {
            throw new InvalidArgumentException('Eval file must be a non-empty JSON list of cases (or an object with a "cases" list).');
        }

        $cases = [];
        $errors = [];

        foreach ($data as $index => $case) {
            $problems = self::problems($case);

            if ($problems !== []) {
                $errors[] = 'Case '.($index + 1).': '.implode('; ', $problems);

                continue;
            }

            /** @var array<string, mixed> $case */
            $cases[] = new EvalCase(
                question: trim((string) $case['question']),
                expectedKeywords: array_values(array_map('strval', $case['expected_keywords'] ?? [])),
                expectedDocument: isset($case['expected_document']) ? (string) $case['expected_document'] : null,
                expectedPage: isset($case['expected_page']) ? (int) $case['expected_page'] : null,
                shouldRefuse: (bool) ($case['should_refuse'] ?? false),
            );
        }

        if ($errors !== []) {
            throw new InvalidArgumentException("Invalid eval file:\n".implode("\n", $errors));
        }

        return $cases;
    }

    /**
     * @return list<string>
     */
    private static function problems(mixed $case): array
    {
        if (! is_array($case)) {
            return ['must be an object'];
        }

        $problems = [];

        if (! is_string($case['question'] ?? null) || trim($case['question']) === '') {
            $problems[] = 'question must be a non-empty string';
        }

        if (isset($case['should_refuse']) && ! is_bool($case['should_refuse'])) {
            $problems[] = 'should_refuse must be true or false';
        }

        $refuse = ($case['should_refuse'] ?? false) === true;
        $keywords = $case['expected_keywords'] ?? [];

        if (! is_array($keywords) || ! array_is_list($keywords) || array_filter($keywords, fn ($k) => ! is_string($k) || $k === '') !== []) {
            $problems[] = 'expected_keywords must be a list of non-empty strings';
        }

        if (! $refuse) {
            if (! is_string($case['expected_document'] ?? null) || $case['expected_document'] === '') {
                $problems[] = 'expected_document is required unless should_refuse is true';
            }

            if (! is_int($case['expected_page'] ?? null) || $case['expected_page'] < 1) {
                $problems[] = 'expected_page must be a whole number from 1';
            }

            if ($keywords === []) {
                $problems[] = 'expected_keywords needs at least one keyword unless should_refuse is true';
            }
        }

        return $problems;
    }
}
