<?php

/**
 * @package     purencool/agent
 *
 * @copyright   Copyright (C) Purencool. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

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
