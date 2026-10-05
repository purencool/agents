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
        $this->apiKey  = config('pncservices.ai.api_key');
        $this->baseUrl = config('pncservices.ai.base_url'); 
        $this->model   = config('pncservices.ai.model');
    }


    public function complete(string $prompt, array $options = []): string
    {
        $messages = [];

        if (!empty($options['system'])) {
            $messages[] = ['role' => 'system', 'content' => $options['system']];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $response = Http::withToken($this->apiKey)
            ->post($this->baseUrl . '/chat/completions', [
                'model'    => $this->model,
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            Log::error('AIService: request failed', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);
            throw new \RuntimeException("AI request failed: " . $response->status());
        }

        return $response->json('choices.0.message.content', '');
    }

    public function embed(string $text): array
    {
        $response = Http::withToken($this->apiKey)
            ->post($this->baseUrl . '/embeddings', [
                'model' => $this->model,
                'input' => $text,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Embedding request failed: " . $response->status());
        }

        return $response->json('data.0.embedding', []);
    }

    public function chat(string $message, array $history = []): string
    {
        $messages = array_merge($history, [
            ['role' => 'user', 'content' => $message],
        ]);

        $response = Http::withToken($this->apiKey)
            ->post($this->baseUrl . '/chat/completions', [
                'model'    => $this->model,
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Chat request failed: " . $response->status());
        }

        return $response->json('choices.0.message.content', '');
    }
}