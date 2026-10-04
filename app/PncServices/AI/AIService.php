<?php

namespace App\PncServices\AI;

use App\PncServices\Contracts\AIServiceInterface;
use Illuminate\Support\Facades\Log;

class AIService implements AIServiceInterface
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $model;

    public function __construct()
    {
        $this->apiKey  = config('pncservices.ai.api_key');
        $this->baseUrl = config('pncservices.ai.base_url', 'https://api.openai.com/v1');
        $this->model   = config('pncservices.ai.model', 'gpt-4o');
    }

    public function complete(string $prompt, array $options = []): string
    {
        // TODO: Implement Neuron AI or OpenAI call
        Log::info("AIService: complete() called", ['model' => $this->model]);
        return '';
    }

    public function embed(string $text): array
    {
        // TODO: Implement embedding
        return [];
    }

    public function chat(string $message, array $history = []): string
    {
        // TODO: Implement chat with history
        return '';
    }
}
