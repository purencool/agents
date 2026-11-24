<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Agent;

use App\PncAgentics\Agent\PncAgent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\OpenAILike;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see PncAgent}.
 *
 * Verifies that:
 *  - The constructor stores runtime parameters correctly.
 *  - provider() returns an OpenAILike instance with the correct
 *    base config and merges runtime overrides (temperature, max_tokens).
 *  - instructions() returns a SystemMessage with the configured prompt.
 *  - contextWindow() returns the configured token budget.
 *  - messageStore() returns an InMemoryMessageStore.
 *
 * Configuration keys tested (config/pncservices.php):
 *  - pnc.ai.base_url       (string) API endpoint
 *  - pnc.ai.api_key        (string) API key
 *  - pnc.ai.model          (string) Model identifier
 *  - pnc.ai.embed_model    (string) Embedding model identifier
 *  - pnc.ai.timeout        (int)    Request timeout in seconds
 *  - pnc.ai.system_prompt  (string) Default system instruction
 *  - pnc.ai.context_window (int)    Token budget for chat history
 *
 * @covers \App\PncAgentics\Agent\PncAgent
 */
class PncAgentTest extends TestCase
{
    /**
     * Configure the pnc.ai values used by PncAgent.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            "pnc.ai.base_url" => "http://localhost:8080/v1",
            "pnc.ai.api_key" => "test-key",
            "pnc.ai.model" => "test-model",
            "pnc.ai.embed_model" => "text-embedding-3-small",
            "pnc.ai.timeout" => 45,
            "pnc.ai.system_prompt" => "You are a PNC assistant.",
            "pnc.ai.context_window" => 50000,
        ]);
    }

    // ─── Constructor ───────────────────────────────────────────────────────

    /**
     * Verify that runtime parameters are stored on the instance.
     */
    #[Test]
    public function constructor_stores_runtime_parameters(): void
    {
        $params = ["temperature" => 0.7, "max_tokens" => 512];
        $agent = new PncAgent($params);

        $ref = new \ReflectionProperty(PncAgent::class, "runtimeParameters");
        $ref->setAccessible(true);

        $this->assertSame($params, $ref->getValue($agent));
    }

    /**
     * Verify that the constructor defaults to an empty array
     * when no parameters are provided.
     */
    #[Test]
    public function constructor_defaults_to_empty_parameters(): void
    {
        $agent = new PncAgent();

        $ref = new \ReflectionProperty(PncAgent::class, "runtimeParameters");
        $ref->setAccessible(true);

        $this->assertSame([], $ref->getValue($agent));
    }

    // ─── provider() ────────────────────────────────────────────────────────

    /**
     * Verify that provider() returns an OpenAILike instance
     * configured with the base config values and default timeout.
     */
    #[Test]
    public function provider_returns_openai_like_with_base_config(): void
    {
        $agent = new PncAgent();

        $provider = $this->invokeProvider($agent);

        $this->assertInstanceOf(OpenAILike::class, $provider);
        $this->assertSame(
            "http://localhost:8080/v1",
            $this->getProviderProp($provider, "baseUri"),
        );
        $this->assertSame("test-key", $this->getProviderProp($provider, "key"));
        $this->assertSame(
            "test-model",
            $this->getProviderProp($provider, "model"),
        );
    }

    /**
     * Verify that provider() includes the configured timeout
     * in the parameters array.
     */
    #[Test]
    public function provider_includes_configured_timeout(): void
    {
        $agent = new PncAgent();
        $provider = $this->invokeProvider($agent);

        $params = $this->getProviderProp($provider, "parameters");

        $this->assertSame(45, $params["timeout"]);
    }

    /**
     * Verify that provider() uses the default timeout (30) when
     * pnc.ai.timeout is not configured.
     */
    #[Test]
    public function provider_uses_default_timeout_when_not_configured(): void
    {
        // Remove the key entirely so config() falls back to the default.
        $config = config("pnc.ai");
        unset($config["timeout"]);
        config()->set("pnc.ai", $config);

        $agent = new PncAgent();
        $provider = $this->invokeProvider($agent);

        $params = $this->getProviderProp($provider, "parameters");

        $this->assertSame(30, $params["timeout"]);
    }

    /**
     * Verify that provider() merges temperature from
     * runtime parameters into the provider config.
     */
    #[Test]
    public function provider_includes_temperature_from_runtime_params(): void
    {
        $agent = new PncAgent(["temperature" => 0.7]);
        $provider = $this->invokeProvider($agent);

        $params = $this->getProviderProp($provider, "parameters");

        $this->assertSame(0.7, $params["temperature"]);
    }

