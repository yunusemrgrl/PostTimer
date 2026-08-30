<?php

namespace App\Domain\Instagram\Services;

use App\Models\InstagramAccount;

/**
 * Bir InstagramAccount kaydının profil bilgilerini Graph API'den
 * getirip veritabanına senkronize eder.
 */
class InstagramAccountService
{
    /**
     * @var array<int, string>
     */
    private const FIELDS = [
        'username',
        'name',
        'account_type',
        'biography',
        'website',
        'followers_count',
        'media_count',
        'profile_picture_url',
    ];

    public function sync(InstagramAccount $account): InstagramAccount
    {
        // İstemci yalnızca bu hesabın kendi jetonundan kurulur;
        // jeton yoksa forAccount() açık hata fırlatır.
        $client = InstagramPublishingService::forAccount($account);

        $data = $client->getAccount($account->ig_user_id, self::FIELDS);

        $account->fill(collect($data)
            ->only(self::FIELDS)
            ->all());

        $account->last_synced_at = now();
        $account->save();

        return $account->refresh();
    }
}
