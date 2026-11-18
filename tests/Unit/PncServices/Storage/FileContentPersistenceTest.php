<?php

declare(strict_types=1);

namespace Tests\Unit\PncServices\Storage;

use App\PncServices\Storage\FileContentPersistence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FileContentPersistence.
 *
 * Verifies that all save/read methods correctly persist and retrieve
 * data from the file system, that parent directories are created
 * automatically on write, and that read methods return null for
 * missing or invalid files.
 *
 * Uses a unique temp directory per test run, cleaned up in @After.
 */
class FileContentPersistenceTest extends TestCase
{
    /**
     * The service under test.
     */
    private FileContentPersistence $service;

    /**
     * Temporary base directory for all file operations in this test.
     */
    private string $baseDir;

    /**
     * Create a unique temp directory and instantiate the service.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = sys_get_temp_dir() . "/pnc_fp_" . uniqid();
        mkdir($this->baseDir, 0755, true);

        $this->service = new FileContentPersistence();
    }

    /**
     * Recursively remove the temp directory after each test.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
        parent::tearDown();
    }

    // ─── saveJson / readJson ───────────────────────────────────────────────

    /**
     * @testdoc Verify that saveJson() writes pretty-printed JSON and
     *          readJson() returns the same data (round-trip).
     */
    #[Test]
    public function json_round_trip(): void
    {
        $data = ["title" => "Hello", "count" => 42, "tags" => ["a", "b"]];
        $path = "{$this->baseDir}/test.json";

        $this->service->saveJson($path, $data);
        $result = $this->service->readJson($path);

        $this->assertSame($data, $result);
    }

    /**
     * @testdoc Verify that readJson() returns null when the file
     *          does not exist.
     */
    #[Test]
    public function readJson_returns_null_for_missing_file(): void
    {
        $result = $this->service->readJson("{$this->baseDir}/nonexistent.json");

        $this->assertNull($result);
    }

    /**
     * @testdoc Verify that readJson() returns null when the file
     *          contains invalid JSON.
     */
    #[Test]
    public function readJson_returns_null_for_invalid_json(): void
    {
        $path = "{$this->baseDir}/invalid.json";
        file_put_contents($path, "not valid json {{{");

        $result = $this->service->readJson($path);

        $this->assertNull($result);
    }

    /**
     * @testdoc Verify that saveJson() creates nested parent directories
     *          that don't exist.
     */
    #[Test]
    public function saveJson_creates_nested_directories(): void
    {
        $path = "{$this->baseDir}/a/b/c/data.json";

        $this->service->saveJson($path, ["ok" => true]);

        $this->assertFileExists($path);
        $this->assertSame(["ok" => true], $this->service->readJson($path));
    }

    /**
     * @testdoc Verify that saveJson() produces pretty-printed output
     *          with unescaped slashes.
     */
    #[Test]
    public function saveJson_uses_pretty_print_and_unescaped_slashes(): void
    {
        $path = "{$this->baseDir}/pretty.json";
        $this->service->saveJson($path, ["url" => "https://example.com/path"]);

        $raw = file_get_contents($path);

        $this->assertStringContainsString("\n", $raw);
        $this->assertStringContainsString("https://example.com/path", $raw);
        $this->assertStringNotContainsString("https:\\/\\/example.com", $raw);
    }

    // ─── saveText / readText ───────────────────────────────────────────────

    /**
     * @testdoc Verify that saveText() writes content and readText()
     *          returns the same content (round-trip).
     */
    #[Test]
    public function text_round_trip(): void
    {
        $content = "# Heading\n\nSome **bold** text.";
        $path = "{$this->baseDir}/test.md";

        $this->service->saveText($path, $content);
        $result = $this->service->readText($path);

        $this->assertSame($content, $result);
    }

    /**
     * @testdoc Verify that readText() returns null when the file
     *          does not exist.
     */
    #[Test]
    public function readText_returns_null_for_missing_file(): void
    {
        $result = $this->service->readText("{$this->baseDir}/nonexistent.md");

        $this->assertNull($result);
    }

    /**
     * @testdoc Verify that saveText() creates nested parent directories.
     */
    #[Test]
    public function saveText_creates_nested_directories(): void
    {
        $path = "{$this->baseDir}/x/y/z/file.txt";

        $this->service->saveText($path, "hello");

        $this->assertFileExists($path);
    }

    // ─── saveHtml / readHtml ───────────────────────────────────────────────

    /**
     * @testdoc Verify that saveHtml() writes HTML and readHtml()
     *          returns the same content (round-trip).
     */
    #[Test]
    public function html_round_trip(): void
    {
        $html = "<div class=\"content\"><h1>Title</h1><p>Body</p></div>";
        $path = "{$this->baseDir}/element.html";

        $this->service->saveHtml($path, $html);
        $result = $this->service->readHtml($path);

        $this->assertSame($html, $result);
    }

    /**
     * @testdoc Verify that readHtml() returns null when the file
     *          does not exist (delegates to readText).
     */
    #[Test]
    public function readHtml_returns_null_for_missing_file(): void
    {
        $result = $this->service->readHtml("{$this->baseDir}/nonexistent.html");

        $this->assertNull($result);
    }

    /**
     * @testdoc Verify that saveHtml() creates nested parent directories.
     */
    #[Test]
    public function saveHtml_creates_nested_directories(): void
    {
        $path = "{$this->baseDir}/out/elements/page.html";

        $this->service->saveHtml($path, "<p>hi</p>");

        $this->assertFileExists($path);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * Recursively remove a directory and its contents.
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
