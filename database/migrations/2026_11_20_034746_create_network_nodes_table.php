<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('network_nodes')) {
            Schema::create('network_nodes', function (Blueprint $table) {
                $table->id();
                $table->string('identifier')->unique();
                $table->string('name');
                $table->string('type');
                $table->string('parent_identifier')->nullable();
                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('network_nodes');
    }
};