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
 *  - buildPromptPayload(): contextual data workspace + execution run sections,
 *    array/object JSON rendering.
 *
 * Prompt architecture (post-refactor):
 *  - system_prompt: Compiled skill instructions (placeholders resolved).
 *  - user_prompt:   Contextual data workspace + execution run instruction only.
 *                   Does NOT contain the system instructions.
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
     * build the user prompt payload (contextual data only, no system
     * instructions), and delegate to the agent service with the correct
     * structured request.
     */
    #[Test]
    public function processLLMCall_compilesTemplateAndCallsAiService(): void
    {
        $this->aiService
            ->expects($this->once())
            ->method("complete")
            ->with(
                $this->callback(function (array $request): bool {
                    // System prompt should contain the resolved instructions
                    return str_contains(
                        $request["system_prompt"],
                        "Greet Alice",
                    ) &&
                        // User prompt should contain the variable in the data workspace
                        str_contains(
                            $request["user_prompt"],
                            "Variable [name]: Alice",
                        ) &&
                        // User prompt should NOT contain the system instructions
                        !str_contains(
                            $request["user_prompt"],
                            "You are a helpful assistant",
                        ) &&
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
    public function resolveFilePath_convertsSkillsSuffixToSkillsJsonExtension(): void
    {
        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "resolveFilePath",
        );
        $method->setAccessible(true);

        // Create the expected file at the fallback path
        $relativePath =
            "track/core_writing/body_content/topic_sentence_opener.skills.json";
        $fallbackDir = app_path("PncAgentics/Skills");
        $fullPath =
            $fallbackDir .
            "/" .
            str_replace("/", DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $fullPath,
            json_encode(["name" => "test", "system_instructions" => "x"]),
        );

        $result = $method->invoke(
            $this->executor,
            "track.core_writing.body_content.topic_sentence_opener.skills",
        );

        $this->assertFileExists($result);
        $this->assertStringEndsWith(
            "topic_sentence_opener.skills.json",
            $result,
        );

        // Cleanup
        unlink($fullPath);
    }

    #[Test]
    public function resolveFilePath_convertsPlainKeyToSkillsJsonExtension(): void
    {
        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "resolveFilePath",
        );
        $method->setAccessible(true);

        $relativePath = "track/core_writing/my_skill.skills.json";
        $fallbackDir = app_path("PncAgentics/Skills");
        $fullPath =
            $fallbackDir .
            "/" .
            str_replace("/", DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $fullPath,
            json_encode(["name" => "test", "system_instructions" => "x"]),
        );

        $result = $method->invoke(
            $this->executor,
            "track.core_writing.my_skill",
        );

        $this->assertFileExists($result);
        $this->assertStringEndsWith("my_skill.skills.json", $result);

        // Cleanup
        unlink($fullPath);
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
        $filePath = $this->tempDir . "/cached_skill.json";
        file_put_contents(
            $filePath,
            '{"name":"test","system_instructions":"original"}',
        );

        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "loadFileContent",
        );
        $method->setAccessible(true);

        $firstCall = $method->invoke($this->executor, $filePath);
        $this->assertSame(
            '{"name":"test","system_instructions":"original"}',
            $firstCall,
        );

        file_put_contents(
            $filePath,
            '{"name":"test","system_instructions":"modified"}',
        );
        $secondCall = $method->invoke($this->executor, $filePath);
        $this->assertSame(
            '{"name":"test","system_instructions":"original"}',
            $secondCall,
        );
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

    /**
     * buildPromptPayload() should produce a two-section payload:
     *  1. === CONTEXTUAL DATA WORKSPACE === (variables)
     *  2. === EXECUTION RUN === (fixed instruction)
     *
     * It should NOT contain system instructions (those go in system_prompt).
     */
    #[Test]
    public function buildPromptPayload_includesDataWorkspaceAndExecutionRun(): void
    {
        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "buildPromptPayload",
        );
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, [
            "topic" => "PHP Testing",
            "lang" => "en",
        ]);

        // Should contain the data workspace section
        $this->assertStringContainsString(
            "=== CONTEXTUAL DATA WORKSPACE ===",
            $result,
        );
        $this->assertStringContainsString(
            "Variable [topic]: PHP Testing",
            $result,
        );
        $this->assertStringContainsString("Variable [lang]: en", $result);

        // Should contain the execution run section
        $this->assertStringContainsString("=== EXECUTION RUN ===", $result);
        $this->assertStringContainsString(
            "Execute the task using the constraints defined above.",
            $result,
        );

        // Should NOT contain system instructions section
        $this->assertStringNotContainsString(
            "=== SYSTEM ROLE & SKILL PROTOCOLS ===",
            $result,
        );
    }

    /**
     * buildPromptPayload() should render array values as pretty-printed JSON
     * under a "Data Object [key]:" label.
     */
    #[Test]
    public function buildPromptPayload_rendersArraysAsJson(): void
    {
        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "buildPromptPayload",
        );
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, [
            "items" => ["apple", "banana"],
        ]);

        $this->assertStringContainsString("Data Object [items]:", $result);
        $this->assertStringContainsString('"apple"', $result);
        $this->assertStringContainsString('"banana"', $result);
    }

    /**
     * buildPromptPayload() should render object values as JSON
     * under a "Data Object [key]:" label.
     */
    #[Test]
    public function buildPromptPayload_rendersObjectsAsJson(): void
    {
        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "buildPromptPayload",
        );
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, [
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
            $path = $baseDir . "/{$name}.skills.json";
            file_put_contents(
                $path,
                json_encode([
                    "name" => $name,
                    "system_instructions" => "# {$name} Protocol\n\nDo specific things.",
                ]),
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
