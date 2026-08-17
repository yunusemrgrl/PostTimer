<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Önce mevcut boş satırları varsayılan host'a taşı, sonra NULL'u yasakla.
        DB::table('instagram_accounts')
            ->whereNull('api_host')
            ->update(['api_host' => 'graph.instagram.com']);

        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->string('api_host', 128)->default('graph.instagram.com')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->string('api_host', 128)->nullable()->change();
        });
    }
};
