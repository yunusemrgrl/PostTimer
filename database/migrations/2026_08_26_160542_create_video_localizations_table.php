<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI video yerelleştirme iş kayıtları: Gemini analizi (timestamp'li
     * Türkçe çeviri + ekrandaki yazılar) ve ElevenLabs TTS sesi.
     * Her Content için birden fazla deneme olabilir; UI en sonuncusunu gösterir.
     */
    public function up(): void
    {
        Schema::create('video_localizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();

            // pending → analyzing → analyzed → voicing → completed / failed
            $table->string('status')->default('pending');

            $table->string('target_language')->default('tr');

            // Gemini'nin tespit ettiği kaynak dil (ISO 639-1)
            $table->string('source_language')->nullable();

            // Tam Gemini sonucu: {segments: [...], on_screen_text: [...]}
            $table->json('translation')->nullable();

            // TTS'e gönderilen Türkçe anlatım metni (segment çevirilerinin birleşimi)
            $table->text('script')->nullable();

            // Üretilen Türkçe ses (Curator Media, mp3)
            $table->foreignId('audio_media_id')->nullable()->constrained('curator')->nullOnDelete();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['content_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_localizations');
    }
};
