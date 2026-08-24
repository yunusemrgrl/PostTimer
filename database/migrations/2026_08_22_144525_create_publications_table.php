<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Publication domain'i: Bir content'in tek bir Instagram hesabındaki
     * yayın kaydı. Mevcut InstagramPost'un publishing alanlarının yeni
     * ev sahibi; InstagramPost refactor'u sonraki adımlarda yapılır.
     */
    public function up(): void
    {
        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instagram_account_id')->constrained()->cascadeOnDelete();

            // Denormalize: publish servisi ve sorgu kolaylığı için
            $table->string('ig_user_id');

            $table->string('status')->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('ig_media_timestamp')->nullable();

            // Idempotency + container resume alanları (InstagramPost ile aynı anlamda)
            $table->string('container_id')->nullable();
            $table->string('media_id')->nullable()->index();
            $table->string('permalink')->nullable();

            $table->text('error_message')->nullable();

            // Hesaba özel caption (Content'in varsayılan caption'ını ezer)
            $table->text('caption_override')->nullable();

            $table->timestamp('last_publish_attempt_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Aynı içerik aynı hesaba yalnızca bir kez planlanabilir
            $table->unique(['content_id', 'instagram_account_id']);

            // Scheduler taraması: status + scheduled_at birlikte taranır
            $table->index(['team_id', 'status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publications');
    }
};
