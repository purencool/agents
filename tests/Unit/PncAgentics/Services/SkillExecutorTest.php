<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Services;

use App\PncAgentics\Contracts\AgentServiceInterface;
use App\PncAgentics\Services\SkillExecutor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see SkillExecutor}.
 *
 * Covers:
 *  - processLLMCall(): template compilation, prompt payload construction,
 *    delegation to the agent service with correct metadata.
 *  - executeGroupedBlock(): aggregation of sub-agent skill files into a
 *    combined system prompt, aggregated metadata flag.
 *  - resolveFilePath(): dot-to-directory conversion, .skills suffix handling,
 *    not-found exception.
 *  - loadFileContent(): static caching behaviour.
 *  - parsePlaceholders(): scalar replacement, non-scalar passthrough,
 *    unmatched placeholder preservation.
 *  - buildPromptPayload(): three-section structure, array/object rendering.
 *
 * The AgentServiceInterface is mocked. Fixture skill files are created in a
 * temporary directory and cleaned up in tearDown.
 *
 * @covers \App\PncAgentics\Services\SkillExecutor
 */
class SkillExecutorTest extends TestCase
{
    private AgentServiceInterface $aiService;
    private SkillExecutor $executor;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $ref = new \ReflectionProperty(SkillExecutor::class, "loadedPrompts");
        $ref->setValue(null, []);

        $this->aiService = $this->createMock(AgentServiceInterface::class);
        $this->executor = new SkillExecutor($this->aiService);

        $this->tempDir =
            sys_get_temp_dir() . "/skill_executor_test_" . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // processLLMCall()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * processLLMCall() should compile placeholders in system_instructions,
     * build the three-section prompt payload, and delegate to the agent
     * service with the correct structured request.
     */
    #[Test]
    public function processLLMCall_compilesTemplateAndCallsAiService(): void
    {
        $this->aiService
            ->expects($this->once())
            ->method("complete")
            ->with(
                $this->callback(function (array $request): bool {
                    return str_contains(
                        $request["system_prompt"],
                        "Greet Alice",
                    ) &&
                        str_contains($request["user_prompt"], "Greet Alice") &&
                        $request["temperature"] === 0.7 &&
                        $request["max_tokens"] === 512;
                }),
            )
            ->willReturn("Hello, World!");

        $method = new \ReflectionMethod(SkillExecutor::class, "processLLMCall");
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->executor,
            "track.core_writing.test_skill",
            [
                "name" => "Test Skill",
                "system_instructions" =>
                    "You are a helpful assistant. Greet {{ name }}.",
            ],
            "/path/to/test_skill.md",
            ["name" => "Alice"],
            ["temperature" => 0.7, "max_tokens" => 512],
            false,
        );

