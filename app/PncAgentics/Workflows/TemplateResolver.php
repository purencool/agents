<?php

declare(strict_types=1);

namespace App\PncAgentics\Workflows;

/**
 * Resolves {{key}} placeholders from a workflow context array.
 *
 * Supports:
 *  - Single-key references (returns raw value, preserving type).
 *  - Mixed templates (returns interpolated string).
 *  - Array/object values (JSON-encoded to prevent type errors).
 *
 * @example
 *   $resolver = new TemplateResolver();
 *   $resolver->resolveTemplate("Hello {{name}}!", ["name" => "World"]);
 *   // → "Hello World!"
 */
class TemplateResolver
{
    /**
     * Resolve a single template string from context.
     * Returns the raw value if the string is a single {{key}},
     * otherwise returns the interpolated string.
     */
    public function resolveVariable(string $key, array $context): mixed
    {
        if (preg_match('/^\{\{(\w+)\}\}$/', $key, $m)) {
            return $context[$m[1]] ?? null;
        }

        return $this->resolveTemplate($key, $context);
    }

    /**
     * Replace all {{key}} placeholders in a template with context values.
     * Missing keys become "". Arrays/objects are JSON-encoded.
     */
    public function resolveTemplate(string $template, array $context): string
    {
        $replaced = preg_replace_callback(
            "/\{\{(\w+)\}\}/",
            function (array $m) use ($context): string {
                $value = $context[$m[1]] ?? "";
                if (is_array($value) || is_object($value)) {
                    return json_encode($value);
                }
                return (string) $value;
            },
            $template,
        );

        return $replaced ?? "";
    }

    /**
     * Resolve all {{key}} placeholders in a variables array.
     * Non-string values pass through unchanged.
     */
    public function resolveVariables(array $variables, array $context): array
    {
        $resolved = [];
        foreach ($variables as $key => $value) {
            $resolved[$key] = is_string($value)
                ? $this->resolveTemplate($value, $context)
                : $value;
        }

        return $resolved;
    }
}
