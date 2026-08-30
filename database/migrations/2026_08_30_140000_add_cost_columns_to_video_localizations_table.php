<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI video yerelleştirme maliyet takibi. Her aşama (Gemini analizi,
     * ElevenLabs TTS) sonunda tahmini USD maliyet persist edilir — böylece
     * tenant başına aylık AI harcaması raporlanabilir.
     */
    public function up(): void
    {
        Schema::table('video_localizations', function (Blueprint $table) {
            $table->decimal('estimated_cost_usd', 8, 4)->default(0)->after('error_message');
            $table->json('cost_breakdown')->nullable()->after('estimated_cost_usd');
        });
    }

    public function down(): void
    {
        Schema::table('video_localizations', function (Blueprint $table) {
            $table->dropColumn(['estimated_cost_usd', 'cost_breakdown']);
        });
    }
};
