<?php

namespace App\PncAgentics\Tools;

interface ToolInterface
{
    public function name(): string;
    public function description(): string;
    public function execute(string $input): array;
}
