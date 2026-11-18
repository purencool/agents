<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);


namespace App\PncAgentics\Factory;

use App\PncAgentics\Contracts\WorkflowFactoryInterface;
use App\PncAgentics\Workflows\UniversalDocumentationWorkflow;
use InvalidArgumentException;

/**
 * Factory for resolving enterprise content-generation workflows.
 *
 * Maps a workflow type string to its standardized base key and returns
 * a {@see UniversalDocumentationWorkflow} instance configured for that track.
 *
 * Supported types:
 *  - developer_docs
 *  - product_guides
 *  - social_media
 *  - sales_copy
 *  - blog_posts
 */
class WorkflowFactory implements WorkflowFactoryInterface
{
    /**
     * Resolve a workflow by type.
     *
     * @param string $type      The workflow type identifier (e.g. "developer_docs").
     * @param bool   $isUpdate  Whether this is an update run (reserved for future use).
     *
     * @return array{
     *     workflow: UniversalDocumentationWorkflow,
     *     base_key: string,
     * }
     *
     * @throws InvalidArgumentException When the type is not a recognized workflow type.
     */
    public function make(string $type, bool $isUpdate = false): array
    {
        $resolvedBaseKey = match ($type) {
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
            "workflow" => app(UniversalDocumentationWorkflow::class),
            "base_key" => $resolvedBaseKey,
        ];
    }
}
