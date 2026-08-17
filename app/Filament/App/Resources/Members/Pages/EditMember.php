<?php

namespace App\Filament\App\Resources\Members\Pages;

use App\Filament\App\Resources\Members\MemberResource;
use Filament\Resources\Pages\EditRecord;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;
}
