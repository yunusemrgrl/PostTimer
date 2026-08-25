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

#[Name('list_publications')]
#[Description('Publication listesi döndürür: status filtresi (draft/scheduled/publishing/published/failed/flagged/cancelled) ve limit (varsayılan 20, en fazla 100). En yeni kayıtlar önce.')]
class ListPublicationsTool extends Tool
{
    /**
     * Tool girdi şeması.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Durum filtresi (opsiyonel)'),
            'limit' => $schema->integer()->description('Kayıt sınırı (1-100, varsayılan 20)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $status = $request->get('status');
        $limit = min(max((int) ($request->get('limit') ?? 20), 1), 100);

        $allowed = array_keys(Publication::statuses());

        if (filled($status) && ! in_array((string) $status, $allowed, true)) {
            return Response::error('Geçersiz status. Geçerli değerler: '.implode(', ', $allowed));
        }

        $rows = Publication::query()
            ->when(filled($status), fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'status', 'scheduled_at', 'published_at', 'media_id', 'permalink', 'ig_user_id'])
            ->map(fn (Publication $publication): array => [
                'id' => $publication->id,
                'status' => $publication->status,
                'scheduled_at' => $publication->scheduled_at?->toIso8601String(),
                'published_at' => $publication->published_at?->toIso8601String(),
                'media_id' => $publication->media_id,
                'permalink' => $publication->permalink,
                'ig_user_id' => $publication->ig_user_id,
            ])
            ->all();

        return Response::text(json_encode(['count' => count($rows), 'publications' => $rows], JSON_THROW_ON_ERROR));
    }
}
