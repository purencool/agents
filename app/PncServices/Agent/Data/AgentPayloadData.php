<?php

namespace App\PncServices\Agent\Data;

use InvalidArgumentException;
use Illuminate\Support\Facades\Validator;

class AgentPayloadData
{
    public function __construct(
        public readonly string $domain,
        public readonly string $type,
        public readonly string $title,
        public readonly string $description,
        public readonly array $contentTracks,
    ) {}

    /**
     * Factory engine method to validate and instantiate the
     * DTO from a raw JSON payload string.
     *
     * @param string $jsonString
     * @return self
     * @throws InvalidArgumentException
     */
    public static function fromJsonString(string $json): self
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                "Invalid incoming payload JSON mapping syntax: " .
                    json_last_error_msg(),
            );
        }

        // Enforce safety baselines
        foreach (
            ["domain", "type", "title", "description", "content_tracks"]
            as $field
        ) {
            if (empty($data[$field])) {
                throw new InvalidArgumentException(
                    "Missing critical structural request property: [{$field}]",
                );
            }
        }

        return new self(
            domain: (string) $data["domain"],
            type: (string) $data["type"],
            title: (string) $data["title"],
            description: (string) $data["description"],
            contentTracks: (array) $data["content_tracks"],
        );
    }

    public function toArray(): array
    {
        return [
            "domain" => $this->domain,
            "type" => $this->type,
            "title" => $this->title,
            "description" => $this->description,
            "content_tracks" => $this->contentTracks,
        ];
    }
}
