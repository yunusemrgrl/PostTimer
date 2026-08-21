<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instagram medya insights snapshot'larını tarihsel olarak saklar.
     * Aynı post + metric için farklı fetched_at anlarında farklı değerler
     * tutulabilir (trend analizi için).
     */
    public function up(): void
    {
        Schema::create('instagram_post_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instagram_post_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('metric');
            $table->string('period')->nullable();
            $table->bigInteger('value')->default(0);
            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();

            // Trend analizi için en son snapshot'ı hızlı bulmak için index.
            $table->index(['instagram_post_id', 'metric', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_post_insights');
    }
};
