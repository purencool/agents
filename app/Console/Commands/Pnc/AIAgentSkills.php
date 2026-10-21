<?php

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AIAgentSkills extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = "pnc:agent:skills {--pretty : Format the JSON output to be human-readable}";

    /**
     * The console command description.
     */
    protected $description = "Scan the disk filesystem and list all dynamically available enterprise AI Agent skills in JSON format.";

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $basePath = app_path("PncAgentics" . DIRECTORY_SEPARATOR . "Skills");

        if (!File::isDirectory($basePath)) {
            $this->error(
                json_encode(
                    [
                        "error" =>
                            "The AI Agents Skills storage path directory does not exist.",
                        "expected_path" => $basePath,
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
                ),
            );
            return self::FAILURE;
        }

        $skillNames = [];

        // Physically scan the local files matching your active
        // dynamic configuration layout engine
        $files = File::allFiles($basePath);

        foreach ($files as $file) {
            if ($file->getExtension() === "md") {
                $relativePath = $file->getRelativePathname();
                $pathWithoutExt = Str::replaceLast(".md", "", $relativePath);

                // Convert standard directory separators back to
                // clean unified lowercase dot notation
                $dotKey = Str::lower(
                    str_replace(["/", "\\"], ".", $pathWithoutExt),
                );

                $skillNames[] = $dotKey;
            }
        }

        // Sort keys alphabetically so the final compiled output
        // payload looks highly professional
        sort($skillNames);

        $response = [
            "count" => count($skillNames),
            "skills" => $skillNames,
        ];

        // 3. Render payload tracks out to the standard interface terminal channel
        $jsonOptions = JSON_UNESCAPED_SLASHES;
        if ($this->option("pretty")) {
            $jsonOptions |= JSON_PRETTY_PRINT;
        }

        $this->line(json_encode($response, $jsonOptions));

        return self::SUCCESS;
    }
}
