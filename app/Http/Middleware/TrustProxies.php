<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    /**
     * Trust ALL proxies — required for Railway (and similar PaaS platforms) where
     * a reverse proxy / load balancer terminates TLS and forwards the original
     * HTTPS request via X-Forwarded-Proto: https.
     * Without this, Laravel cannot detect HTTPS and generates http:// URLs,
     * causing Mixed-Content errors in the browser.
     */
    protected $proxies = '*';

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
    Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
