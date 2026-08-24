<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            // Scheduler komutları (publish-scheduled, recover-stuck,
            // check-connections) team_id olmadan status + scheduled_at
            // üzerinde tarar; mevcut (team_id, status, scheduled_at)
            // indeksi bu sorguları desteklemez.
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
        });
    }
};
