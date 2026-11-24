<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_snapshots', function (Blueprint $table) {
            $table->id();
            $table->integer('cpu_load')->default(0);
            $table->integer('memory_usage')->default(0);
            $table->integer('active_clients')->default(0);
            $table->integer('rto_clients')->default(0);
            $table->decimal('traffic_rx_mbps', 8, 2)->default(0.00);
            $table->decimal('traffic_tx_mbps', 8, 2)->default(0.00);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_snapshots');
    }
};
