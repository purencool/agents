<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Workflows;

use App\PncAgentics\Workflows\TemplateResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see TemplateResolver}.
 *
 * Covers:
 *  - resolveTemplate(): single placeholder, multiple placeholders, missing keys,
 *    no placeholders, numeric values, array values (JSON-encoded), object values.
 *  - resolveVariable(): single {{key}} returns raw value (type preserved),
 *    mixed template returns interpolated string, missing key returns null.
 *  - resolveVariables(): string values are resolved, non-string values pass through.
 *
 * @covers \App\PncAgentics\Workflows\TemplateResolver
 */
class TemplateResolverTest extends TestCase
{
    /**
     * The system under test.
     */
    private TemplateResolver $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sut = new TemplateResolver();
    }

    // ─────────────────────────────────────────────────────────────────────
    // resolveTemplate()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * resolveTemplate() should replace {{key}} placeholders with context values.
     */
    #[Test]
    #[DataProvider("templateProvider")]
    public function resolveTemplate_replacesPlaceholders(
        string $template,
        array $context,
        string $expected,
    ): void {
        $this->assertSame(
            $expected,
            $this->sut->resolveTemplate($template, $context),
        );
    }

    /**
     * @return array<string, array{0: string, 1: array, 2: string}>
     */
    public static function templateProvider(): array
    {
        return [
            "single placeholder" => [
                "Hello, {{name}}!",
                ["name" => "World"],
                "Hello, World!",
            ],
            "multiple placeholders" => [
                "{{first}} and {{second}}",
                ["first" => "A", "second" => "B"],
                "A and B",
            ],
            "missing key becomes empty string" => [
                "Value: {{unknown}}",
                ["known" => "x"],
                "Value: ",
            ],
            "no placeholders returns unchanged" => [
                "Plain text with no braces",
                ["key" => "value"],
                "Plain text with no braces",
            ],
            "placeholder with integer value" => [
                "Count: {{count}}",
                ["count" => 42],
                "Count: 42",
            ],
            "placeholder with float value" => [
                "Score: {{score}}",
                ["score" => 3.14],
                "Score: 3.14",
            ],
            "placeholder with boolean true" => [
                "Active: {{active}}",
                ["active" => true],
                "Active: 1",
            ],
            "placeholder with boolean false" => [
                "Active: {{active}}",
                ["active" => false],
                "Active: ",
            ],
            "placeholder with null value" => [
                "Value: {{null_val}}",
                ["null_val" => null],
                "Value: ",
            ],
            "repeated placeholder" => [
                "{{x}}-{{x}}-{{x}}",
                ["x" => "a"],
                "a-a-a",
            ],
            "placeholder adjacent to text" => [
                "pre{{key}}post",
                ["key" => "mid"],
                "premidpost",
            ],
            "array value is JSON-encoded" => [
                "Data: {{items}}",
                ["items" => ["a", "b"]],
                'Data: ["a","b"]',
            ],
            "object value is JSON-encoded" => [
                "Config: {{cfg}}",
                ["cfg" => new \stdClass()],
                "Config: {}",
            ],
            "nested array value is JSON-encoded" => [
                "Obj: {{data}}",
                ["data" => ["x" => 1, "y" => [2, 3]]],
                'Obj: {"x":1,"y":[2,3]}',
            ],
            "empty template returns empty string" => [
                "",
                ["key" => "value"],
                "",
            ],
            "empty context with placeholder" => [
                "Hello {{name}}",
                [],
                "Hello ",
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // resolveVariable()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * When the entire string is a single {{key}}, resolveVariable() should
     * return the raw context value, preserving its type (array, int, etc.).
     */
    #[Test]
    public function resolveVariable_returnsRawArrayForSingleKey(): void
    {
        $context = ["sections" => ["A", "B", "C"]];

        $result = $this->sut->resolveVariable("{{sections}}", $context);

        $this->assertSame(["A", "B", "C"], $result);
    }

    /**
     * When the entire string is a single {{key}} with a numeric value,
     * the integer type should be preserved.
     */
    #[Test]
    public function resolveVariable_returnsRawIntegerForSingleKey(): void
    {
        $context = ["count" => 42];

        $result = $this->sut->resolveVariable("{{count}}", $context);

        $this->assertSame(42, $result);
    }

    /**
     * When the entire string is a single {{key}} with a boolean value,
     * the boolean type should be preserved.
     */
    #[Test]
    public function resolveVariable_returnsRawBooleanForSingleKey(): void
    {
        $context = ["flag" => true];

        $result = $this->sut->resolveVariable("{{flag}}", $context);

        $this->assertTrue($result);
    }

    /**
     * When the single {{key}} does not exist in context, resolveVariable()
     * should return null.
     */
    #[Test]
    public function resolveVariable_returnsNullForMissingSingleKey(): void
    {
        $context = ["known" => "value"];

        $result = $this->sut->resolveVariable("{{missing}}", $context);

        $this->assertNull($result);
    }

    /**
     * When the string contains mixed content (text + {{key}}), resolveVariable()
     * should return the interpolated string (not the raw value).
     */
    #[Test]
    public function resolveVariable_returnsStringForMixedTemplate(): void
    {
        $context = ["name" => "World"];

        $result = $this->sut->resolveVariable("Hello, {{name}}!", $context);

        $this->assertSame("Hello, World!", $result);
    }

    /**
     * When the string contains no placeholders, resolveVariable() should
     * return the string unchanged.
     */
    #[Test]
    public function resolveVariable_returnsUnchangedStringWhenNoPlaceholders(): void
    {
        $context = ["key" => "value"];

        $result = $this->sut->resolveVariable("plain text", $context);

        $this->assertSame("plain text", $result);
    }

    /**
     * A single {{key}} with surrounding whitespace should NOT match the
     * single-key pattern (regex requires exact match) and should fall through
     * to template resolution.
     */
    #[Test]
    public function resolveVariable_singleKeyWithWhitespaceUsesTemplateMode(): void
    {
        $context = ["name" => "World"];

        $result = $this->sut->resolveVariable(" {{name}} ", $context);

        $this->assertSame(" World ", $result);
    }

    // ─────────────────────────────────────────────────────────────────────
    // resolveVariables()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * String values in the variables array should have their {{key}}
     * placeholders resolved from context.
     */
    #[Test]
    public function resolveVariables_resolvesStringValues(): void
    {
        $context = ["title" => "My Article", "author" => "Jane"];

        $result = $this->sut->resolveVariables(
            ["heading" => "{{title}}", "by" => "By {{author}}"],
            $context,
        );

        $this->assertSame("My Article", $result["heading"]);
        $this->assertSame("By Jane", $result["by"]);
    }

    /**
     * Non-string values (arrays, integers, booleans) should pass through
     * unchanged.
     */
    #[Test]
    public function resolveVariables_passesNonStringValuesUnchanged(): void
    {
        $context = ["key" => "value"];

        $result = $this->sut->resolveVariables(
            [
                "items" => ["a", "b"],
                "count" => 42,
                "active" => true,
                "config" => new \stdClass(),
                "nothing" => null,
            ],
            $context,
        );

        $this->assertSame(["a", "b"], $result["items"]);
        $this->assertSame(42, $result["count"]);
        $this->assertTrue($result["active"]);
        $this->assertInstanceOf(\stdClass::class, $result["config"]);
        $this->assertNull($result["nothing"]);
    }

    /**
     * An empty variables array should return an empty array.
     */
    #[Test]
    public function resolveVariables_returnsEmptyArrayForEmptyInput(): void
    {
        $result = $this->sut->resolveVariables([], ["key" => "value"]);

        $this->assertSame([], $result);
    }

    /**
     * A mix of resolvable strings and non-strings should handle both correctly.
     */
    #[Test]
    public function resolveVariables_handlesMixedTypes(): void
    {
        $context = ["name" => "Alice", "age" => 30];

        $result = $this->sut->resolveVariables(
            [
                "greeting" => "Hi {{name}}",
                "age" => 30,
                "tags" => ["php", "laravel"],
                "missing" => "{{unknown}}",
            ],
            $context,
        );

        $this->assertSame("Hi Alice", $result["greeting"]);
        $this->assertSame(30, $result["age"]);
        $this->assertSame(["php", "laravel"], $result["tags"]);
        $this->assertSame("", $result["missing"]);
    }
}
