<?php

namespace App\PncAgentics\Contracts;

interface SkillExecutorInterface
{
    /**
     * Executes a specific markdown-based skill by resolving shortcode placeholders,
     * structures parameters cleanly, and interfaces with the LLM layer.
     *
     * @param string $skillKey Dot-notation path to the skill (e.g., 'ai_agents.core_writing.conclusion_writer')
     * @param array $variables Key-value pairs to parse into template markers
     * @param array $options Configuration adjustments (temperature, model constraints)
     * @return string The finalized text output content
     */
    public function execute(
        string $skillKey,
        array $variables = [],
        array $options = [],
    ): string;
}
