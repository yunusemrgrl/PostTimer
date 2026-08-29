<?php

namespace App\Domain\Video\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Desteklenen hedef diller (ISO 639-1). UI'da ses/dil seçtirme
 * V2 konusudur; Select bileşeni doğrudan bu enum'u kullanabilir.
 */
enum LocalizationLanguage: string implements HasLabel
{
    case Turkish = 'tr';

    case English = 'en';

    case German = 'de';

    case French = 'fr';

    case Spanish = 'es';

    case Arabic = 'ar';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Turkish => 'Türkçe',
            self::English => 'İngilizce',
            self::German => 'Almanca',
            self::French => 'Fransızca',
            self::Spanish => 'İspanyolca',
            self::Arabic => 'Arapça',
        };
    }

    /**
     * Gemini prompt'u için makine-dostu tanım (ISO kodu ile birlikte).
     */
    public function promptName(): string
    {
        return "{$this->getLabel()} ({$this->value})";
    }
}
