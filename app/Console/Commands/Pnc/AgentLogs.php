<?php

namespace App\Console\Commands\Pnc;

use Illuminate\Console\Command;

class AgentLogs extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pnc:agent:logs
                            {--date= : The explicit log date to read (Format: YYYY-MM-DD)}
                            {--tail : Live-stream incoming AI workflow activity in real-time}
                            {--lines=50 : The number of historic lines to output when opening}';

    /**
     * The console command description.
     */
    protected $description = "Stream or view the dedicated multi-agent AI execution audit logs";

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 1. Resolve target log date filename mapping
        $date = $this->option("date") ?: now()->format("Y-m-d");
        $logPath = storage_path("logs/ai_audit_{$date}.log");

        if (!file_exists($logPath)) {
            $this->error("No AI audit log file found for date: {$date}");
            $this->line("Expected path: {$logPath}");
            return self::FAILURE;
        }

        $this->info("=== Reading AI Agent Audit Logs [Date: {$date}] ===");
        $this->line("File Path: {$logPath}");
        $this->line(str_repeat("=", 80));

        // 2. Handle Live-Streaming Tail Mode
        if ($this->option("tail")) {
            $this->warn("Starting live stream... Press Ctrl+C to exit.");

            // Execute native shell tail utility directly on Linux/Mac workspace
            if (function_exists("proc_open")) {
                $process = proc_open(
                    "tail -n {$this->option("lines")} -f " .
                        escapeshellarg($logPath),
                    [
                        0 => ["file", "php://stdin", "r"],
                        1 => ["file", "php://stdout", "w"],
                        2 => ["file", "php://stderr", "w"],
                    ],
                    $pipes,
                );

                if (is_resource($process)) {
                    proc_close($process);
                }
            } else {
                // Fallback loop if proc_open is disabled in system configurations
                $lastSize = filesize($logPath);
                while (true) {
                    clearstatcache();
                    $currentSize = filesize($logPath);
                    if ($currentSize > $lastSize) {
                        $fp = fopen($logPath, "r");
                        fseek($fp, $lastSize);
                        while (!feof($fp)) {
                            echo fgets($fp);
                        }
                        fclose($fp);
                        $lastSize = $currentSize;
                    }
                    usleep(500000); // Sleep for 0.5 seconds
                }
            }
            return self::SUCCESS;
        }

        // 3. Handle Standard Historical Line Reading Mode
        $lines = array_slice(file($logPath), -$this->option("lines"));
        foreach ($lines as $line) {
            echo $line;
        }

        return self::SUCCESS;
    }
}
