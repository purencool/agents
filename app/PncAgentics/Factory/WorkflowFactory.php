<?php

namespace App\PncAgentics\Factory;

use App\PncAgentics\Contracts\WorkflowFactoryInterface;
use InvalidArgumentException;

class WorkflowFactory implements WorkflowFactoryInterface
{
    /**
     * Resolves the target workflow strategy along with its
     * enterprise base namespace key.
     */
    public function make(string $type, bool $isUpdate = false): array
    {
        // Establish the absolute structural root prefix for your enterprise keys
        $baseEnterprisePrefix =
            "sales_marketing.marketing.external_documentation";

        $resolvedBaseKey = "{$baseEnterprisePrefix}.{$type}";

        // Resolve the update workflow strategy immediately if requested
        if ($isUpdate) {
            return [
                "workflow" => app(
                    \App\PncAgentics\Workflows\UpdateRefiningWorkflow::class,
                ),
                "base_key" => $resolvedBaseKey,
            ];
        }

        $strategy = match ($type) {
            "developer_docs",
            "product_guides",
            "social_media",
            "sales_copy",
            "blog_posts"
                => app(
                \App\PncAgentics\Workflows\UniversalDocumentationWorkflow::class,
            ),

            default => throw new InvalidArgumentException(
                "Unsupported enterprise workflow type: [{$type}]",
            ),
        };

        return [
            "workflow" => $strategy,
            "base_key" => $resolvedBaseKey,
        ];
    }
}
