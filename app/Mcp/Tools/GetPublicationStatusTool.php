<?php

namespace App\Mcp\Tools;

use App\Models\Publication;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_publication_status')]
#[Description('Bir Publication’ın güncel durumunu döndürür: status, planlanan/yayınlanma zamanı, media_id, permalink ve hata kategorisi.')]
class GetPublicationStatusTool extends Tool
{
    /**
     * Tool girdi şeması.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'publication_id' => $schema->integer()->description('Sorgulanacak Publication ID')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $publication = Publication::query()->find($request->get('publication_id'));

        if (! $publication) {
            return Response::error('Publication bulunamadı.');
        }

        return Response::text(json_encode([
            'publication_id' => $publication->id,
            'status' => $publication->status,
            'scheduled_at' => $publication->scheduled_at?->toIso8601String(),
            'published_at' => $publication->published_at?->toIso8601String(),
            'media_id' => $publication->media_id,
            'permalink' => $publication->permalink,
            'error_category' => $publication->errorCategory(),
            'error_message' => $publication->error_message,
        ], JSON_THROW_ON_ERROR));
    }
}
