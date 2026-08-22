<?php

namespace App\Domain\Instagram\Payload;

/**
 * Bir medya için Meta Graph API'ye gönderilecek tüm verilerin ortak
 * sözleşmesi. Her somut payload, kendi tipine özel alanları ekleyerek
 * `toPayload()` ile meta-agnostik (üniform) bir dizi üretir.
 */
interface InstagramMediaPayload
{
    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array;
}
