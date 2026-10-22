<?php

namespace App\PncAgentics\Factory;

use App\PncAgentics\Contracts\WorkflowFactoryInterface;
use InvalidArgumentException;

class WorkflowFactory implements WorkflowFactoryInterface
{
    /**
     * Resolves the standardized Universal content generation workflow for all tracks.
     */
    public function make(string $type, bool $isUpdate = false): array
    {
        $resolvedBaseKey = match ($type) {
            "template",
            "developer_docs",
            "product_guides",
            "social_media",
            "sales_copy",
            "blog_posts"
                => "sales_marketing.marketing.external_documentation.{$type}",

            default => throw new InvalidArgumentException(
                "Unsupported enterprise workflow type: [{$type}]",
            ),
        };

        return [
            "workflow" => app(
                \App\PncAgentics\Workflows\UniversalDocumentationWorkflow::class,
            ),
            "base_key" => $resolvedBaseKey,
        ];
    }
}
