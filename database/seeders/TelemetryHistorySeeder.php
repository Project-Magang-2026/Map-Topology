<?php

namespace Database\Seeders;

use App\Models\TelemetrySnapshot;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TelemetryHistorySeeder extends Seeder
{
    public function run(): void
    {
        if (TelemetrySnapshot::count() > 0) {
            return;
        }

        $now = Carbon::now();
        $snapshots = [];

        for ($i = 24; $i >= 0; $i--) {
            $time = (clone $now)->subMinutes($i * 5); // Tiap 5 menit
            $baseCpu = 12 + rand(0, 15);
            $baseRam = 38 + rand(0, 8);
            $rto = ($i === 3 || $i === 4) ? 2 : (rand(0, 10) > 8 ? 1 : 0);

            $snapshots[] = [
                'cpu_load'        => $baseCpu,
                'memory_usage'    => $baseRam,
                'active_clients'  => 10 - $rto,
                'rto_clients'     => $rto,
                'traffic_rx_mbps' => round(35.5 + rand(5, 45) + (rand(0, 9) / 10), 2),
                'traffic_tx_mbps' => round(12.2 + rand(2, 20) + (rand(0, 9) / 10), 2),
                'created_at'      => $time,
            ];
        }

        TelemetrySnapshot::insert($snapshots);
    }
}
