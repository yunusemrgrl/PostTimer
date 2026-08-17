<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Yaygın bot/vulnerability tarayıcı isteklerini engeller:
 * - Gizli dosya yolları (.env, .git, wp-admin, phpmyadmin vb.) → 404
 * - Bilinen tarayıcı User-Agent'ları → 404
 * - Kayıt rotaları → 404 (register kapalı)
 *
 * 404 dönülür, 403 değil — varolmayan yolun teyidi verilmez.
 */
class BlockMaliciousRequests
{
    /**
     * @var array<int, string>
     */
    protected array $blockedPathPatterns = [
        '/\.env',
        '/\.git',
        '/\.aws',
        '/\.ssh',
        '/\.docker',
        '/wp-admin',
        '/wp-login',
        '/wp-content',
        '/xmlrpc\.php',
        '/phpinfo',
        '/phpmyadmin',
        '/pma',
        '/myadmin',
        '/adminer',
        '/config\.php',
        '/composer\.',
        '/vendor/',
        '/storage/',
        '/register',
        '/cgi-bin',
        '/jenkins',
        '/solr',
        '/actuator',
        '/\.aws/credentials',
    ];

    /**
     * @var array<int, string>
     */
    protected array $blockedUserAgents = [
        'curl',
        'python-requests',
        'python-urllib',
        'scrapy',
        'nikto',
        'sqlmap',
        'masscan',
        'nmap',
        'zgrab',
        'zabbix',
        'go-http-client',
        'java/',
        'okhttp',
        'httpx',
        'httprobe',
        'crawler',
        'semrushbot',
        'ahrefsbot',
        'dotbot',
        'bytespider',
        'yandexbot',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isMaliciousPath($request) || $this->isMaliciousUserAgent($request)) {
            abort(404);
        }

        return $next($request);
    }

    protected function isMaliciousPath(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        foreach ($this->blockedPathPatterns as $pattern) {
            if (preg_match('#'.str_replace('#', '\#', $pattern).'#i', $path)) {
                return true;
            }
        }

        return false;
    }

    protected function isMaliciousUserAgent(Request $request): bool
    {
        $userAgent = strtolower((string) $request->userAgent());

        foreach ($this->blockedUserAgents as $bot) {
            if (str_contains($userAgent, $bot)) {
                return true;
            }
        }

        return false;
    }
}
