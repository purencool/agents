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
     * Apply a named transform to a value.
     *
     * Supported transforms:
     *  - "strip_code_fence": Removes ```json ... ``` wrappers.
     *  - "json_decode":      Decodes a JSON string into a PHP array.
     */
    public function apply(string $transform, string $value): mixed
    {
        return match ($transform) {
            "strip_code_fence" => trim(
                preg_replace("/```json\s*|```/", "", $value) ?? "",
            ),
            "json_decode" => json_decode(trim($value), true),
            default => $value,
        };
    }
}
