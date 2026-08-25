<?php

namespace App\Mcp\Tools;

use App\Jobs\PublishScheduledPublication;
use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Publication;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('schedule_publication')]
#[Description('Yeni bir Instagram yayını planlar: content + hesap + zaman verilir, Publication oluşturulur ve kuyruğa dispatch edilir.')]
class SchedulePublicationTool extends Tool
{
    /**
     * Tool girdi şeması.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'content_id' => $schema->integer()->description('Planlanacak Content ID')->required(),
            'instagram_account_id' => $schema->integer()->description('Hedef Instagram hesabı ID')->required(),
            'scheduled_at' => $schema->string()->description('Yayın zamanı (ISO-8601, gelecekte olmalı)')->required(),
            'caption_override' => $schema->string()->description('Hesaba özel caption (opsiyonel)'),
        ];
    }

    /**
     * Yayını planlar. Tenant uyumsuzluğu ve geçmiş tarih hataları
     * MCP error response olarak döner.
     */
    public function handle(Request $request): Response
    {
        $content = Content::query()->find($request->get('content_id'));
        $account = InstagramAccount::query()->find($request->get('instagram_account_id'));

        if (! $content || ! $account) {
            return Response::error('content_id veya instagram_account_id bulunamadı.');
        }

        if ($content->team_id !== $account->team_id) {
            return Response::error('Content ve Instagram hesabı farklı takımlara ait.');
        }

        $scheduledAt = Carbon::parse((string) $request->get('scheduled_at'));

        if ($scheduledAt->isPast()) {
            return Response::error('scheduled_at geçmiş bir zaman olamaz.');
        }

        $publication = Publication::query()->create([
            'team_id' => $content->team_id,
            'content_id' => $content->id,
            'instagram_account_id' => $account->id,
            'ig_user_id' => $account->ig_user_id,
            'status' => Publication::STATUS_SCHEDULED,
            'scheduled_at' => $scheduledAt,
            'caption_override' => $request->get('caption_override'),
        ]);

        PublishScheduledPublication::dispatch($publication);

        return Response::text(json_encode([
            'publication_id' => $publication->id,
            'status' => $publication->status,
            'scheduled_at' => $publication->scheduled_at->toIso8601String(),
            'ig_user_id' => $publication->ig_user_id,
        ], JSON_THROW_ON_ERROR));
    }
}
