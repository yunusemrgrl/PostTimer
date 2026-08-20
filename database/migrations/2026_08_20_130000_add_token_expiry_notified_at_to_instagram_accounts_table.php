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
        Schema::table('instagram_accounts', function (Blueprint $table) {
            // Aynı "süre doluyor" penceresi için tekrarlayan Telegram bildirimini
            // önlemek amacıyla son bildirim zamanı saklanır (nullable).
            $table->timestamp('token_expiry_notified_at')->nullable()->after('token_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->dropColumn('token_expiry_notified_at');
        });
    }
};
