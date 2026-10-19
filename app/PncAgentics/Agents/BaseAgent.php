<?php

namespace App\PncAgentics\Agents;

use App\PncServices\Contracts\AIServiceInterface;
use App\PncServices\Contracts\RestServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 *
 */
abstract class BaseAgent
{
    /**
     *
     */
    protected AIServiceInterface $ai;

    /**
     *
     */
    protected RestServiceInterface $rest;

    /**
     *
     */
    public function __construct(
        AIServiceInterface $ai,
        RestServiceInterface $rest,
    ) {
        $this->ai = $ai;
        $this->rest = $rest;
    }

    /**
     *
     */
    abstract public function name(): string;

    /**
     *
     */
    abstract public function run(array $input): array;

    /**
     *
     * @param string $prompt
     * @param string $system
     * @return string
     */
    protected function think(string $prompt, string $system = ""): string
    {
        return $this->ai->complete($prompt, ["system" => $system]);
    }

    /**
     *
     */
    //protected function generate(string $text): string
    // {
    //    return ''; //$this->ai->generate($text);
    // }

    /**
     *
     * @param string $prompt
     * @return string
     */
    //protected function translate(string $prompt): string
    // {
    //    return ''; //$this->ai->translate($prompt);
    // }

    /**
     *
     * @param string $text
     * @return string
     */
    ///protected function detect(string $text): string
    //{
    //    return ''; $this->ai->detect($text);
    // }

    /**
     * @todo Needs to be updated.
     * @param string $prompt
     * @param array string $params
     * @return array
     */
    //  protected function complete(string $prompt, array $params = []): array
    // {
    //      return $this->ai->complete($prompt, ["system" => $params]);
    //  }

    /**
     * @param string $endpoint
     * @param array $params
     * @return array
     */
    protected function fetch(string $endpoint, array $params = []): array
    {
        return $this->rest->get($endpoint, $params);
    }
}
