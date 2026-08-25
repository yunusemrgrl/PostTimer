<?php

namespace App\Mcp\Tools;

use App\Models\Content;
use App\Models\Team;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_content')]
#[Description('Yeni bir Content oluşturur. IMAGE/VIDEO için media_url, CAROUSEL_ALBUM için children (2-10 url) zorunludur. Dönen content_id, schedule_publication ile kullanılabilir.')]
class CreateContentTool extends Tool
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
            'type' => $schema->string()->description('IMAGE | VIDEO | CAROUSEL_ALBUM')->required(),
            'surface' => $schema->string()->description('FEED | REELS | STORY (varsayılan FEED)')->required(),
            'caption' => $schema->string()->description('Yayın açıklaması')->required(),
            'media_url' => $schema->string()->description('Herkese açık medya URL’i (IMAGE/VIDEO için zorunlu)'),
            'children' => $schema->array()->description('Karusel çocuk URL’leri (CAROUSEL_ALBUM için 2-10 adet)'),
            'first_comment' => $schema->string()->description('Otomatik ilk yorum (REELS/POST; STORY için geçersiz)'),
            'alt_text' => $schema->string()->description('Görsel alt metni (opsiyonel)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (int) ($request->get('team_id') ?? 0);

        if (! Team::query()->whereKey($teamId)->exists()) {
            return Response::error("team_id {$teamId} bulunamadı.");
        }

        $type = strtoupper((string) $request->get('type'));
        $surface = strtoupper((string) ($request->get('surface') ?: Content::SURFACE_FEED));

        if (! in_array($type, [Content::TYPE_IMAGE, Content::TYPE_VIDEO, Content::TYPE_CAROUSEL_ALBUM], true)) {
            return Response::error("Geçersiz type: {$type}. IMAGE, VIDEO veya CAROUSEL_ALBUM olmalı.");
        }

        if (! in_array($surface, [Content::SURFACE_FEED, Content::SURFACE_REELS, Content::SURFACE_STORY], true)) {
            return Response::error("Geçersiz surface: {$surface}. FEED, REELS veya STORY olmalı.");
        }

        $mediaUrl = $request->get('media_url');
        /** @var array<int, mixed>|null $children */
        $children = $request->get('children');

        if ($type === Content::TYPE_CAROUSEL_ALBUM) {
            $childCount = is_array($children) ? count(array_filter($children)) : 0;

            if ($childCount < 2 || $childCount > 10) {
                return Response::error('CAROUSEL_ALBUM için 2-10 arası children URL’i gereklidir.');
            }
        } elseif (! filled($mediaUrl)) {
            return Response::error("{$type} için media_url zorunludur.");
        }

        if ($surface === Content::SURFACE_STORY && filled($request->get('first_comment'))) {
            return Response::error('STORY yüzeyi first_comment desteklemez.');
        }

        $content = Content::query()->create([
            'team_id' => $teamId,
            'type' => $type,
            'surface' => $surface,
            'caption' => (string) $request->get('caption'),
            'media_url' => $type === Content::TYPE_CAROUSEL_ALBUM ? null : $mediaUrl,
            'children' => $type === Content::TYPE_CAROUSEL_ALBUM
                ? array_values(array_map(
                    fn (mixed $url): array => ['url' => (string) $url],
                    array_filter(is_array($children) ? $children : []),
                ))
                : null,
            'alt_text' => $request->get('alt_text'),
            'first_comment' => $request->get('first_comment'),
            // MCP üzerinden üretilen içerik AI kaynaklı işaretlenmez;
            // istemci isterse metadata ile bilgi verebilir.
            'is_ai_generated' => false,
        ]);

        return Response::text(json_encode([
            'content_id' => $content->id,
            'type' => $content->type,
            'surface' => $content->surface,
            'children_count' => $content->children !== null ? count($content->children) : null,
        ], JSON_THROW_ON_ERROR));
    }
}
