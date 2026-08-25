<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateContentTool;
use App\Mcp\Tools\GetPublicationStatusTool;
use App\Mcp\Tools\ImportMediaFromUrlTool;
use App\Mcp\Tools\ListPublicationsTool;
use App\Mcp\Tools\SchedulePublicationTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Publication Server')]
#[Version('0.1.0')]
#[Instructions('PostTimer yayın hattı için MCP sunucusu. Akış: create_content → schedule_publication → get_publication_status / list_publications. Content ve Instagram hesabı aynı takıma ait olmalıdır.')]
class PublicationServer extends Server
{
    protected array $tools = [
        CreateContentTool::class,
        ImportMediaFromUrlTool::class,
        SchedulePublicationTool::class,
        GetPublicationStatusTool::class,
        ListPublicationsTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
