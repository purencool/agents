<?php

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Data;

use App\PncAgentics\Data\SkillOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see SkillOptions}.
 *
 * @covers \App\PncAgentics\Data\SkillOptions
 */
class SkillOptionsTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // Constructor defaults
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Constructing with no arguments should yield the documented defaults.
     */
    #[Test]
    public function constructor_defaultsToDocumentedValues(): void
    {
        $options = new SkillOptions();

        $this->assertSame(0.7, $options->temperature);
        $this->assertSame(4000, $options->maxTokens);
        $this->assertSame([], $options->customSystemRules);
    }

    /**
     * Constructor should accept explicit values for all properties.
     */
    #[Test]
    public function constructor_acceptsExplicitValues(): void
    {
        $options = new SkillOptions(
            temperature: 0.3,
            maxTokens: 1024,
            customSystemRules: ["rule_one", "rule_two"],
        );

        $this->assertSame(0.3, $options->temperature);
        $this->assertSame(1024, $options->maxTokens);
        $this->assertSame(
            ["rule_one", "rule_two"],
            $options->customSystemRules,
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // fromArray()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * fromArray() with an empty array should return default values.
     */
    #[Test]
    public function fromArray_emptyArrayReturnsDefaults(): void
    {
        $options = SkillOptions::fromArray([]);

        $this->assertSame(0.7, $options->temperature);
        $this->assertSame(4000, $options->maxTokens);
        $this->assertSame([], $options->customSystemRules);
    }

    /**
     * fromArray() should map snake_case keys to the correct properties.
     */
    #[Test]
    public function fromArray_mapsSnakeCaseKeysCorrectly(): void
    {
        $options = SkillOptions::fromArray([
            "temperature" => 0.5,
            "max_tokens" => 2048,
            "system_rules" => ["be concise"],
        ]);

        $this->assertSame(0.5, $options->temperature);
        $this->assertSame(2048, $options->maxTokens);
        $this->assertSame(["be concise"], $options->customSystemRules);
    }

    /**
     * fromArray() should only override keys that are present,
     * leaving others at their defaults.
     */
    #[Test]
    public function fromArray_partialArrayKeepsMissingDefaults(): void
    {
        $options = SkillOptions::fromArray([
            "temperature" => 0.9,
        ]);

        $this->assertSame(0.9, $options->temperature);
        $this->assertSame(4000, $options->maxTokens);
        $this->assertSame([], $options->customSystemRules);
    }

    /**
     * fromArray() should handle zero values without falling back to defaults.
     */
    #[Test]
    public function fromArray_zeroValuesAreNotTreatedAsMissing(): void
    {
        $options = SkillOptions::fromArray([
            "temperature" => 0.0,
            "max_tokens" => 0,
            "system_rules" => [],
        ]);

        $this->assertSame(0.0, $options->temperature);
        $this->assertSame(0, $options->maxTokens);
        $this->assertSame([], $options->customSystemRules);
    }

    /**
     * fromArray() should ignore unknown keys without error.
     */
    #[Test]
    public function fromArray_ignoresUnknownKeys(): void
    {
        $options = SkillOptions::fromArray([
            "temperature" => 0.4,
            "unknown_key" => "ignored",
            "another" => 123,
        ]);

        $this->assertSame(0.4, $options->temperature);
        $this->assertSame(4000, $options->maxTokens);
    }

    /**
     * fromArray() should cast temperature to float.
     */
    #[Test]
    public function fromArray_castsTemperatureToFloat(): void
    {
        $options = SkillOptions::fromArray(["temperature" => "0.5"]);

        $this->assertSame(0.5, $options->temperature);
        $this->assertIsFloat($options->temperature);
    }

    /**
     * fromArray() should cast max_tokens to int.
     */
    #[Test]
    public function fromArray_castsMaxTokensToInt(): void
    {
        $options = SkillOptions::fromArray(["max_tokens" => "2048"]);

        $this->assertSame(2048, $options->maxTokens);
        $this->assertIsInt($options->maxTokens);
    }
}
