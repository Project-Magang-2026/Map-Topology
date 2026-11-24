<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_nodes', function (Blueprint $table) {
            $table->string('node_subtype')->nullable();
            $table->integer('capacity_ports')->default(8);
            $table->integer('used_ports')->default(0);
            $table->string('fiber_core_color')->nullable();
            $table->decimal('optical_power_rx', 5, 2)->nullable();
            $table->decimal('attenuation_db', 5, 2)->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('network_nodes', function (Blueprint $table) {
            $table->dropColumn([
                'node_subtype',
                'capacity_ports',
                'used_ports',
                'fiber_core_color',
                'optical_power_rx',
                'attenuation_db',
                'notes',
            ]);
        });
    }
};
