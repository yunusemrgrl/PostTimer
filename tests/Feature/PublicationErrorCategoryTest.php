<?php

use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function publicationWithMessage(?string $message): Publication
{
    return Publication::factory()->create(['error_message' => $message]);
}

it('categorizes quota errors', function () {
    $publication = publicationWithMessage('Instagram 24 saatlik API yayın limiti doldu.');

    expect($publication->errorCategory())->toBe(Publication::ERROR_CATEGORY_QUOTA);
});

it('categorizes timeout errors', function () {
    $publication = publicationWithMessage('publishing_timed_out');

    expect($publication->errorCategory())->toBe(Publication::ERROR_CATEGORY_TIMEOUT);
});

it('categorizes token and connection errors case-insensitively', function () {
    expect(publicationWithMessage('Instagram hesabı yayın öncesi sağlık kontrolünde erişilemedi; jeton/bağlantıyı kontrol edin.')->errorCategory())
        ->toBe(Publication::ERROR_CATEGORY_TOKEN);

    expect(publicationWithMessage('Invalid OAuth access TOKEN')->errorCategory())
        ->toBe(Publication::ERROR_CATEGORY_TOKEN);
});

it('falls back to api_error for unrecognized messages and unknown for empty ones', function () {
    expect(publicationWithMessage('( ! ) Some weird HTTP 500 blowup')->errorCategory())
        ->toBe(Publication::ERROR_CATEGORY_API);

    expect(publicationWithMessage(null)->errorCategory())
        ->toBe(Publication::ERROR_CATEGORY_UNKNOWN);
});

it('exposes turkish labels and badge colors per category', function () {
    $labels = Publication::errorCategories();

    expect($labels[Publication::ERROR_CATEGORY_QUOTA])->toBe('Kota doldu')
        ->and(Publication::errorCategoryColor(Publication::ERROR_CATEGORY_QUOTA))->toBe('warning')
        ->and(Publication::errorCategoryColor(Publication::ERROR_CATEGORY_TIMEOUT))->toBe('danger')
        ->and(Publication::errorCategoryColor(Publication::ERROR_CATEGORY_UNKNOWN))->toBe('gray');
});
