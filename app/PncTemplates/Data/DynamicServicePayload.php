<?php

declare(strict_types=1);

namespace App\PncTemplates\Data;

use NeuronAI\StructuredOutput\SchemaProperty;

class DynamicServicePayload
{
    #[SchemaProperty(
        description: "A key-value dictionary mapping requested element IDs directly to their isolated text content chunks",
        required: true,
    )]
    public array $elements = [];
}
