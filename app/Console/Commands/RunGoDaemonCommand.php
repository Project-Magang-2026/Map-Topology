<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RunGoDaemonCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'go:serve {--port=8080 : Port HTTP daemon Go}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Menjalankan Go Sub-Second Telemetry Daemon (Port :8080) dengan real-time SSE stream 500ms.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $port = $this->option('port');
        $this->info("=================================================================");
        $this->info(" ⚡ MIKROTIK TOPOLOGY - GO SUB-SECOND TELEMETRY ENGINE");
        $this->info("=================================================================");
        $this->comment(" Streaming Server-Sent Events (SSE) : http://127.0.0.1:{$port}/events");
        $this->comment(" JSON Telemetry Snapshot           : http://127.0.0.1:{$port}/telemetry");
        $this->comment(" Siklus Refresh                     : 500ms Sub-Second");
        $this->info(" Tekan Ctrl+C untuk menghentikan server Go.");
        $this->newLine();

        $goBackendDir = base_path('go-backend');
        $exePath = $goBackendDir . DIRECTORY_SEPARATOR . 'mikrotik-gis.exe';

        if (file_exists($exePath)) {
            $cmd = [$exePath];
        } else {
            $cmd = ['go', 'run', '.'];
        }

        $process = new Process($cmd, $goBackendDir, [
            'PORT' => (string) $port,
            'DB_DATABASE' => database_path('database.sqlite'),
            'MIKROTIK_HOST' => env('MIKROTIK_HOST', '103.142.194.75'),
            'MIKROTIK_USER' => env('MIKROTIK_USER', 'magang'),
            'MIKROTIK_PASS' => env('MIKROTIK_PASS', '2026##'),
            'MIKROTIK_PORT' => env('MIKROTIK_PORT', '8777'),
        ]);

        $process->setTimeout(null);

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return Command::SUCCESS;
    }
}
