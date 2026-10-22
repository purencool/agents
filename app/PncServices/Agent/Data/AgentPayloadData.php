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
    public static function fromJsonString(string $jsonString): self
    {
        $payload = json_decode($jsonString, true);

        // Validate JSON Format Syntax
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            throw new InvalidArgumentException(
                "Invalid input: The provided string is not valid JSON. Error: " .
                    json_last_error_msg(),
            );
        }

        // Enforce Strict Field Validation Matching Structural Matrix
        $validator = Validator::make($payload, [
            "domain" => "required|string",
            "type" => "required|string",
            "title" => "required|string",
            "description" => "required|string",
            "content_tracks" => "required|array|min:1",
            "content_tracks.*" => "required|string",
        ]);

        if ($validator->fails()) {
            $errorSummary = implode(", ", $validator->errors()->all());
            throw new InvalidArgumentException(
                "Validation Failed: {$errorSummary}",
            );
        }

        // Return Strongly-Typed Instance Map
        return new self(
            domain: (string) $payload["domain"],
            type: (string) $payload["type"],
            title: (string) $payload["title"],
            description: (string) $payload["description"],
            contentTracks: (array) $payload["content_tracks"],
        );
    }

    /**
     * Converts the strongly-typed properties back into an
     * array for legacy action support.
     */
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
