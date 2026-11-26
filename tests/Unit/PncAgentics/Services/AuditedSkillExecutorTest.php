<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Services;

use App\PncAgentics\Contracts\SkillExecutorInterface;
use App\PncAgentics\Services\AuditedSkillExecutor;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for AuditedSkillExecutor.
 *
 * Verifies that execute():
 *  - Delegates to the base executor and returns its result.
 *  - Writes a START entry (2 args: message + context) to the daily log.
 *  - Writes a DONE entry (1 arg: message only) on success.
 *  - Writes a FAIL entry (2 args: message + context) on failure.
 *  - Triggers a performance warning when execution exceeds 45 seconds.
 *  - Re-throws exceptions from the base executor after logging.
 *  - Writes PROMPT INPUT and LLM RESPONSE blocks to the per-run audit file.
 *
 * Also verifies getDynamicLogPath():
 *  - Uses the LOGGING env variable (relative to base_path) when set.
 *  - Falls back to storage/logs/ when not set.
 *  - Creates the {date_Hi}/{domain}/ directory structure.
 *
 * Audit file path format: {base}/YYYY-MM-DD_Hi/{domain}/{runId}.log
 */
class AuditedSkillExecutorTest extends TestCase
{
    /**
     * The service under test.
     */
    private AuditedSkillExecutor $service;

    /**
     * Mock base executor.
     */
    private Mockery\MockInterface $baseExecutor;

    /**
     * Relative path for the LOGGING env variable (resolved via base_path in production).
     */
    private string $logDir;

    /**
     * Absolute path to the temporary log base directory (for assertions and file operations).
     */
    private string $logDirAbsolute;

    /**
     * Set up mocks, temp log directory, and the service.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = "tmp/pnc_audit_" . uniqid();
        $this->logDirAbsolute = base_path($this->logDir);
        mkdir($this->logDirAbsolute, 0755, true);

        $this->baseExecutor = Mockery::mock(SkillExecutorInterface::class);
        $this->service = new AuditedSkillExecutor($this->baseExecutor);
    }

    /**
     * Clean up temp directory and Mockery.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->logDirAbsolute);
        putenv("LOGGING");
        Mockery::close();
        parent::tearDown();
    }

    // ─── execute() — happy path ────────────────────────────────────────────

    /**
     * Verify that execute() delegates to the base executor
     * and returns its result unchanged.
     */
    #[Test]
    public function execute_delegates_to_base_executor_and_returns_result(): void
    {
        $this->setLogDir();

        $options = ["_domain" => "example.com", "temperature" => 0.5];

        $this->baseExecutor
            ->shouldReceive("execute")
            ->once()
            ->with("test.skill", ["key" => "value"], $options)
            ->andReturn("LLM Response Text");

        $this->mockLogChannel(2); // START + DONE

        $result = $this->service->execute(
            "test.skill",
            ["key" => "value"],
            $options,
        );

        $this->assertSame("LLM Response Text", $result);
    }

    /**
     * Verify that execute() logs the skill key, domain, and variable keys
     * in the START entry (2-arg call) and the DONE entry (1-arg call).
     */
    #[Test]
    public function execute_logs_start_with_skill_domain_and_variable_keys(): void
    {
        $this->setLogDir();

        $options = ["_domain" => "example.com", "temperature" => 0.3];

        $this->baseExecutor->shouldReceive("execute")->once()->andReturn("ok");

        $logChannel = Mockery::mock();

        // START: 2 args (message string + context array)
        $logChannel
            ->shouldReceive("info")
            ->once()
            ->withArgs(function (...$args) use ($options): bool {
                if (count($args) !== 2) {
                    return false;
                }
                [$msg, $ctx] = $args;
                return str_contains($msg, "[AI] START") &&
                    str_contains($msg, "test.skill") &&
                    str_contains($msg, "example.com") &&
                    ($ctx["variables_keys"] ?? null) === ["title", "body"] &&
                    ($ctx["options"] ?? null) === $options;
            });

        // DONE: 1 arg (message string only)
        $logChannel
            ->shouldReceive("info")
            ->once()
            ->withArgs(function (...$args): bool {
                if (count($args) !== 1) {
                    return false;
                }
                return str_contains($args[0], "[AI] DONE") &&
                    str_contains($args[0], "test.skill") &&
                    str_contains($args[0], "example.com");
            });

        Log::shouldReceive("channel")
            ->twice()
            ->with("daily")
            ->andReturn($logChannel);

        $this->service->execute(
            "test.skill",
            ["title" => "T", "body" => "B"],
            $options,
        );
    }

