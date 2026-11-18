<?php

namespace Tests\Unit\PncServices\AI;

use App\PncServices\AI\AIService;
use App\PncServices\AI\PncAgent;
use App\PncServices\Contracts\LoggingServiceInterface;
use Illuminate\Support\Facades\Log;
use Mockery;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for AIService.
 */
class AIServiceTest extends TestCase
{
    private AIService $service;
    private Mockery\MockInterface $agentMock;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            "pncservices.ai.base_url" => "http://localhost:8080/v1",
            "pncservices.ai.api_key" => "test-key",
            "pncservices.ai.model" => "test-model",
            "pncservices.ai.system_prompt" => "You are a helpful assistant.",
        ]);

        $logger = Mockery::mock(LoggingServiceInterface::class);
        $this->agentMock = Mockery::mock(PncAgent::class);

        $this->service = new class ($logger) extends AIService {
            public Mockery\MockInterface $mockAgent;

            protected function createAgent(array $options = []): PncAgent
            {
                return $this->mockAgent;
            }
        };

        $this->service->mockAgent = $this->agentMock;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── complete() ────────────────────────────────────────────────────────

    #[Test]
    public function complete_returns_response_on_success(): void
    {
        $responseContent = "The sky is blue.";

        $message = Mockery::mock(Message::class); // ← typed as Message
        $message
            ->shouldReceive("getContent")
            ->once()
            ->andReturn($responseContent);

        $chatResult = Mockery::mock(AgentState::class);
        $chatResult->shouldReceive("getMessage")->once()->andReturn($message);

        $this->agentMock
            ->shouldReceive("setThreadId")
            ->once()
            ->with("one-shot");
        $this->agentMock
            ->shouldReceive("chat")
            ->once()
            ->with(Mockery::on(fn($m) => $m instanceof UserMessage))
            ->andReturn($chatResult);

        $result = $this->service->complete("Why is the sky blue?");

        $this->assertSame($responseContent, $result);
    }

    #[Test]
    public function complete_applies_system_instruction(): void
    {
        $responseContent = "I am a helpful assistant.";

        $message = Mockery::mock(Message::class); // ← typed as Message
        $message
            ->shouldReceive("getContent")
            ->once()
            ->andReturn($responseContent);

        $chatResult = Mockery::mock(AgentState::class);
        $chatResult->shouldReceive("getMessage")->once()->andReturn($message);

        $this->agentMock
            ->shouldReceive("setThreadId")
            ->once()
            ->with("one-shot");
        $this->agentMock
            ->shouldReceive("setInstructions")
            ->once()
            ->with("You are a helpful assistant.");
        $this->agentMock->shouldReceive("chat")->once()->andReturn($chatResult);

        $result = $this->service->complete("Hello", [
            "system" => "You are a helpful assistant.",
        ]);

        $this->assertSame($responseContent, $result);
    }

    #[Test]
    public function complete_logs_and_rethrows_on_failure(): void
    {
        $this->agentMock
            ->shouldReceive("setThreadId")
            ->once()
            ->with("one-shot");
        $this->agentMock
            ->shouldReceive("chat")
            ->once()
            ->andThrow(new \RuntimeException("Connection timeout"));

        Log::shouldReceive("error")
            ->once()
            ->withArgs(function (string $msg, array $ctx): bool {
                return str_contains($msg, "AIService::complete") &&
                    $ctx["error"] === "Connection timeout" &&
                    isset($ctx["trace"]);
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Connection timeout");

        $this->service->complete("Test prompt");
    }

    // ─── embed() ───────────────────────────────────────────────────────────

    #[Test]
    public function embed_returns_vector_on_success(): void
    {
        $expectedVector = [0.1, 0.2, 0.3, 0.4];

        $embeddingsMock = Mockery::mock(OpenAILikeEmbeddings::class);
        $embeddingsMock
            ->shouldReceive("embedText")
            ->once()
            ->with("Hello world")
            ->andReturn($expectedVector);

        $this->swapEmbeddings($embeddingsMock);

        $result = $this->service->embed("Hello world");

        $this->assertSame($expectedVector, $result);
    }

    #[Test]
    public function embed_logs_and_rethrows_on_failure(): void
    {
        $embeddingsMock = Mockery::mock(OpenAILikeEmbeddings::class);
        $embeddingsMock
            ->shouldReceive("embedText")
            ->once()
            ->andThrow(new \RuntimeException("API rate limited"));

        $this->swapEmbeddings($embeddingsMock);

        Log::shouldReceive("error")
            ->once()
            ->withArgs(function (string $msg, array $ctx): bool {
                return str_contains($msg, "AIService::embed") &&
                    $ctx["error"] === "API rate limited";
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("API rate limited");

        $this->service->embed("Some text");
    }

    // ─── chat() ────────────────────────────────────────────────────────────

    #[Test]
    public function chat_returns_response_on_success(): void
    {
        $responseContent = "Hello! How can I help you today?";

        $message = Mockery::mock(Message::class); // ← typed as Message
        $message
            ->shouldReceive("getContent")
            ->once()
            ->andReturn($responseContent);

        $chatResult = Mockery::mock(AgentState::class);
        $chatResult->shouldReceive("getMessage")->once()->andReturn($message);

        $this->agentMock->shouldReceive("setThreadId")->once()->with("default");
        $this->agentMock
            ->shouldReceive("chat")
            ->once()
            ->with(Mockery::on(fn($m) => $m instanceof UserMessage))
            ->andReturn($chatResult);

        $result = $this->service->chat("Hi there");

        $this->assertSame($responseContent, $result);
    }

    #[Test]
    public function chat_logs_and_rethrows_on_failure(): void
    {
        $this->agentMock->shouldReceive("setThreadId")->once()->with("default");
        $this->agentMock
            ->shouldReceive("chat")
            ->once()
            ->andThrow(new \RuntimeException("Model overloaded"));

        Log::shouldReceive("error")
            ->once()
            ->withArgs(function (string $msg, array $ctx): bool {
                return str_contains($msg, "AIService::chat") &&
                    $ctx["thread_id"] === "default" &&
                    $ctx["error"] === "Model overloaded";
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Model overloaded");

        $this->service->chat("Test message");
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function swapEmbeddings(object $mock): void
    {
        $ref = new \ReflectionProperty(AIService::class, "embeddings");
        $ref->setAccessible(true);
        $ref->setValue($this->service, $mock);
    }
}
