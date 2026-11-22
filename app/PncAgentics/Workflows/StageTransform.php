<?php

declare(strict_types=1);

namespace App\PncAgentics\Workflows;

/**
 * Applies named transforms to stage output.
 *
 * Transforms are pure functions: (string) → mixed.
 * Unknown transform names return the input unchanged.
 *
 * @example
 *   $transform = new StageTransform();
 *   $transform->apply("strip_code_fence", "```json\n{}\n```");
 *   // → "{}"
 */
class StageTransform
{
    /**
     * Apply a named transform to the given value.
     *
     * @param string $transform The transform name (e.g. "strip_code_fence", "json_decode").
     * @param mixed  $value     The input value.
     *
     * @return mixed The transformed value, or the original if transform is unknown.
     */
    public function apply(string $transform, mixed $value): mixed
    {
        return match ($transform) {
            "strip_code_fence" => $this->stripCodeFence((string) $value),
            "json_decode" => $this->jsonDecode((string) $value),
            default => $value,
        };
    }

    /**
     * Remove markdown code fence markers (```lang ... ```) from the value.
     *
     * Handles multiple fence blocks, bare ``` fences, and trims
     * surrounding whitespace from the result.
     *
     * @param string $value
     *
     * @return string
     */
    private function stripCodeFence(string $value): string
    {
        if ($value === "") {
            return "";
        }

        $result = preg_replace('/```(?:\w+)?\n?(.*?)\n?```/s', '$1', $value);

        return trim($result ?? $value);
    }

    /**
     * Decode a JSON string into a PHP value.
     *
     * Trims surrounding whitespace before decoding. Returns null
     * on invalid JSON or empty input.
     *
     * @param string $value
     *
     * @return mixed
     */
    private function jsonDecode(string $value): mixed
    {
        $value = trim($value);

        if ($value === "") {
            return null;
        }

        return json_decode($value, true);
    }
}
