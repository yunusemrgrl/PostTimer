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
            // Carousel checkpoint: tamamlanan çocuk container ID'leri
            // (pozisyon sırasına göre). Retry'ta yeniden oluşturulmaz.
            $table->json('carousel_child_container_ids')->nullable()->after('container_id');
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropColumn('carousel_child_container_ids');
        });
    }
};
