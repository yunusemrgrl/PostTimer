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
            // Business Login ile bağlanan hesaplarda kullanılan Graph host
            // (graph.instagram.com); elle bağlananlarda boş bırakılır.
            $table->string('api_host', 128)->nullable()->after('access_token');
            $table->timestamp('token_expires_at')->nullable()->after('api_host');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->dropColumn(['api_host', 'token_expires_at']);
        });
    }
};
