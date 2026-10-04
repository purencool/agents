<?php

namespace App\PncAgentics\Agents;

use App\PncServices\Contracts\AIServiceInterface;
use App\PncServices\Contracts\RestServiceInterface;
use Illuminate\Support\Facades\Log;

abstract class BaseAgent
{
    protected AIServiceInterface $ai;
    protected RestServiceInterface $rest;

    public function __construct(
        AIServiceInterface $ai,
        RestServiceInterface $rest
    ) {
        $this->ai   = $ai;
        $this->rest = $rest;
    }

    abstract public function name(): string;
    abstract public function run(array $input): array;

    protected function think(string $prompt): string
    {
        return $this->ai->complete($prompt);
    }

    protected function fetch(string $endpoint, array $params = []): array
    {
        return $this->rest->get($endpoint, $params);
    }
}
