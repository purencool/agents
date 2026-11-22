<?php

declare(strict_types=1);

namespace App\PncAgentics\Workflows;

use App\PncServices\Contracts\ContentPersistenceInterface;

/**
 * Persists workflow output to disk via the content persistence service.
 *
 * Resolves path/filename templates from context and delegates to
 * ContentPersistenceInterface::saveMarkdown().
 *
 * @example
 *   $persister = new WorkflowPersister($persistenceService, $resolver);
 *   $persister->persist(["path" => "{{dir}}", "filename" => "{{slug}}.md"], $context);
 */
class WorkflowPersister
{
    public function __construct(
        protected ContentPersistenceInterface $persistence,
        protected TemplateResolver $resolver,
    ) {}

    /**
     * Persist the workflow output to disk.
     *
     * @param array $persistConfig Expected keys: "path", "filename",
     *                             optional "output_key" (context key to save).
     * @param array $context       Current context (for template resolution).
     */
    public function persist(array $persistConfig, array $context): void
    {
        $path = $this->resolver->resolveTemplate(
            $persistConfig["path"],
            $context,
        );
        $filename = $this->resolver->resolveTemplate(
            $persistConfig["filename"],
            $context,
        );

        $contentKey = $persistConfig["output_key"] ?? "final_output";
        $content = $context[$contentKey] ?? "";

        if (is_array($content)) {
            $content = json_encode($content);
        }

        $this->persistence->setPath($path);
        $this->persistence->saveMarkdown((string) $content, $filename);
    }
}
