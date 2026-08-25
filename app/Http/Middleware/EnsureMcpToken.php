<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP JSON-RPC endpoint'ine basit token kapısı.
 *
 * MCP_TOKEN env değişkeni TANIMLIYSA istek X-Mcp-Token başlığıyla
 * eşleşmek zorundadır; tanımsızsa (local dev) istek serbesttir —
 * production'da bu env mutlaka set edilmelidir.
 */
class EnsureMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('media.mcp_token');

        if ($expected === '') {
            return $next($request);
        }

        if (! hash_equals($expected, (string) $request->header('X-Mcp-Token'))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