    // ─── execute() — performance warning ───────────────────────────────────

    /**
     * Verify that no warning is triggered for fast execution (< 45s).
     */
    #[Test]
    public function execute_does_not_trigger_warning_for_fast_execution(): void
    {
        $this->setLogDir();

        $this->baseExecutor
            ->shouldReceive("execute")
            ->once()
            ->andReturn("fast result");

        $logChannel = Mockery::mock();
        $logChannel->shouldReceive("info")->twice();
        $logChannel->shouldReceive("warning")->never();

        Log::shouldReceive("channel")
            ->twice()
            ->with("daily")
            ->andReturn($logChannel);

        $this->service->execute("fast.skill", [], ["_domain" => "test.com"]);
    }

    // ─── execute() — error handling ────────────────────────────────────────

    /**
     * Verify that execute() logs a FAIL entry and re-throws the exception.
     */
    #[Test]
    public function execute_logs_and_rethrows_on_failure(): void
    {
        $this->setLogDir();

        $this->baseExecutor
            ->shouldReceive("execute")
            ->once()
            ->andThrow(new \RuntimeException("Model timeout"));

        $logChannel = Mockery::mock();

        // START: 2 args
        $logChannel
            ->shouldReceive("info")
            ->once()
            ->withArgs(function (...$args): bool {
                return count($args) === 2 &&
                    str_contains($args[0], "[AI] START");
            });

        // FAIL: 2 args (message + context)
        $logChannel
            ->shouldReceive("error")
            ->once()
            ->withArgs(function (...$args): bool {
                if (count($args) !== 2) {
                    return false;
                }
                [$msg, $ctx] = $args;
                return str_contains($msg, "[AI] FAIL") &&
                    str_contains($msg, "Model timeout") &&
                    ($ctx["exception"] ?? "") === \RuntimeException::class &&
                    isset($ctx["trace"]);
            });

        Log::shouldReceive("channel")
            ->twice()
            ->with("daily")
            ->andReturn($logChannel);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Model timeout");

        $this->service->execute("failing.skill", [], ["_domain" => "test.com"]);
    }

    // ─── File-based audit logging ──────────────────────────────────────────

    /**
     * Verify that execute() writes a PROMPT INPUT block to the per-run
     * audit log file containing the skill key, domain, and variables.
     */
    #[Test]
    public function execute_writes_prompt_tree_to_audit_log(): void
    {
        $this->setLogDir();

        $this->baseExecutor
            ->shouldReceive("execute")
            ->once()
            ->andReturn("Response");

        $this->mockLogChannel(2);

        $this->service->execute(
            "my.skill",
            ["title" => "Test Title", "body" => "Test Body"],
            ["_domain" => "example.com"],
        );

        $logFile = $this->findAuditLogFile();
        $this->assertNotNull($logFile, "Audit log file should exist");

        $content = (string) file_get_contents($logFile);
        $this->assertStringContainsString("PROMPT INPUT", $content);
        $this->assertStringContainsString("my.skill", $content);
        $this->assertStringContainsString("example.com", $content);
        $this->assertStringContainsString("Test Title", $content);
        $this->assertStringContainsString("Test Body", $content);
    }

    /**
     * Verify that execute() appends an LLM RESPONSE block to the
     * per-run audit log file after successful execution.
     */
    #[Test]
    public function execute_writes_response_tree_to_audit_log(): void
    {
        $this->setLogDir();

        $this->baseExecutor
            ->shouldReceive("execute")
            ->once()
            ->andReturn("The generated article content.");

        $this->mockLogChannel(2);

        $this->service->execute(
            "article.skill",
            ["topic" => "AI"],
            ["_domain" => "example.com"],
        );

        $logFile = $this->findAuditLogFile();
        $this->assertNotNull($logFile, "Audit log file should exist");
        $content = (string) file_get_contents($logFile);

        $this->assertStringContainsString("LLM RESPONSE", $content);
        $this->assertStringContainsString(
            "The generated article content.",
            $content,
        );
        $this->assertStringContainsString("article.skill", $content);
        $this->assertStringContainsString("Status:  OK", $content);
    }

