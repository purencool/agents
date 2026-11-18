<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncServices\Storage;

use App\PncServices\Storage\FileContentPersistence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for FileContentPersistence.
 *
 * Covers both usage modes:
 *  - Stateless (full-path API): saveJson, readJson, saveText, readText,
 *    saveHtml, readHtml.
 *  - Stateful (base-path API): setPath, path, save, saveJsonTo, saveHtmlTo,
 *    saveMarkdown, append, saveFromPath, saveJsonFromPath, saveSibling.
 *
 * Uses a unique temp directory per test run, cleaned up in tearDown.
 */
class FileContentPersistenceTest extends TestCase
{
    /** @var FileContentPersistence */
    private FileContentPersistence $service;

    /** @var string */
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

    // ─────────────────────────────────────────────────────────────
    // Stateless: saveJson / readJson
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function json_round_trip(): void
    {
        $data = ["title" => "Hello", "count" => 42, "tags" => ["a", "b"]];
        $path = "{$this->baseDir}/test.json";

        $this->service->saveJson($path, $data);
        $result = $this->service->readJson($path);

        $this->assertSame($data, $result);
    }

    #[Test]
    public function readJson_returns_null_for_missing_file(): void
    {
        $result = $this->service->readJson("{$this->baseDir}/nonexistent.json");

        $this->assertNull($result);
    }

    #[Test]
    public function readJson_returns_null_for_invalid_json(): void
    {
        $path = "{$this->baseDir}/invalid.json";
        file_put_contents($path, "not valid json {{{");

        $result = $this->service->readJson($path);

        $this->assertNull($result);
    }

    #[Test]
    public function saveJson_creates_nested_directories(): void
    {
        $path = "{$this->baseDir}/a/b/c/data.json";

        $this->service->saveJson($path, ["ok" => true]);

        $this->assertFileExists($path);
        $this->assertSame(["ok" => true], $this->service->readJson($path));
    }

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

    // ─────────────────────────────────────────────────────────────
    // Stateless: saveText / readText
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function text_round_trip(): void
    {
        $content = "# Heading\n\nSome **bold** text.";
        $path = "{$this->baseDir}/test.md";

        $this->service->saveText($path, $content);
        $result = $this->service->readText($path);

        $this->assertSame($content, $result);
    }

    #[Test]
    public function readText_returns_null_for_missing_file(): void
    {
        $result = $this->service->readText("{$this->baseDir}/nonexistent.md");

        $this->assertNull($result);
    }

    #[Test]
    public function saveText_creates_nested_directories(): void
    {
        $path = "{$this->baseDir}/x/y/z/file.txt";

        $this->service->saveText($path, "hello");

        $this->assertFileExists($path);
    }

    // ─────────────────────────────────────────────────────────────
    // Stateless: saveHtml / readHtml
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function html_round_trip(): void
    {
        $html = "<div class=\"content\"><h1>Title</h1><p>Body</p></div>";
        $path = "{$this->baseDir}/element.html";

        $this->service->saveHtml($path, $html);
        $result = $this->service->readHtml($path);

        $this->assertSame($html, $result);
    }

    #[Test]
    public function readHtml_returns_null_for_missing_file(): void
    {
        $result = $this->service->readHtml("{$this->baseDir}/nonexistent.html");

        $this->assertNull($result);
    }

    #[Test]
    public function saveHtml_creates_nested_directories(): void
    {
        $path = "{$this->baseDir}/out/elements/page.html";

        $this->service->saveHtml($path, "<p>hi</p>");

        $this->assertFileExists($path);
    }

    // ─────────────────────────────────────────────────────────────
    // Stateful: setPath / path
    // ─────────────────────────────────────────────────────────────

    /**
     * setPath() creates the directory if it doesn't exist and
     * path() returns the trimmed path.
     */
    #[Test]
    public function setPath_creates_directory_and_path_returns_it(): void
    {
        $target = "{$this->baseDir}/new_dir/";

        $this->service->setPath($target);

        $this->assertDirectoryExists($target);
        $this->assertSame("{$this->baseDir}/new_dir", $this->service->path());
    }

    /**
     * path() throws RuntimeException when setPath() has not been called.
     */
    #[Test]
    public function path_throws_when_not_set(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("path not set");

        $this->service->path();
    }

    // ─────────────────────────────────────────────────────────────
    // Stateful: save
    // ─────────────────────────────────────────────────────────────

    /**
     * save() writes raw data to base/filename.ext and returns the full path.
     */
    #[Test]
    public function save_writes_file_and_returns_path(): void
    {
        $this->service->setPath($this->baseDir);

        $result = $this->service->save("raw content", "myfile", "txt");

        $this->assertSame("{$this->baseDir}/myfile.txt", $result);
        $this->assertFileExists($result);
        $this->assertSame("raw content", file_get_contents($result));
    }

    /**
     * save() defaults to "json" extension when none is specified.
     */
    #[Test]
    public function save_defaults_to_json_extension(): void
    {
        $this->service->setPath($this->baseDir);

        $result = $this->service->save("{}", "data");

        $this->assertSame("{$this->baseDir}/data.json", $result);
    }

    // ─────────────────────────────────────────────────────────────
    // Stateful: saveJsonTo
    // ─────────────────────────────────────────────────────────────

