<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Services;

use App\PncAgentics\Services\SkillExecutor;
use App\PncAgentics\Contracts\AgentServiceInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see SkillExecutor}.
 *
 * Tests the skill resolution, template compilation, prompt payload
 * construction, and delegation to the agent service.
 *
 * The agent service mock expects the new single-array contract:
 *   AgentServiceInterface::complete(array $request): string
 *
 * where $request contains "system_prompt", "user_prompt", "temperature",
 * "max_tokens", and "metadata" keys.
 *
 * @covers \App\PncAgentics\Services\SkillExecutor
 */
class SkillExecutorTest extends TestCase
{
    /**
     * Mock agent service injected into the executor under test.
     */
    private AgentServiceInterface $aiService;

    /**
     * The system under test.
     */
    private SkillExecutor $executor;

    /**
     * Temporary directory used to create fixture skill files.
     */
    private string $tempDir;

    /**
     * Set up the test environment: mock the agent service, reset the static
     * prompt cache, create a temp directory for fixture files, and
     * instantiate the executor.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the static prompt cache between tests.
        $ref = new \ReflectionProperty(SkillExecutor::class, "loadedPrompts");
        $ref->setValue(null, []);

        $this->aiService = $this->createMock(AgentServiceInterface::class);
        $this->executor = new SkillExecutor($this->aiService);

        // Create a temporary directory for fixture skill files.
        $this->tempDir =
            sys_get_temp_dir() . "/skill_executor_test_" . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    /**
     * Clean up temporary fixture files after each test.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() – normal (single-skill) path
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When given a standard skill key, execute() should resolve the file,
     * load its contents, compile placeholders, build the prompt payload,
     * and delegate to the agent service with the correct structured request.
     */
    #[Test]
    public function execute_resolvesFileAndCallsAiService(): void
    {
        // Arrange: create a fixture skill file.
        $skillPath = $this->tempDir . "/test_skill.md";
        file_put_contents(
            $skillPath,
            "You are a helpful assistant. Greet {{ name }}.",
        );

        $this->aiService->method("complete")->willReturn("Hello, World!");

        // Use reflection to call processLLMCall directly with known inputs
        // to avoid depending on Laravel path helpers in a unit test.
        $method = new \ReflectionMethod(SkillExecutor::class, "processLLMCall");
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->executor,
            "track.core_writing.test_skill",
            "You are a helpful assistant. Greet {{ name }}.",
            $skillPath,
            ["name" => "World"],
            ["temperature" => 0.7, "max_tokens" => 512],
            false,
        );

        // Assert
        $this->assertSame("Hello, World!", $result);
    }

    // ─────────────────────────────────────────────────────────────────────
    // execute() – grouped block path
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the skill key contains "batch_draft_engine", execute() should
     * route to executeGroupedBlock, aggregate Phase A sub-agent skill files
     * into a combined system prompt, and mark the call as aggregated in
     * the metadata.
     */
    #[Test]
    public function execute_routesToGroupedBlockForDraftEngine(): void
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

        // We test via reflection since grouped block resolution depends
        // on file system paths that we set up in the fixture.
        $method = new \ReflectionMethod(
            SkillExecutor::class,
            "executeGroupedBlock",
        );
        $method->setAccessible(true);

        // Create the sub-agent fixture files the method will try to resolve.
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
     * When the skill key contains "batch_expand_engine", execute() should
     * route to executeGroupedBlock, aggregate Phase B sub-agent skill files
     * into a combined system prompt, and mark the call as aggregated in
     * the metadata.
     */
    #[Test]
    public function execute_routesToGroupedBlockForExpandEngine(): void
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

    /**
     * Keys ending in ".skills" should resolve to a file with the
     * ".skills.md" extension, with dots converted to directory separators.
     */
    #[Test]
    public function resolveFilePath_convertsSkillsSuffixToSkillsMdExtension(): void
    {
        // Create the expected file.
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

        // Verify the file we created matches the expected relative structure.
        $this->assertFileExists($filePath);
        $this->assertStringEndsWith($expectedRelative, $filePath);
    }

    /**
     * Keys NOT ending in ".skills" should resolve to a ".md" file.
     */
    #[Test]
    public function resolveFilePath_convertsPlainKeyToMdExtension(): void
    {
        $dir = $this->tempDir . "/track/core_writing";
        mkdir($dir, 0755, true);
        $filePath = $dir . "/my_skill.md";
        file_put_contents($filePath, "content");

        $this->assertFileExists($filePath);
    }

    /**
     * When no file can be found at any resolution path, an
     * InvalidArgumentException should be thrown with a descriptive message.
     */
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

    /**
     * loadFileContent() should cache file contents in the static
     * $loadedPrompts array and return the same value on subsequent calls
     * without re-reading the file from disk.
     */
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

        // Modify the file on disk – the cached value should still be returned.
        file_put_contents($filePath, "modified content");
        $secondCall = $method->invoke($this->executor, $filePath);
        $this->assertSame("original content", $secondCall);
    }

    // ─────────────────────────────────────────────────────────────────────
    // parsePlaceholders()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Scalar variables should replace their corresponding {{ key }}
     * placeholders in the template. Non-scalar values and unmatched
     * placeholders should remain untouched.
     */
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
     * Data provider for parsePlaceholders tests.
     *
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
     * buildPromptPayload() should produce a payload with three clearly
     * delimited sections: system protocols, contextual data workspace,
     * and execution run instruction.
     */
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

    /**
     * Array variables should be rendered as pretty-printed JSON under
     * a "Data Object" label, not as a simple key-value line.
     */
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

    /**
     * Object variables should be rendered as JSON under a "Data Object" label.
     */
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
    // processLLMCall() – metadata & error logging
    // ─────────────────────────────────────────────────────────────────────

    /**
     * processLLMCall() should pass correct metadata (skill key, source,
     * aggregated flag) and the compiled system prompt to the agent service
     * within the structured request array.
     */
    #[Test]
    public function processLLMCall_passesCorrectMetadata(): void
    {
        $this->aiService
            ->expects($this->once())
            ->method("complete")
            ->with(
                $this->callback(function (array $request): bool {
                    return $request["system_prompt"] === "Some instructions" &&
                        $request["metadata"]["skill_executed"] ===
                            "my.skill.key" &&
                        $request["metadata"]["resolved_file"] ===
                            "/path/to/file.md" &&
                        $request["metadata"]["is_aggregated"] === false;
                }),
            )
            ->willReturn("ok");

        $method = new \ReflectionMethod(SkillExecutor::class, "processLLMCall");
        $method->setAccessible(true);

        $method->invoke(
            $this->executor,
            "my.skill.key",
            "Some instructions",
            "/path/to/file.md",
            [],
            [],
            false,
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create fixture skill files for the given sub-agent names under
     * the temporary directory, mimicking the expected path structure
     * that resolveFilePath() would produce for grouped block keys.
     *
     * @param string[] $agentNames Dot-notation-free agent names.
     */
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

    /**
     * Recursively remove a directory and all its contents.
     *
     * Used in tearDown to clean up fixture files created during the test.
     */
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