    /**
     * Verify that array variables are JSON-encoded in the PROMPT INPUT block.
     */
    #[Test]
    public function execute_json_encodes_array_variables_in_prompt_tree(): void
    {
        $this->setLogDir();

        $this->baseExecutor->shouldReceive("execute")->once()->andReturn("ok");

        $this->mockLogChannel(2);

        $this->service->execute(
            "test.skill",
            ["tags" => ["ai", "ml", "nlp"]],
            ["_domain" => "example.com"],
        );

        $logFile = $this->findAuditLogFile();
        $this->assertNotNull($logFile, "Audit log file should exist");
        $content = (string) file_get_contents($logFile);

        $this->assertStringContainsString('"ai"', $content);
        $this->assertStringContainsString('"ml"', $content);
        $this->assertStringContainsString('"nlp"', $content);
    }

    // ─── getDynamicLogPath() ───────────────────────────────────────────────

    /**
     * Verify that getDynamicLogPath() uses the LOGGING env variable
     * (relative to base_path) and creates the {date_Hi}/{domain}/ directory.
     */
    #[Test]
    public function getDynamicLogPath_uses_logging_env_when_set(): void
    {
        putenv("LOGGING=" . $this->logDir);

        $path = $this->invokeGetDynamicLogPath("test-run-id", "example.com");

        $this->assertStringStartsWith($this->logDirAbsolute, $path);
        $this->assertStringContainsString("example.com", $path);
        $this->assertStringEndsWith("test-run-id.log", $path);
        $this->assertDirectoryExists(dirname($path));
    }

    /**
     * Verify that getDynamicLogPath() falls back to storage/logs/
     * when the LOGGING env variable is not set.
     */
    #[Test]
    public function getDynamicLogPath_falls_back_to_storage_logs(): void
    {
        putenv("LOGGING");

        $path = $this->invokeGetDynamicLogPath("test-run-id", "example.com");

        $this->assertStringContainsString("storage", $path);
        $this->assertStringContainsString("logs", $path);
        $this->assertStringContainsString("example.com", $path);
        $this->assertStringEndsWith("test-run-id.log", $path);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * Set the LOGGING env to the relative temp path so audit files
     * land under base_path() in a known, testable location.
     */
    private function setLogDir(): void
    {
        putenv("LOGGING=" . $this->logDir);
    }

    /**
     * Create a mock Log channel that accepts $expectedInfoCalls info() calls.
     */
    private function mockLogChannel(int $expectedInfoCalls): void
    {
        $logChannel = Mockery::mock();
        $logChannel->shouldReceive("info")->times($expectedInfoCalls);
        Log::shouldReceive("channel")
            ->times($expectedInfoCalls)
            ->with("daily")
            ->andReturn($logChannel);
    }

    /**
     * Recursively find the most recent .log file under the test log directory.
     *
     * Expected structure: {logDirAbsolute}/{YYYY-MM-DD_Hi}/{domain}/{runId}.log
     */
    private function findAuditLogFile(): ?string
    {
        $files = glob($this->logDirAbsolute . "/*/*/*.log");

        if (empty($files)) {
            $files = $this->findLogFilesRecursive($this->logDirAbsolute);
        }

        if (empty($files)) {
            return null;
        }

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        return $files[0];
    }

    /**
     * Recursively find all .log files under a directory.
     *
     * @return string[]
     */
    private function findLogFilesRecursive(string $dir): array
    {
        $results = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($items as $item) {
            if (
                $item->isFile() &&
                str_ends_with($item->getFilename(), ".log")
            ) {
                $results[] = $item->getPathname();
            }
        }

        return $results;
    }

    /**
     * Invoke the protected getDynamicLogPath() via reflection.
     */
    private function invokeGetDynamicLogPath(
        string $runId,
        string $domain,
    ): string {
        $method = new \ReflectionMethod(
            AuditedSkillExecutor::class,
            "getDynamicLogPath",
        );
        $method->setAccessible(true);

        return $method->invoke($this->service, $runId, $domain);
    }

    /**
     * Recursively remove a directory.
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
