<?php

return [
    "ai" => [
        "api_key" => env("AI_API_KEY"),
        "base_url" => env("AI_BASE_URL", "https://api.openai.com/v1"),
        "model" => env("AI_MODEL", "gpt-4o"),
        "embed_model" => env("AI_EMBED_MODEL", "text-embedding-3-small"),
        "timeout" => env("AI_TIMEOUT", 30),
        "context_window" => env("AI_CONTEXT_WINDOW", 50000),
        "system_prompt" => env(
            "AI_SYSTEM_PROMPT",
            "You are a helpful assistant.",
        ),
    ],
    "sms" => [
        "from_number" => env("SMS_FROM_NUMBER"),
        "sid" => env("SMS_SID"),
        "token" => env("SMS_TOKEN"),
    ],
    "rest" => [
        "base_url" => env("REST_BASE_URL"),
        "api_key" => env("REST_API_KEY"),
    ],
    "email" => [
        "from" => env("MAIL_FROM_ADDRESS"),
        "from_name" => env("MAIL_FROM_NAME", "PNC"),
        "alert_to" => env("PNC_ALERT_EMAIL"),
    ],
    "logging" => [
        "channel" => env("PNC_LOG_CHANNEL", "stack"),
    ],
    "templates" => [
        "templates_dir" => base_path("app/PncTemplates/Templates"),
        "override_dir" => env("OVERLAY_TEMPLATES"),
    ],
    "workflows" => [
        "definitions_dir" => base_path("app/PncAgentics/Workflows/Definitions"),
        "override_dir" => env("OVERLAY_WORKFLOWS"),
    ],
];