    /**
     * saveJsonTo() encodes data as pretty JSON and saves to base path.
     */
    #[Test]
    public function saveJsonTo_writes_pretty_json(): void
    {
        $this->service->setPath($this->baseDir);

        $result = $this->service->saveJsonTo("output", ["key" => "value"]);

        $this->assertSame("{$this->baseDir}/output.json", $result);
        $this->assertSame(
            ["key" => "value"],
            $this->service->readJson($result),
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Stateful: saveHtmlTo
    // ─────────────────────────────────────────────────────────────

    /**
     * saveHtmlTo() writes HTML to base path and returns the full path.
     */
    #[Test]
    public function saveHtmlTo_writes_html(): void
    {
        $this->service->setPath($this->baseDir);

        $result = $this->service->saveHtmlTo("page", "<h1>Hi</h1>");

        $this->assertSame("{$this->baseDir}/page.html", $result);
        $this->assertSame("<h1>Hi</h1>", file_get_contents($result));
    }

    // ─────────────────────────────────────────────────────────────
    // Stateful: saveMarkdown
    // ─────────────────────────────────────────────────────────────

    /**
     * saveMarkdown() writes markdown with .md extension.
     */
    #[Test]
    public function saveMarkdown_writes_md_file(): void
    {
        $this->service->setPath($this->baseDir);

        $result = $this->service->saveMarkdown("# Title\n\nBody.", "post");

        $this->assertSame("{$this->baseDir}/post.md", $result);
        $this->assertSame("# Title\n\nBody.", file_get_contents($result));
    }

    // ─────────────────────────────────────────────────────────────
    // Stateful: append
    // ─────────────────────────────────────────────────────────────

    /**
     * append() adds content to an existing file without overwriting.
     */
    #[Test]
    public function append_adds_to_existing_file(): void
    {
        $this->service->setPath($this->baseDir);

        $path = $this->service->save("first line\n", "log", "txt");
        $this->service->append("second line\n", "log", "txt");

        $this->assertSame(
            "first line\nsecond line\n",
            file_get_contents($path),
        );
    }

    /**
     * append() creates the file if it doesn't exist yet.
     */
    #[Test]
    public function append_creates_file_if_missing(): void
    {
        $this->service->setPath($this->baseDir);

        $path = $this->service->append("only content", "newlog", "txt");

        $this->assertFileExists($path);
        $this->assertSame("only content", file_get_contents($path));
    }

    // ─────────────────────────────────────────────────────────────
    // Stateful: saveFromPath
    // ─────────────────────────────────────────────────────────────

    /**
     * saveFromPath() derives the directory and filename from a reference
     * path, strips the original extension, and saves with the given ext.
     */
    #[Test]
    public function saveFromPath_derives_path_from_reference(): void
    {
        $reference = "{$this->baseDir}/data/mcp/page.json";

        $result = $this->service->saveFromPath("content", $reference, "html");

        $this->assertSame("{$this->baseDir}/data/mcp/page.html", $result);
        $this->assertFileExists($result);
    }

    /**
     * saveFromPath() infers the extension from the reference when
     * no explicit ext is given.
     */
    #[Test]
    public function saveFromPath_infers_extension_when_null(): void
    {
        $reference = "{$this->baseDir}/data/report.md";

        $result = $this->service->saveFromPath("content", $reference);

        $this->assertSame("{$this->baseDir}/data/report.md", $result);
    }

    // ─────────────────────────────────────────────────────────────
    // Stateful: saveJsonFromPath
    // ─────────────────────────────────────────────────────────────

    /**
     * saveJsonFromPath() encodes data as JSON and saves to the
     * derived path.
     */
    #[Test]
    public function saveJsonFromPath_writes_json_to_derived_path(): void
    {
        $reference = "{$this->baseDir}/out/content.json";
        $data = ["result" => "success"];

        $result = $this->service->saveJsonFromPath($data, $reference);

        $this->assertSame("{$this->baseDir}/out/content.json", $result);
        $this->assertSame($data, $this->service->readJson($result));
    }

    // ─────────────────────────────────────────────────────────────
    // Stateful: saveSibling
    // ─────────────────────────────────────────────────────────────

    /**
     * saveSibling() saves to the same directory and base name as the
     * reference but with a different extension.
     */
    #[Test]
    public function saveSibling_writes_to_same_dir_with_new_ext(): void
    {
        $reference = "{$this->baseDir}/mcp/page.json";

        $result = $this->service->saveSibling(
            "<html></html>",
            $reference,
            "html",
        );

        $this->assertSame("{$this->baseDir}/mcp/page.html", $result);
        $this->assertFileExists($result);
        $this->assertSame("<html></html>", file_get_contents($result));
    }

    /**
     * saveSibling() creates the directory if it doesn't exist.
     */
    #[Test]
    public function saveSibling_creates_directory_if_missing(): void
    {
        $reference = "{$this->baseDir}/new/deep/file.json";

        $result = $this->service->saveSibling("data", $reference, "html");

        $this->assertDirectoryExists("{$this->baseDir}/new/deep");
        $this->assertSame("{$this->baseDir}/new/deep/file.html", $result);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

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
