<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Believe the X-Forwarded-* headers only from the load balancers named in
 * `app.trusted_proxies` (a comma list of IPs or CIDR ranges, or "*").
 *
 * Behind a load balancer every request arrives from the balancer's address,
 * so without this all users would share one login throttle and https would
 * read as http. With none configured no proxy is trusted, since while the app
 * is reachable directly any client could otherwise claim another IP.
 */
class TrustConfiguredProxies extends TrustProxies
{
    /**
     * Get the trusted proxies, read on every request from the config.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies(): array|string|null
    {
        $proxies = config('app.trusted_proxies');

        if (! is_string($proxies) || trim($proxies) === '') {
            return null;
        }

        return trim($proxies) === '*' ? '*' : array_map(trim(...), explode(',', $proxies));
    }
}
