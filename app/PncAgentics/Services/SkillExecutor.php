<?php

namespace App\PncAgentics\Services;

use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncServices\Contracts\AIServiceInterface;
use InvalidArgumentException;

class SkillExecutor implements SkillExecutorInterface
{
    public function __construct(protected AIServiceInterface $aiService) {}

    public function execute(string $skillKey, array $parameters = []): string
    {
        // Fetch the raw skills.md content registered by your Service Provider
        $skillsRegistry = config("pnc.skills", []);

        if (!isset($skillsRegistry[$skillKey])) {
            throw new InvalidArgumentException(
                "Neuro-Laravel Skill [{$skillKey}] could not be found.",
            );
        }

        $systemInstructions = $skillsRegistry[$skillKey];

        // Build user context string from provided runtime variables
        $userContext = "Context and Variables for execution:\n";
        foreach ($parameters as $key => $value) {
            $userContext .=
                "- {$key}: " .
                (is_array($value) ? json_encode($value) : $value) .
                "\n";
        }

        // Construct a unified prompt combining the agent instructions and task context
        $compiledPrompt = "System Instructions:\n{$systemInstructions}\n\n{$userContext}\nPlease complete the task matching the style and formatting requested above.";

        // Fall back to your concrete AIService complete() method
        return $this->aiService->complete($compiledPrompt, [
            "temperature" => 0.7, // Custom parameters if needed
        ]);
    }
}
