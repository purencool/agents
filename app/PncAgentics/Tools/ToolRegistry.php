<?php

namespace App\PncAgentics\Tools;

class ToolRegistry
{
    /** @var ToolInterface[] */
    private array $tools = [];

    public function __construct()
    {
        $dir = __DIR__;
        foreach (glob("$dir/*Tool.php") as $file) {
            $class = 'App\\PncAgentics\\Tools\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, ToolInterface::class)) {
                $instance = new $class();
                $this->tools[$instance->name()] = $instance;
            }
        }
    }

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function all(): array
    {
        return $this->tools;
    }

    public function manifest(): array
    {
        return array_map(fn(ToolInterface $t) => [
            'name' => $t->name(),
            'description' => $t->description(),
        ], $this->tools);
    }
}
