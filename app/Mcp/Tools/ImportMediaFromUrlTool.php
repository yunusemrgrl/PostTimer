<?php

namespace App\Mcp\Tools;

use App\Domain\Publishing\Services\MediaUrlImporter;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('import_media_from_url')]
#[Description('Harici bir URL’i SSRF korumalı indirip takımın medya kütüphanesine ekler. İzinli tipler: image/jpeg|png|gif|webp, video/mp4|quicktime|webm (max 50 MB). Dönen media_id, Content oluştururken media_url yerine kullanılabilir.')]
class ImportMediaFromUrlTool extends Tool
{
    /**
     * Tool girdi şeması.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'team_id' => $schema->integer()->description('Hedef takım (tenant) ID')->required(),
            'url' => $schema->string()->description('Herkese açık http(s) medya URL’i')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = Team::query()->find($request->get('team_id'));

        if (! $team) {
            return Response::error('Takım bulunamadı.');
        }

        try {
            $media = app(MediaUrlImporter::class)->import($team, (string) $request->get('url'));
        } catch (Throwable $e) {
            return Response::error('İçe aktarma başarısız: '.$e->getMessage());
        }

        return Response::text(json_encode([
            'media_id' => $media->id,
            'path' => $media->path,
            'mime_type' => $media->type,
            'size_bytes' => $media->size,
            'thumbnail_status' => $media->curations['thumbnail_status'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }
}
