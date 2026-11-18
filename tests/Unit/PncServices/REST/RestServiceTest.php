<?php

declare(strict_types=1);

namespace Tests\Unit\PncServices\REST;

use App\PncServices\REST\RestService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for RestService.
 *
 * Verifies that all HTTP verb methods (get, post, put, delete)
 * correctly delegate to the underlying request() method with the
 * proper method, endpoint, and data. Also verifies error handling
 * (HTTP failures, connection exceptions, empty responses) and that
 * the base URL + bearer token are applied to every request.
 *
 * Uses Laravel's Http::fake() for HTTP mocking and Mockery for the
 * Log facade where log assertions are needed.
 */
class RestServiceTest extends TestCase
{
    /**
     * The service under test.
     */
    private RestService $service;

    /**
     * Set up the test environment.
     *
     * Configures the REST base URL and API key, then instantiates
     * the service (which reads those config values in its constructor).
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            "pncservices.rest.base_url" => "https://api.example.com/v1",
            "pncservices.rest.api_key" => "test-api-key",
        ]);

        $this->service = new RestService();
    }

    // ─── get() ─────────────────────────────────────────────────────────────

    /**
     * @testdoc Verify that get() sends a GET request to the correct URL
     *          with query parameters, includes the bearer token, and
     *          returns the decoded JSON response.
     */
    #[Test]
    public function get_returns_decoded_json_on_success(): void
    {
        $expected = ["id" => 1, "name" => "Alice"];

        Http::fake([
            "api.example.com/v1/users?active=1" => Http::response(
                $expected,
                200,
            ),
        ]);

        $result = $this->service->get("/users", ["active" => 1]);

        $this->assertSame($expected, $result);

        Http::assertSent(function ($request): bool {
            return $request->method() === "GET" &&
                $request->url() ===
                    "https://api.example.com/v1/users?active=1" &&
                $request->hasHeader("Authorization", "Bearer test-api-key");
        });
    }

    /**
     * @testdoc Verify that get() returns an empty array when the
     *          response body is null (e.g. 204 No Content).
     */
    #[Test]
    public function get_returns_empty_array_on_null_response(): void
    {
        Http::fake([
            "api.example.com/v1/empty" => Http::response(null, 204),
        ]);

        $result = $this->service->get("/empty");

        $this->assertSame([], $result);
    }

    // ─── post() ────────────────────────────────────────────────────────────

    /**
     * @testdoc Verify that post() sends a POST request with a JSON body,
     *          includes the bearer token, and returns the decoded JSON.
     */
    #[Test]
    public function post_returns_decoded_json_on_success(): void
    {
        $expected = ["id" => 2, "name" => "Bob", "created" => true];

        Http::fake([
            "api.example.com/v1/users" => Http::response($expected, 201),
        ]);

        $result = $this->service->post("/users", ["name" => "Bob"]);

        $this->assertSame($expected, $result);

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true);

            return $request->method() === "POST" &&
                $request->url() === "https://api.example.com/v1/users" &&
                $body["name"] === "Bob" &&
                $request->hasHeader("Authorization", "Bearer test-api-key");
        });
    }

    // ─── put() ─────────────────────────────────────────────────────────────

    /**
     * @testdoc Verify that put() sends a PUT request with a JSON body
     *          and returns the decoded JSON response.
     */
    #[Test]
    public function put_returns_decoded_json_on_success(): void
    {
        $expected = ["id" => 1, "name" => "Updated"];

        Http::fake([
            "api.example.com/v1/users/1" => Http::response($expected, 200),
        ]);

        $result = $this->service->put("/users/1", ["name" => "Updated"]);

        $this->assertSame($expected, $result);

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true);

            return $request->method() === "PUT" &&
                $request->url() === "https://api.example.com/v1/users/1" &&
                $body["name"] === "Updated";
        });
    }

    // ─── delete() ──────────────────────────────────────────────────────────

    /**
     * @testdoc Verify that delete() sends a DELETE request and returns
     *          the decoded JSON response.
     */
    #[Test]
    public function delete_returns_decoded_json_on_success(): void
    {
        $expected = ["deleted" => true];

        Http::fake([
            "api.example.com/v1/users/1" => Http::response($expected, 200),
        ]);

        $result = $this->service->delete("/users/1");

        $this->assertSame($expected, $result);

        Http::assertSent(function ($request): bool {
            return $request->method() === "DELETE" &&
                $request->url() === "https://api.example.com/v1/users/1";
        });
    }

    // ─── Error handling ────────────────────────────────────────────────────

    /**
     * @testdoc Verify that a 4xx/5xx HTTP response triggers the error path:
     *          returns ['error' => message] containing the status code.
     */
    #[Test]
    public function request_returns_error_array_on_http_failure(): void
    {
        Http::fake([
            "api.example.com/v1/users" => Http::response(
                ["message" => "Not Found"],
                404,
            ),
        ]);

        $result = $this->service->get("/users");

        $this->assertArrayHasKey("error", $result);
        $this->assertStringContainsString("404", $result["error"]);
    }

    /**
     * @testdoc Verify that a connection failure (ConnectionException) is
     *          caught, logged via Log::error, and returned as an error array.
     */
    #[Test]
    public function request_returns_error_array_on_connection_failure(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException("Connection refused");
        });

        Log::shouldReceive("error")
            ->once()
            ->withArgs(function (string $msg, array $ctx): bool {
                return str_contains($msg, "GET /users") &&
                    str_contains($ctx["error"], "Connection refused");
            });

        $result = $this->service->get("/users");

        $this->assertArrayHasKey("error", $result);
        $this->assertStringContainsString(
            "Connection refused",
            $result["error"],
        );
    }

    // ─── Authentication ────────────────────────────────────────────────────

    /**
     * @testdoc Verify that the bearer token is sent on every request
     *          regardless of HTTP method.
     */
    #[Test]
    public function all_methods_include_bearer_token(): void
    {
        Http::fake([
            "api.example.com/v1/*" => Http::response(["ok" => true], 200),
        ]);

        $this->service->get("/a");
        $this->service->post("/b");
        $this->service->put("/c");
        $this->service->delete("/d");

        Http::assertSentCount(4);

        Http::assertSent(function ($request): bool {
            return $request->hasHeader("Authorization", "Bearer test-api-key");
        });
    }
}
