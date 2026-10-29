<?php

namespace App\PncServices\AI;

use App\PncServices\Contracts\AIServiceInterface;
use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;

class AIService implements AIServiceInterface
{
    protected OpenAILikeEmbeddings $embeddings;

    public function __construct(
        protected \App\PncServices\Contracts\LoggingServiceInterface $logger,
    ) {
        $this->embeddings = new OpenAILikeEmbeddings(
            baseUri: config("pncservices.ai.base_url"),
            key: config("pncservices.ai.api_key"),
            model: config("pncservices.ai.model"),
        );
    }

    public function complete(string $prompt, array $options = []): string
    {
        $agent = new PncAgent($options);
        $agent->setThreadId("one-shot");
        if (!empty($options["system"])) {
            $agent->setInstructions((string) $options["system"]);
        }

        try {
            $content = $agent
                ->chat(new UserMessage($prompt))
                ->getMessage()
                ->getContent();

            $response = $content ?? "";

            return $response;
        } catch (\Throwable $e) {
            Log::error("AIService::complete — request failed", [
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function embed(string $text): array
    {
        try {
            $vector = $this->embeddings->embedText($text);
            return $vector;
        } catch (\Throwable $e) {
            Log::error("AIService::embed — request failed", [
                "error" => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Fix: Signature updated to have exactly 2 arguments matching AIServiceInterface.
     */
    public function chat(string $message, array $history = []): string
    {
        $threadId = "default";

        try {
            $start = microtime(true);
            $agent = new PncAgent();
            $content = $agent
                ->setThreadId($threadId)
                ->chat(new UserMessage($message))
                ->getMessage()
                ->getContent();

            $response = $content ?? "";
            return $response;
        } catch (\Throwable $e) {
            Log::error("AIService::chat — request failed", [
                "thread_id" => $threadId,
                "error" => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
