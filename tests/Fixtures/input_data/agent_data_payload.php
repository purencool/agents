<?php

/**
 * Shared test fixture: standard agent data creation payload.
 *
 * @return array{title: string, description: string, domains: array}
 */
return [
    "title" =>
        "Can AI models be deployed on-premises and still function with the same performance, accuracy, and efficiency as cloud-hosted solutions?",
    "description" =>
        "[CONTEXT]:Can AI models be deployed on-premises and still function with the same performance, accuracy, and efficiency as cloud-hosted solutions?",
    "domains" => [
        "blog.com" => [
            "blog" => [
                "content_tracks" => [
                    "config" => [
                        "type" => "social",
                        "workflow" => "default",
                        "template" => "social",
                        "track" => "social_media",
                        "lang" => "en",
                    ],
                ],
            ],
        ],
        "meeting.com" => [
            "meeting" => [
                "content_tracks" => [
                    "config" => [
                        "type" => "social",
                        "workflow" => "default",
                        "template" => "social",
                        "track" => "social_media",
                        "lang" => "en",
                    ],
                ],
            ],
        ],
    ],
];
