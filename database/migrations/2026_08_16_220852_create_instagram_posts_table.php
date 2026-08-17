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
        Schema::create('instagram_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('ig_user_id');
            $table->string('media_type')->default('IMAGE');
            $table->text('caption')->nullable();
            $table->text('media_url')->nullable();
            $table->json('children')->nullable();
            $table->text('alt_text')->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->string('container_id')->nullable();
            $table->string('media_id')->nullable();
            $table->string('status')->default('draft');
            $table->text('error_message')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instagram_posts');
    }
};
