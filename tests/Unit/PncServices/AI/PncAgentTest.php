<?php

namespace Tests\Unit\PncServices\AI;

use App\PncServices\AI\PncAgent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\OpenAILike;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for PncAgent.
 *
 * Verifies that:
 *  - The constructor stores runtime parameters correctly.
 *  - provider() returns an OpenAILike instance with the correct
 *    base config and merges runtime overrides (temperature, max_tokens).
 *  - instructions() returns a SystemMessage with the configured prompt.
 */
class PncAgentTest extends TestCase
{
    /**
     * Configure the pncservices.ai values used by PncAgent.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            "pncservices.ai.base_url" => "http://localhost:8080/v1",
            "pncservices.ai.api_key" => "test-key",
            "pncservices.ai.model" => "test-model",
            "pncservices.ai.timeout" => 45,
            "pncservices.ai.system_prompt" => "You are a PNC assistant.",
        ]);
    }

    // ─── Constructor ───────────────────────────────────────────────────────

    /**
     * @testdoc Verify that runtime parameters are stored on the instance.
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
     * @testdoc Verify that the constructor defaults to an empty array
     *          when no parameters are provided.
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
     * @testdoc Verify that provider() returns an OpenAILike instance
     *          configured with the base config values and default timeout.
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
     * @testdoc Verify that provider() includes the configured timeout
     *          in the parameters array.
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
     * @testdoc Verify that provider() merges temperature from
     *          runtime parameters into the provider config.
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
     * @testdoc Verify that provider() merges max_tokens from
     *          runtime parameters into the provider config.
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
     * @testdoc Verify that provider() omits temperature and max_tokens
     *          from parameters when they are not in runtime params.
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
     * @testdoc Verify that provider() casts temperature to float
     *          and max_tokens to int.
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
     * @testdoc Verify that instructions() returns a SystemMessage
     *          with the configured system prompt.
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
     * @testdoc Verify that instructions() falls back to the default
     *          prompt when no system_prompt is configured.
     */
    #[Test]
    public function instructions_falls_back_to_default_prompt(): void
    {
        // Unset the key so config() falls back to the default
        $aiConfig = config("pncservices.ai");
        unset($aiConfig["system_prompt"]);
        config(["pncservices.ai" => $aiConfig]);

        $agent = new PncAgent();
        $message = $agent->instructions();

        $this->assertSame(
            "You are a helpful assistant.",
            $message->getContent(),
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
