<?php

namespace App\PncServices\AI;

use App\PncServices\Contracts\AIServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService implements AIServiceInterface
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config("pncservices.ai.api_key");
        $this->baseUrl = config("pncservices.ai.base_url");
        $this->model = config("pncservices.ai.model");
    }

    public function complete(string $prompt, array $options = []): string
    {
        $messages = [];

        if (!empty($options["system"])) {
            $messages[] = ["role" => "system", "content" => $options["system"]];
        }

        $messages[] = ["role" => "user", "content" => $prompt];

        $body = [
            "model" => $this->model,
            "messages" => $messages,
        ];

        // Map custom runtime generation metrics if passed by workflows
        if (isset($options["temperature"])) {
            $body["temperature"] = (float) $options["temperature"];
        }

        if (isset($options["max_tokens"])) {
            $body["max_tokens"] = (int) $options["max_tokens"];
        }

        // --- 1. DYNAMIC COMPUTE TIMEOUT ENGINE ---
        $timeoutSeconds = (int) env("AI_REQUEST_TIMEOUT", 30);

        $response = Http::withToken($this->apiKey)
            ->timeout($timeoutSeconds)
            ->post($this->baseUrl . "/chat/completions", $body);

        if ($response->failed()) {
            Log::error("AIService: request failed", [
                "status" => $response->status(),
                "body" => $response->json(),
            ]);
            throw new \RuntimeException(
                "AI request failed: " . $response->status(),
            );
        }

        return $response->json("choices.0.message.content", "");
    }

    public function embed(string $text): array
    {
        // --- 2. DYNAMIC COMPUTE TIMEOUT ENGINE ---
        $timeoutSeconds = (int) env("AI_REQUEST_TIMEOUT", 30);

        $response = Http::withToken($this->apiKey)
            ->timeout($timeoutSeconds)
            ->post($this->baseUrl . "/embeddings", [
                "model" => $this->model,
                "input" => $text,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                "Embedding request failed: " . $response->status(),
            );
        }

        return $response->json("data.0.embedding", []);
    }

    public function chat(string $message, array $history = []): string
    {
        $messages = array_merge($history, [
            ["role" => "user", "content" => $message],
        ]);

        $timeoutSeconds = (int) env("AI_REQUEST_TIMEOUT", 30);

        $response = Http::withToken($this->apiKey)
            ->timeout($timeoutSeconds)
            ->withHeaders([
                "Connection" => "keep-alive",
            ])
            ->post($this->baseUrl . "/chat/completions", [
                "model" => $this->model,
                "messages" => $messages,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                "Chat request failed: " . $response->status(),
            );
        }

        return $response->json("choices.0.message.content", "");
    }
}
