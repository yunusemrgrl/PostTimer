<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetPublicationStatusTool;
use App\Mcp\Tools\SchedulePublicationTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Publication Server')]
#[Version('0.1.0')]
#[Instructions('PostTimer yayın hattı için MCP sunucusu. schedule_publication ile yeni yayın planlayın; get_publication_status ile durum sorgulayın. Content ve Instagram hesabı aynı takıma ait olmalıdır.')]
class PublicationServer extends Server
{
    protected array $tools = [
        SchedulePublicationTool::class,
        GetPublicationStatusTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
