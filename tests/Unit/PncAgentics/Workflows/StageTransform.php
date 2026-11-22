<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Workflows;

use App\PncAgentics\Workflows\StageTransform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see StageTransform}.
 *
 * Covers:
 *  - strip_code_fence: removes ```json and ``` wrappers, handles whitespace,
 *    passes through strings without fences.
 *  - json_decode: decodes valid JSON strings, handles surrounding whitespace,
 *    returns null for invalid JSON.
 *  - Unknown transform names: returns the input value unchanged.
 *
 * @covers \App\PncAgentics\Workflows\StageTransform
 */
class StageTransformTest extends TestCase
{
    /**
     * The system under test.
     */
    private StageTransform $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sut = new StageTransform();
    }

    // ─────────────────────────────────────────────────────────────────────
    // strip_code_fence
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Should remove ```json and ``` markers from the value.
     */
    #[Test]
    public function apply_stripCodeFence_removesJsonFenceMarkers(): void
    {
        $result = $this->sut->apply(
            "strip_code_fence",
            "```json\n{\"key\": \"value\"}\n```",
        );

        $this->assertSame('{"key": "value"}', $result);
    }

    /**
     * Should handle fences without a language identifier (just ```).
     */
    #[Test]
    public function apply_stripCodeFence_handlesBareBacktickFences(): void
    {
        $result = $this->sut->apply(
            "strip_code_fence",
            "```\nsome content\n```",
        );

        $this->assertSame("some content", $result);
    }

    /**
     * Should trim leading/trailing whitespace after fence removal.
     */
    #[Test]
    public function apply_stripCodeFence_trimsWhitespaceAfterRemoval(): void
    {
        $result = $this->sut->apply(
            "strip_code_fence",
            "```json\n  {\"a\": 1}  \n```",
        );

        $this->assertSame('{"a": 1}', $result);
    }

    /**
     * Should pass through the value unchanged when no fences are present.
     */
    #[Test]
    public function apply_stripCodeFence_passesThroughWhenNoFences(): void
    {
        $result = $this->sut->apply(
            "strip_code_fence",
            "plain text without fences",
        );

        $this->assertSame("plain text without fences", $result);
    }

    /**
     * Should handle multiple fence blocks (all are removed).
     */
    #[Test]
    public function apply_stripCodeFence_removesMultipleFenceBlocks(): void
    {
        $result = $this->sut->apply(
            "strip_code_fence",
            "```json\n[1,2,3]\n```\nand\n```json\n[4,5,6]\n```",
        );

        $this->assertSame("[1,2,3]\nand\n[4,5,6]", $result);
    }

    /**
     * Should return an empty string for an empty input.
     */
    #[Test]
    public function apply_stripCodeFence_returnsEmptyStringForEmptyInput(): void
    {
        $result = $this->sut->apply("strip_code_fence", "");

        $this->assertSame("", $result);
    }

    /**
     * Should handle fences with no content between them.
     */
    #[Test]
    public function apply_stripCodeFence_handlesEmptyFencedBlock(): void
    {
        $result = $this->sut->apply("strip_code_fence", "```json\n```");

        $this->assertSame("", $result);
    }

    // ─────────────────────────────────────────────────────────────────────
    // json_decode
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Should decode a valid JSON object string into an associative array.
     */
    #[Test]
    public function apply_jsonDecode_decodesObjectToJsonArray(): void
    {
        $result = $this->sut->apply(
            "json_decode",
            '{"name": "test", "count": 3}',
        );

        $this->assertSame(["name" => "test", "count" => 3], $result);
    }

    /**
     * Should decode a valid JSON array string into a PHP array.
     */
    #[Test]
    public function apply_jsonDecode_decodesArrayToJsonArray(): void
    {
        $result = $this->sut->apply("json_decode", '["a", "b", "c"]');

        $this->assertSame(["a", "b", "c"], $result);
    }

    /**
     * Should trim surrounding whitespace before decoding.
     */
    #[Test]
    public function apply_jsonDecode_trimsWhitespaceBeforeDecoding(): void
    {
        $result = $this->sut->apply(
            "json_decode",
            "  \n{\"key\": \"value\"}\n  ",
        );

        $this->assertSame(["key" => "value"], $result);
    }

    /**
     * Should return null for invalid JSON (json_decode returns null on error).
     */
    #[Test]
    public function apply_jsonDecode_returnsNullForInvalidJson(): void
    {
        $result = $this->sut->apply("json_decode", "this is not json");

        $this->assertNull($result);
    }

    /**
     * Should return null for an empty string.
     */
    #[Test]
    public function apply_jsonDecode_returnsNullForEmptyString(): void
    {
        $result = $this->sut->apply("json_decode", "");

        $this->assertNull($result);
    }

    /**
     * Should decode JSON with nested structures correctly.
     */
    #[Test]
    public function apply_jsonDecode_handlesNestedJson(): void
    {
        $json = '{"outer": {"inner": [1, 2, 3]}, "flag": true}';
        $result = $this->sut->apply("json_decode", $json);

        $this->assertSame(
            ["outer" => ["inner" => [1, 2, 3]], "flag" => true],
            $result,
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Unknown transform (passthrough)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * An unknown transform name should return the input value unchanged.
     */
    #[Test]
    #[DataProvider("unknownTransformProvider")]
    public function apply_unknownTransform_returnsValueUnchanged(
        string $transform,
        string $value,
    ): void {
        $result = $this->sut->apply($transform, $value);

        $this->assertSame($value, $result);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unknownTransformProvider(): array
    {
        return [
            "random name" => ["my_custom_transform", "original value"],
            "empty string" => ["", "original value"],
            "uppercase variant" => ["STRIP_CODE_FENCE", "```json\n{}\n```"],
            "partial match" => ["strip_code", "```json\n{}\n```"],
        ];
    }

    /**
     * An empty transform name should return the value unchanged.
     */
    #[Test]
    public function apply_emptyTransformName_returnsValueUnchanged(): void
    {
        $result = $this->sut->apply("", "some content");

        $this->assertSame("some content", $result);
    }
}
