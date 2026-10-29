<?php

namespace App\PncTemplates\Services;

use App\PncTemplates\Contracts\TemplateTransformerInterface;
use App\PncTemplates\Data\DynamicServicePayload;
use App\PncServices\AI\PncAgent;
use NeuronAI\Chat\Messages\UserMessage;
use InvalidArgumentException;

class TemplateTransformerService implements TemplateTransformerInterface
{
    public function transform(string $template, string $rawContent): array
    {
        if (!file_exists($template)) {
            throw new InvalidArgumentException(
                "Missing layout configuration profile at: {$template}",
            );
        }

        $config = json_decode(file_get_contents($template), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                "Malformed JSON template configuration: " .
                    json_last_error_msg(),
            );
        }

        $compiledInstructions =
            $config["system_prompt"] .
            "\n\nCRITICAL STRIP & EXTRACT REQUIREMENTS:\n";
        foreach ($config["elements_map"] as $key => $meta) {
            $compiledInstructions .= " - Extract key '{$key}': Looking for content matching '{$meta["selector"]}'. Directive: {$meta["description"]}\n";
        }
        $compiledInstructions .=
            "\nIsolate the sections precisely, strip off the matching title elements headers themselves, and return only the raw text strings.";

        $agent = new PncAgent([
            "temperature" => $config["options"]["temperature"] ?? 0.1,
            "max_tokens" => $config["options"]["max_tokens"] ?? 2500,
        ]);
        $agent->setInstructions($compiledInstructions);

        /** @var DynamicServicePayload $structuredOutput */
        $structuredOutput = $agent->structured(
            new UserMessage($rawContent),
            DynamicServicePayload::class,
        );

        $extractedFields = $structuredOutput->elements;

        $htmlElements = [];
        foreach ($extractedFields as $key => $content) {
            if ($key === "title") {
                $htmlElements["title"] =
                    "<h1>" . htmlspecialchars($content) . "</h1>";
                continue;
            }

            $htmlElements[$key] = sprintf(
                '<div id="%s" class="pnc-template-node pnc-type-%s"><h2>%s</h2><p>%s</p></div>',
                htmlspecialchars($key),
                htmlspecialchars($config["type"]),
                htmlspecialchars(ucfirst($key)),
                nl2br(htmlspecialchars($content)),
            );
        }

        return [
            "type" => $config["type"],
            "raw_json_fields" => $extractedFields,
            "html_elements" => $htmlElements,
            "transformed_at" => now()->toIso8601String(),
        ];
    }
}
