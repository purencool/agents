<?php

declare(strict_types=1);

namespace Tests\Unit\PncAgentics\Factory;

use App\PncAgentics\Factory\WorkflowFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for {@see WorkflowFactory}.
 *
 * @covers \App\PncAgentics\Factory\WorkflowFactory
 */
class WorkflowFactoryTest extends TestCase
{
    /**
     * The system under test.
     */
    private WorkflowFactory $factory;

    /**
     * Instantiate the factory.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Stub the workflow class since it doesn't exist yet.
        $this->app->bind(
            \App\PncAgentics\Workflows\UniversalDocumentationWorkflow::class,
            fn() => new \stdClass(),
        );

        $this->factory = new WorkflowFactory();
    }

    // ─────────────────────────────────────────────────────────────────────
    // make() – valid types
    // ─────────────────────────────────────────────────────────────────────

    /**
     * make() should return the correct base_key for each supported type.
     */
    #[Test]
    #[DataProvider("supportedTypesProvider")]
    public function make_returnsCorrectBaseKeyForSupportedTypes(
        string $type,
        string $expectedBaseKey,
    ): void {
        $result = $this->factory->make($type);

        $this->assertSame($expectedBaseKey, $result["base_key"]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function supportedTypesProvider(): array
    {
        return [
            "developer_docs" => [
                "developer_docs",
                "sales_marketing.marketing.external_documentation.developer_docs",
            ],
            "product_guides" => [
                "product_guides",
                "sales_marketing.marketing.external_documentation.product_guides",
            ],
            "social_media" => [
                "social_media",
                "sales_marketing.marketing.external_documentation.social_media",
            ],
            "sales_copy" => [
                "sales_copy",
                "sales_marketing.marketing.external_documentation.sales_copy",
            ],
            "blog_posts" => [
                "blog_posts",
                "sales_marketing.marketing.external_documentation.blog_posts",
            ],
        ];
    }

    /**
     * make() should resolve a UniversalDocumentationWorkflow instance
     * from the container.
     */
    #[Test]
    public function make_resolvesUniversalDocumentationWorkflow(): void
    {
        $result = $this->factory->make("blog_posts");

        $this->assertArrayHasKey("workflow", $result);
        $this->assertIsObject($result["workflow"]);
    }

    /**
     * make() should return exactly two keys: "workflow" and "base_key".
     */
    #[Test]
    public function make_returnsExactlyTwoKeys(): void
    {
        $result = $this->factory->make("developer_docs");

        $this->assertArrayHasKey("workflow", $result);
        $this->assertArrayHasKey("base_key", $result);
        $this->assertCount(2, $result);
    }

    /**
     * make() should produce the same base_key regardless of the
     * $isUpdate flag (the flag is currently unused).
     */
    #[Test]
    public function make_baseKeyUnaffectedByIsUpdateFlag(): void
    {
        $create = $this->factory->make("sales_copy", false);
        $update = $this->factory->make("sales_copy", true);

        $this->assertSame($create["base_key"], $update["base_key"]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // make() – invalid types
    // ─────────────────────────────────────────────────────────────────────

    /**
     * make() should throw InvalidArgumentException for an unsupported type.
     */
    #[Test]
    public function make_throwsForUnsupportedType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Unsupported enterprise workflow type: [unknown_type]",
        );

        $this->factory->make("unknown_type");
    }

    /**
     * make() should throw for an empty string type.
     */
    #[Test]
    public function make_throwsForEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Unsupported enterprise workflow type: []",
        );

        $this->factory->make("");
    }

    /**
     * make() should throw for a case-variant of a valid type (case-sensitive).
     */
    #[Test]
    public function make_throwsForCaseMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Unsupported enterprise workflow type: [Developer_Docs]",
        );

        $this->factory->make("Developer_Docs");
    }
}
