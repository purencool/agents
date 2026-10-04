<?php

namespace App\PncServices\REST;

use App\PncServices\Contracts\RestServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RestService implements RestServiceInterface
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('pncservices.rest.base_url');
        $this->apiKey  = config('pncservices.rest.api_key');
    }

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, $data);
    }

    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, $data);
    }

    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    protected function request(string $method, string $endpoint, array $data = []): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->apiKey)
                ->{$method}($endpoint, $data)
                ->throw()
                ->json();
            return $response ?? [];
        } catch (\Exception $e) {
            Log::error("RestService: {$method} {$endpoint} failed", ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }
}