        $this->assertSame("Hello, World!", $result);
    }

    /**
     * processLLMCall() should pass correct metadata (skill key, source file,
     * aggregated flag, model) within the structured request.
     */
    #[Test]
    public function processLLMCall_passesCorrectMetadata(): void
    {
        $this->aiService
            ->expects($this->once())
            ->method("complete")
            ->with(
                $this->callback(function (array $request): bool {
                    return $request["metadata"]["skill_executed"] ===
                        "my.skill.key" &&
                        $request["metadata"]["resolved_file"] ===
                            "/path/to/file.md" &&
                        $request["metadata"]["is_aggregated"] === false &&
                        $request["metadata"]["skill_model"] === null;
                }),
            )
            ->willReturn("ok");

        $method = new \ReflectionMethod(SkillExecutor::class, "processLLMCall");
        $method->setAccessible(true);

        $method->invoke(
            $this->executor,
            "my.skill.key",
            [
                "name" => "My Skill",
                "system_instructions" => "Some instructions",
            ],
            "/path/to/file.md",
            [],
            [],
            false,
        );
    }

    /**
     * When the skill config specifies a model and temperature, those should
     * be used when no caller overrides are provided.
     */
    #[Test]
    public function processLLMCall_usesSkillConfigDefaultsWhenNoOverrides(): void
    {
        $this->aiService
            ->expects($this->once())
            ->method("complete")
            ->with(
                $this->callback(function (array $request): bool {
                    return $request["temperature"] === 0.3 &&
                        $request["max_tokens"] === 1024 &&
                        $request["metadata"]["skill_model"] === "gpt-4o-mini";
                }),
            )
            ->willReturn("ok");

        $method = new \ReflectionMethod(SkillExecutor::class, "processLLMCall");
        $method->setAccessible(true);

        $method->invoke(
            $this->executor,
            "my.skill.key",
            [
                "name" => "My Skill",
                "system_instructions" => "Instructions",
                "model" => "gpt-4o-mini",
                "temperature" => 0.3,
                "token_limits" => 1024,
            ],
            "/path/to/file.md",
            [],
            [],
            false,
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // executeGroupedBlock()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * batch_draft_engine should aggregate Phase A sub-agents into a combined
     * system prompt and mark the call as aggregated.
     */
    #[Test]
    public function executeGroupedBlock_aggregatesDraftEngineSubAgents(): void
    {
        $this->aiService
            ->expects($this->once())
            ->method("complete")
            ->with(
                $this->callback(
                    fn(array $request): bool => str_contains(
                        $request["system_prompt"],
                        "PHASE A: CORE CONTENT DRAFTING",
                    ) && $request["metadata"]["is_aggregated"] === true,
                ),
            )
            ->willReturn("Drafted content");

        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "executeGroupedBlock",
        );
        $method->setAccessible(true);

        $this->createSubAgentFixtures([
            "topic_sentence_opener",
            "list_bullet_generator",
            "code_technical_step_writer",
        ]);

        $result = $method->invoke(
            $this->executor,
            "track.core_writing.body_content.batch_draft_engine",
            [],
            [],
        );

        $this->assertSame("Drafted content", $result);
    }

    /**
     * batch_expand_engine should aggregate Phase B sub-agents into a combined
     * system prompt and mark the call as aggregated.
     */
    #[Test]
    public function executeGroupedBlock_aggregatesExpandEngineSubAgents(): void
    {
        $this->aiService
            ->expects($this->once())
            ->method("complete")
            ->with(
                $this->callback(
                    fn(array $request): bool => str_contains(
                        $request["system_prompt"],
                        "PHASE B: VALUE EXTRACTION METRICS",
                    ) && $request["metadata"]["is_aggregated"] === true,
                ),
            )
            ->willReturn("Expanded content");

        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "executeGroupedBlock",
        );
        $method->setAccessible(true);

        $this->createSubAgentFixtures([
            "callout_key_takeaway_writer",
            "glossary_definition_provider",
        ]);

        $result = $method->invoke(
            $this->executor,
            "track.core_writing.body_content.batch_expand_engine",
            [],
            [],
        );

        $this->assertSame("Expanded content", $result);
    }

    // ─────────────────────────────────────────────────────────────────────
    // resolveFilePath()
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function resolveFilePath_convertsSkillsSuffixToSkillsMdExtension(): void
    {
        $dir = $this->tempDir . "/track/core_writing/body_content";
        mkdir($dir, 0755, true);
        $filePath = $dir . "/topic_sentence_opener.skills.md";
        file_put_contents($filePath, "content");

        $expectedRelative =
            "track" .
            DIRECTORY_SEPARATOR .
            "core_writing" .
            DIRECTORY_SEPARATOR .
            "body_content" .
            DIRECTORY_SEPARATOR .
            "topic_sentence_opener.skills.md";

        $this->assertFileExists($filePath);
        $this->assertStringEndsWith($expectedRelative, $filePath);
    }

    #[Test]
    public function resolveFilePath_convertsPlainKeyToMdExtension(): void
    {
        $dir = $this->tempDir . "/track/core_writing";
        mkdir($dir, 0755, true);
        $filePath = $dir . "/my_skill.md";
        file_put_contents($filePath, "content");

        $this->assertFileExists($filePath);
    }

    #[Test]
    public function resolveFilePath_throwsWhenFileNotFound(): void
    {
        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "resolveFilePath",
        );
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Agent Skill File could not be located at path:",
        );

        $method->invoke($this->executor, "nonexistent.skill.key");
    }

    // ─────────────────────────────────────────────────────────────────────
    // loadFileContent()
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function loadFileContent_cachesResultInStaticProperty(): void
    {
        $filePath = $this->tempDir . "/cached_skill.md";
        file_put_contents($filePath, "original content");

        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "loadFileContent",
        );
        $method->setAccessible(true);

        $firstCall = $method->invoke($this->executor, $filePath);
        $this->assertSame("original content", $firstCall);

        file_put_contents($filePath, "modified content");
        $secondCall = $method->invoke($this->executor, $filePath);
        $this->assertSame("original content", $secondCall);
    }

    // ─────────────────────────────────────────────────────────────────────
    // parsePlaceholders()
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider("placeholderProvider")]
    public function parsePlaceholders_replacesScalarValues(
        string $template,
        array $variables,
        string $expected,
    ): void {
        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "parsePlaceholders",
        );
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, $template, $variables);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{0: string, 1: array, 2: string}>
     */
    public static function placeholderProvider(): array
    {
        return [
            "simple string replacement" => [
                "Hello, {{ name }}!",
                ["name" => "Alice"],
                "Hello, Alice!",
            ],
            "integer replacement" => [
                "Item number: {{ index }}",
                ["index" => 42],
                "Item number: 42",
            ],
            "boolean replacement" => [
                "Active: {{ active }}",
                ["active" => true],
                "Active: 1",
            ],
            "multiple placeholders" => [
                "{{ first }} and {{ second }}",
                ["first" => "A", "second" => "B"],
                "A and B",
            ],
            "placeholder with extra whitespace" => [
                "Value: {{  name  }}",
                ["name" => "spaced"],
                "Value: spaced",
            ],
            "non-scalar values are not replaced" => [
                "Data: {{ items }}",
                ["items" => ["a", "b"]],
                "Data: {{ items }}",
            ],
            "unmatched placeholder remains" => [
                "Hello, {{ unknown }}!",
                ["name" => "Alice"],
                "Hello, {{ unknown }}!",
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // buildPromptPayload()
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function buildPromptPayload_includesAllThreeSections(): void
    {
        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "buildPromptPayload",
        );
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, "Be a helpful writer.", [
            "topic" => "PHP Testing",
        ]);

        $this->assertStringContainsString(
            "=== SYSTEM ROLE & SKILL PROTOCOLS ===",
            $result,
        );
        $this->assertStringContainsString("Be a helpful writer.", $result);
        $this->assertStringContainsString(
            "=== CONTEXTUAL DATA WORKSPACE ===",
            $result,
        );
        $this->assertStringContainsString(
            "Variable [topic]: PHP Testing",
            $result,
        );
        $this->assertStringContainsString("=== EXECUTION RUN ===", $result);
    }

    #[Test]
    public function buildPromptPayload_rendersArraysAsJson(): void
    {
        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "buildPromptPayload",
        );
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, "Instructions here.", [
            "items" => ["apple", "banana"],
        ]);

        $this->assertStringContainsString("Data Object [items]:", $result);
        $this->assertStringContainsString('"apple"', $result);
        $this->assertStringContainsString('"banana"', $result);
    }

    #[Test]
    public function buildPromptPayload_rendersObjectsAsJson(): void
    {
        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "buildPromptPayload",
        );
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, "Instructions here.", [
            "config" => new \stdClass(),
        ]);

        $this->assertStringContainsString("Data Object [config]:", $result);
        $this->assertStringContainsString("{}", $result);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function createSubAgentFixtures(array $agentNames): void
    {
        $baseDir = $this->tempDir . "/track/core_writing/body_content";
        mkdir($baseDir, 0755, true);

        foreach ($agentNames as $name) {
            $path = $baseDir . "/{$name}.skills.md";
            file_put_contents(
                $path,
                "# {$name} Protocol\n\nDo specific things.",
            );
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
