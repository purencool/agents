<?php

return [
    "transport" => env("MCP_TRANSPORT", "local"),
    "local" => [
        "server" => env("MCP_SERVER", ""),
        "timeout" => env("MCP_LOCAL_TIMEOUT", 60),
    ],
    "rest" => [
        "base_url" => env("MCP_REST_URL", ""),
        "api_key" => env("MCP_REST_KEY", ""),
        "timeout" => env("MCP_REST_TIMEOUT", 60),
    ],
];
