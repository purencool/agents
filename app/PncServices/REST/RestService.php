<?php

namespace App\PncServices\REST;

use App\PncServices\Contracts\RestServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * REST API client service.
 *
 * Provides a simple wrapper around Laravel's Http facade for making
 * authenticated requests to a configured REST API. All methods return
 * the decoded JSON response as an array, or an error array on failure.
 *
 * Configuration keys (config/pncservices.php):
 *  - pncservices.rest.base_url (string) Base URL of the target API
 *  - pncservices.rest.api_key  (string) Bearer token for authentication
 */
class RestService implements RestServiceInterface
{
    /**
     * Base URL for all API requests.
     */
    protected string $baseUrl;

    /**
     * Bearer API key for authentication.
     */
    protected string $apiKey;

    /**
     * Read base URL and API key from config.
     */
    public function __construct()
    {
        $this->baseUrl = (string) config("pncservices.rest.base_url", "");
        $this->apiKey = (string) config("pncservices.rest.api_key", "");
    }

    /**
     * Perform a GET request.
     *
     * @param string $endpoint API endpoint path (e.g. "/users").
     * @param array  $params   Query string parameters.
     *
     * @return array Decoded JSON response, or ['error' => message] on failure.
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request("GET", $endpoint, $params);
    }

    /**
     * Perform a POST request.
     *
     * @param string $endpoint API endpoint path.
     * @param array  $data     Request body data.
     *
     * @return array Decoded JSON response, or ['error' => message] on failure.
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->request("POST", $endpoint, $data);
    }

    /**
     * Perform a PUT request.
     *
     * @param string $endpoint API endpoint path.
     * @param array  $data     Request body data.
     *
     * @return array Decoded JSON response, or ['error' => message] on failure.
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->request("PUT", $endpoint, $data);
    }

    /**
     * Perform a DELETE request.
     *
     * @param string $endpoint API endpoint path.
     *
     * @return array Decoded JSON response, or ['error' => message] on failure.
     */
    public function delete(string $endpoint): array
    {
        return $this->request("DELETE", $endpoint);
    }

    /**
     * Execute an HTTP request with authentication and error handling.
     *
     * Builds the full URL from base URL + endpoint, attaches the bearer
     * token, sends the request, and returns the decoded JSON. On any
     * exception (HTTP error, connection failure), logs the error and
     * returns an error array instead of propagating.
     *
     * @param string $method   HTTP method (GET, POST, PUT, DELETE).
     * @param string $endpoint Relative endpoint path.
     * @param array  $data     Query params (GET) or request body (POST/PUT).
     *
     * @return array Decoded JSON response, ['error' => message] on failure,
     *               or [] if the response body is empty.
     */
    protected function request(
        string $method,
        string $endpoint,
        array $data = [],
    ): array {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->apiKey)
                ->{$method}($endpoint, $data)
                ->throw()
                ->json();

            return $response ?? [];
        } catch (\Exception $e) {
            Log::error("RestService: {$method} {$endpoint} failed", [
                "error" => $e->getMessage(),
            ]);

            return ["error" => $e->getMessage()];
        }
    }
}
