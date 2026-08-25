<?php

use App\Mcp\Servers\PublicationServer;
use Laravel\Mcp\Facades\Mcp;

// MCP sunucusu — stateless JSON-RPC endpoint.
// GÜVENLİK NOTU: production'da kimlik doğrulama middleware'i eklenmelidir
// (Mcp::web üçüncü parametresi middleware kabul eder).
Mcp::web('mcp/publications', PublicationServer::class)
    ->name('mcp.publications')
    ->middleware([\App\Http\Middleware\EnsureMcpToken::class]);
