<?php

namespace App\PncTemplates\Contracts;

interface TemplateTransformerInterface
{
    /**
     * Transforms unstructured markdown into type-safe service fields using an external configuration blueprint.
     */
    public function transform(string $templates, string $rawContent): array;
}
