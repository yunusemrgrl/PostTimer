<?php

namespace App\Filament\App\Resources\Members\Pages;

use App\Filament\App\Resources\Members\MemberResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;
}