    /**
     * Verify that provider() merges max_tokens from
     * runtime parameters into the provider config.
     */
    #[Test]
    public function provider_includes_max_tokens_from_runtime_params(): void
    {
        $agent = new PncAgent(["max_tokens" => 1024]);
        $provider = $this->invokeProvider($agent);

        $params = $this->getProviderProp($provider, "parameters");

        $this->assertSame(1024, $params["max_tokens"]);
    }

    /**
     * Verify that provider() omits temperature and max_tokens
     * from parameters when they are not in runtime params.
     */
    #[Test]
    public function provider_omits_optional_params_when_not_set(): void
    {
        $agent = new PncAgent();
        $provider = $this->invokeProvider($agent);

        $params = $this->getProviderProp($provider, "parameters");

        $this->assertArrayNotHasKey("temperature", $params);
        $this->assertArrayNotHasKey("max_tokens", $params);
    }

    /**
     * Verify that provider() casts temperature to float
     * and max_tokens to int.
     */
    #[Test]
    public function provider_casts_parameter_types_correctly(): void
    {
        $agent = new PncAgent(["temperature" => "0.5", "max_tokens" => "256"]);
        $provider = $this->invokeProvider($agent);

        $params = $this->getProviderProp($provider, "parameters");

        $this->assertSame(0.5, $params["temperature"]);
        $this->assertSame(256, $params["max_tokens"]);
    }

    // ─── instructions() ────────────────────────────────────────────────────

    /**
     * Verify that instructions() returns a SystemMessage
     * with the configured system prompt.
     */
    #[Test]
    public function instructions_returns_configured_prompt(): void
    {
        $agent = new PncAgent();
        $message = $agent->instructions();

        $this->assertInstanceOf(SystemMessage::class, $message);
        $this->assertSame("You are a PNC assistant.", $message->getContent());
    }

    /**
     * Verify that instructions() falls back to the default
     * prompt when no system_prompt is configured.
     */
    #[Test]
    public function instructions_falls_back_to_default_prompt(): void
    {
        // Remove the key entirely so config() falls back to the default.
        $config = config("pnc.ai");
        unset($config["system_prompt"]);
        config()->set("pnc.ai", $config);

        $agent = new PncAgent();
        $message = $agent->instructions();

        $this->assertSame(
            "You are a helpful assistant.",
            $message->getContent(),
        );
    }

    // ─── contextWindow() ───────────────────────────────────────────────────

    /**
     * Verify that contextWindow() returns the configured token budget.
     */
    #[Test]
    public function context_window_returns_configured_value(): void
    {
        $agent = new PncAgent();

        $method = new \ReflectionMethod(PncAgent::class, "contextWindow");
        $method->setAccessible(true);

        $this->assertSame(50000, $method->invoke($agent));
    }

    /**
     * Verify that contextWindow() returns the default (50000) when
     * pnc.ai.context_window is not configured.
     */
    #[Test]
    public function context_window_uses_default_when_not_configured(): void
    {
        // Remove the key entirely so config() falls back to the default.
        $config = config("pnc.ai");
        unset($config["context_window"]);
        config()->set("pnc.ai", $config);

        $agent = new PncAgent();

        $method = new \ReflectionMethod(PncAgent::class, "contextWindow");
        $method->setAccessible(true);

        $this->assertSame(50000, $method->invoke($agent));
    }

    // ─── messageStore() ────────────────────────────────────────────────────

    /**
     * Verify that messageStore() returns an InMemoryMessageStore.
     */
    #[Test]
    public function message_store_returns_in_memory_store(): void
    {
        $agent = new PncAgent();

        $method = new \ReflectionMethod(PncAgent::class, "messageStore");
        $method->setAccessible(true);

        $store = $method->invoke($agent);

        $this->assertInstanceOf(
            \NeuronAI\Chat\History\InMemoryMessageStore::class,
            $store,
        );
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * Invoke the protected provider() method via reflection.
     */
    private function invokeProvider(PncAgent $agent): OpenAILike
    {
        $method = new \ReflectionMethod(PncAgent::class, "provider");
        $method->setAccessible(true);

        return $method->invoke($agent);
    }

    /**
     * Read a property from the OpenAILike provider via reflection.
     */
    private function getProviderProp(OpenAILike $provider, string $prop): mixed
    {
        $ref = new \ReflectionProperty($provider, $prop);
        $ref->setAccessible(true);

        return $ref->getValue($provider);
    }
}
