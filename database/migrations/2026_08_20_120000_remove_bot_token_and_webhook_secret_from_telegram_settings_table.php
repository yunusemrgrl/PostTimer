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
        // SQLite, bir indeksin parçası olan sütunu düşüremez;
        // önce webhook_secret üzerindeki unique index kaldırılır.
        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->dropUnique('telegram_settings_webhook_secret_unique');
        });

        // Tek bot mimarisi: token .env'de, tenant ayrımı webhook_secret ile değil
        // verification_code + chat_id ile yapılıyor.
        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->dropColumn(['bot_token', 'webhook_secret']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->text('bot_token')->nullable();
            $table->string('webhook_secret', 32)->unique()->nullable();
        });
    }
};
