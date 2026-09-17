<?php

use Illuminate\Support\Str;

return [
    /*
    |--------------------------------------------------------------------------
    | Allowed Hosts
    |--------------------------------------------------------------------------
    |
    | This is the list of target sentry hosts that are allowed to use the tunnel.
    | It uses the value of 'SENTRY_LARAVEL_DSN' by default.
    |
    */

    'allowed-hosts' => explode(',', env('SENTRY_TUNNEL_ALLOWED_HOSTS', (string) Str::of(env('SENTRY_LARAVEL_DSN'))->after('@')->before('/'))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Projects
    |--------------------------------------------------------------------------
    |
    | This is the list of target sentry projects that are allowed to use the tunnel.
    | It uses the value of 'SENTRY_LARAVEL_DSN' by default.
    | If the value is empty, all projects are allowed. Otherwise, it should be a
    | comma-separated list of project IDs.
    */

    'allowed-projects' => explode(',', env('SENTRY_TUNNEL_ALLOWED_PROJECTS', (string) Str::of(env('SENTRY_LARAVEL_DSN'))->afterLast('/'))),

    /*
    |--------------------------------------------------------------------------
    | Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where the tunnel will be accessible from. If the
    | setting is null, the route will reside under the same domain as the
    | application. Otherwise, this value will be used as the subdomain.
    |
    */

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Tunnel url
    |--------------------------------------------------------------------------
    |
    | This is the URI path of the tunnel. It is used to define the route that
    | will be used to proxy the requests to Sentry.
    |
    */

    'tunnel-url' => env('SENTRY_TUNNEL_URL', '/sentry/tunnel'),

    /*
    |--------------------------------------------------------------------------
    | Maximum payload size
    |--------------------------------------------------------------------------
    |
    | The largest envelope, in bytes, the tunnel will relay. Set it to null to
    | disable the check.
    |
    | The default of 20 MiB sits far above anything a browser SDK produces,
    | including session replay and profiling payloads, and below Sentry's own
    | envelope ceiling, so it never refuses a report Sentry would have
    | accepted. Raise it if you deliberately route large attachments through
    | the tunnel. Note that PHP's post_max_size does not cover this: it is not
    | applied to a body whose content type is not a form type.
    |
    */

    'max-payload-size' => env('SENTRY_TUNNEL_MAX_PAYLOAD_SIZE', 20 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | How long, in seconds, the tunnel waits for Sentry. These replace the
    | framework defaults of 10 and 30 seconds. They bound how long a server
    | worker is held when Sentry is slow or unreachable. The rate limit in the
    | middleware list below does not bound this: it limits how many requests
    | are admitted, not how long each one is held. Raise them if your egress
    | proxy is slow.
    |
    */

    'timeout' => env('SENTRY_TUNNEL_TIMEOUT', 5),

    'connect-timeout' => env('SENTRY_TUNNEL_CONNECT_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Log level
    |--------------------------------------------------------------------------
    |
    | The level at which a failed or unreachable Sentry request is logged.
    |
    | The default is 'warning' on purpose: the Sentry Laravel SDK treats an
    | ordinary log record at that level as a breadcrumb rather than an event.
    | Raising this to 'error' makes tunnel failures visible as events, but it
    | also means every failed relay sends a new report to the very Sentry
    | instance that is already failing. That feedback loop is what the default
    | avoids.
    |
    */

    'log-level' => env('SENTRY_TUNNEL_LOG_LEVEL', 'warning'),

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middlewares will be assigned to the tunnel route.
    |
    | The rate limit is deliberately generous: a browser sending more than five
    | error reports per second is already pathological, so no legitimate
    | installation should notice it, while a looping or compromised account is
    | bounded. It sits after 'auth' so an unauthenticated probe is refused
    | without consuming a rate-limit slot.
    |
    */

    'middleware' => [
        'web',
        'auth',
        'throttle:300,1',
    ],
];
