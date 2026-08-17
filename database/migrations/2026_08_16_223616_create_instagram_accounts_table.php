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
        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('ig_user_id');
            // Takıma özel erişim jetonu; boşsa genel INSTAGRAM_ACCESS_TOKEN kullanılır.
            $table->text('access_token')->nullable();
            $table->string('username')->nullable();
            $table->string('name')->nullable();
            $table->string('account_type', 32)->nullable();
            $table->text('biography')->nullable();
            $table->string('website')->nullable();
            $table->unsignedBigInteger('followers_count')->default(0);
            $table->unsignedInteger('media_count')->default(0);
            $table->text('profile_picture_url')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'ig_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instagram_accounts');
    }
};
