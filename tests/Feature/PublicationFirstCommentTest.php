<?php

use App\Events\PublicationPublished;
use App\Jobs\PublishPublicationFirstComment;
use App\Listeners\PublishPublicationFirstCommentListener;
use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Publication;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function firstCommentPublication(?string $contentCaption = null, ?string $firstComment = 'İlk yorum', string $surface = Content::SURFACE_REELS): Publication
{
    $teamId = Team::factory()->create()->id;

    $content = Content::factory()->create([
        'team_id' => $teamId,
        'caption' => $contentCaption,
        'first_comment' => $firstComment,
        'type' => Content::TYPE_VIDEO,
        'surface' => $surface,
    ]);

    $account = InstagramAccount::factory()->create([
        'team_id' => $teamId,
        'ig_user_id' => '2915115069225431',
        'api_host' => 'graph.instagram.com',
        'access_token' => 'account-token',
        'username' => 'hesap1',
    ]);

    return Publication::factory()->published()->create([
        'team_id' => $teamId,
        'content_id' => $content->id,
        'instagram_account_id' => $account->id,
        'ig_user_id' => $account->ig_user_id,
        'media_id' => 'ig_media_1',
    ]);
}

it('dispatches the first comment job when content has first_comment and is not a story', function () {
    Queue::fake();

    $publication = firstCommentPublication();

    app(PublishPublicationFirstCommentListener::class)
        ->handle(new PublicationPublished($publication));

    Queue::assertPushed(PublishPublicationFirstComment::class, 1);
});

it('does not dispatch when content has no first_comment', function () {
    Queue::fake();

    $publication = firstCommentPublication(firstComment: null);

    app(PublishPublicationFirstCommentListener::class)
        ->handle(new PublicationPublished($publication));

    Queue::assertNothingPushed();
});

it('does not dispatch first comment for story surfaces', function () {
    Queue::fake();

    $publication = firstCommentPublication(surface: Content::SURFACE_STORY);

    app(PublishPublicationFirstCommentListener::class)
        ->handle(new PublicationPublished($publication));

    Queue::assertNothingPushed();
});

it('posts the first comment to the publication media', function () {
    Http::fake([
        'https://graph.instagram.com/*/ig_media_1/comments*' => Http::response(['id' => 'comment_1']),
        '*' => Http::response(),
    ]);

    $publication = firstCommentPublication(firstComment: 'Bunu al, çok iyi!');

    (new PublishPublicationFirstComment($publication))->handle();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/ig_media_1/comments')
            && ($request['message'] ?? null) === 'Bunu al, çok iyi!';
    });
});

it('skips first comment when publication has no media_id', function () {
    Http::fake(['*' => Http::response()]);

    $publication = firstCommentPublication();
    $publication->update(['media_id' => null]);

    (new PublishPublicationFirstComment($publication->fresh()))->handle();

    Http::assertNothingSent();
});

it('builds a correct unique id', function () {
    $publication = firstCommentPublication();

    expect((new PublishPublicationFirstComment($publication))->uniqueId())
        ->toBe("first-comment-publication-{$publication->id}");
});

it('does not enqueue a duplicate job for the same publication', function () {
    Queue::fake();

    $publication = firstCommentPublication();

    PublishPublicationFirstComment::dispatch($publication);
    PublishPublicationFirstComment::dispatch($publication);

    Queue::assertPushed(PublishPublicationFirstComment::class, 1);
});
