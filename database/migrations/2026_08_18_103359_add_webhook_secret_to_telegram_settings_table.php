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
        Schema::table('telegram_settings', function (Blueprint $table) {
            // Webhook URL'sinde kullanılan aranabilir secret (bot_token encrypted olduğu için webhook lookup'ta kullanılamaz)
            $table->string('webhook_secret', 32)->unique()->after('bot_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_settings', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
};
