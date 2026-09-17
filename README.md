# Laravel Sentry Tunnel

This package provides a URL for use with the [`tunnel`-option](https://docs.sentry.io/platforms/javascript/troubleshooting/#using-the-tunnel-option) of the Sentry SDK.

[![Latest Version](https://img.shields.io/packagist/v/asbiin/laravel-sentry-tunnel?style=flat-square&label=Latest%20Version)](https://github.com/asbiin/laravel-sentry-tunnel/releases)
[![Downloads](https://img.shields.io/packagist/dt/asbiin/laravel-sentry-tunnel?style=flat-square&label=Downloads)](https://packagist.org/packages/asbiin/laravel-sentry-tunnel)
[![Workflow Status](https://img.shields.io/github/actions/workflow/status/asbiin/laravel-sentry-tunnel/tests.yml?branch=main&style=flat-square&label=Workflow%20Status)](https://github.com/asbiin/laravel-sentry-tunnel/actions?query=branch%3Amain)
[![Quality Gate](https://img.shields.io/sonar/quality_gate/asbiin_laravel-sentry-tunnel?server=https%3A%2F%2Fsonarcloud.io&style=flat-square&label=Quality%20Gate)](https://sonarcloud.io/dashboard?id=asbiin_laravel-sentry-tunnel)
[![Coverage Status](https://img.shields.io/sonar/coverage/asbiin_laravel-sentry-tunnel?server=https%3A%2F%2Fsonarcloud.io&style=flat-square&label=Coverage%20Status)](https://sonarcloud.io/dashboard?id=asbiin_laravel-sentry-tunnel)


# Installation

```shell
composer require asbiin/laravel-sentry-tunnel
```

## Configuration

You can optionally publish the configuration files:

```shell
php artisan vendor:publish --provider=SentryTunnel\\Provider
```

### Allowed hosts and projects

The project will use `SENTRY_LARAVEL_DSN` value to set the valid sentry host and project to tunnel the traffic for.

You can define the allowed hosts by setting the `SENTRY_TUNNEL_ALLOWED_HOSTS` value in your `.env` file, and the allowed projects by setting the `SENTRY_TUNNEL_ALLOWED_PROJECTS` value.

```dotenv
SENTRY_TUNNEL_ALLOWED_HOSTS=my.host.com
SENTRY_TUNNEL_ALLOWED_PROJECTS=1234,456,78
```

Surrounding whitespace is ignored, and hosts are matched without regard to case, so
`my.host.com, other.host.com` works as written. If the host list is empty, every request is refused.

### Security

This essentially creates a reverse proxy to the `SENTRY_TUNNEL_ALLOWED_HOSTS`. As the Sentry DSN is not kept secret, this enables everyone to send messages to these hosts that seem to originate from your server.

Therefore, the default middleware list for the tunnel URL includes `web`, `auth` (so that only authenticated users can use the endpoint) and `throttle:300,1`, which limits one caller to 300 reports per minute. The rate limit sits after `auth` so an unauthenticated probe is refused without consuming a slot.

You can change the middleware list of the tunnel endpoint by setting the `sentry-tunnel.middleware` value of your `config/sentry-tunnel.php` file.

### Payload size

The tunnel refuses an envelope larger than `sentry-tunnel.max-payload-size` with a `413`, before the
body is read.

```dotenv
SENTRY_TUNNEL_MAX_PAYLOAD_SIZE=20971520
```

The default of 20 MiB sits far above anything a browser SDK produces, including session replay and
profiling payloads, and below Sentry's own envelope ceiling, so it never refuses a report Sentry
would have accepted. Set it to `null` in the config file to disable the check. PHP's `post_max_size`
is not a substitute: it is not applied to a body whose content type is not a form type.

### Timeouts

```dotenv
SENTRY_TUNNEL_TIMEOUT=5
SENTRY_TUNNEL_CONNECT_TIMEOUT=2
```

How long, in seconds, the tunnel waits for Sentry. These replace the framework defaults of 30 and 10
seconds, and bound how long a server worker is held when Sentry is slow or unreachable. The rate
limit does not bound this — it limits how many requests are admitted, not how long each one is held.
Raise them if your egress proxy is slow. When the timeout expires the caller receives a `504`.

### Logging

```dotenv
SENTRY_TUNNEL_LOG_LEVEL=warning
```

The level at which a failed or unreachable Sentry request is logged, with the upstream status, host
and project — never the upstream response text, and never the envelope.

The default is `warning` on purpose: the Sentry Laravel SDK treats an ordinary log record at that
level as a breadcrumb rather than an event. Raising this to `error` makes tunnel failures visible as
events, but it also means every failed relay sends a new report to the very Sentry instance that is
already failing.

### Responses

The tunnel relays Sentry's own status code, along with the `Retry-After` and `X-Sentry-Rate-Limits`
headers, so that the Sentry SDK can apply its own backoff when your organisation is being rate
limited. On success the upstream body and content type are relayed too. On an upstream error the
status is relayed but the body is not, so Sentry's error text is never disclosed to the browser.

### CsrfToken

As you currently cannot pass a dynamic `X-XSRF-TOKEN` header in Sentry's `transportOptions` you either have to implement your own transport or place the tunnel URL in the exclude-list in the `VerifyCsrfToken` middleware.

* Add the URL to the `except` array in the `VerifyCsrfToken` middleware.

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        '/sentry/tunnel',
    ]);
})
```

* OR Implement your own transport

_example_:

```js
const myTransport = (options) => {
  const makeRequest = async (request) => {
    const requestOptions = {
      data: request.body,
      url: options.url,
      method: 'POST',
      referrerPolicy: 'origin',
      headers: options.headers,
      ...options.fetchOptions,
    };
    return axios(requestOptions).then((response) => ({
      statusCode: response.status,
      headers: response.headers,
    }));
  };
  return createTransport({ bufferSize: options.bufferSize }, makeRequest);
};

Sentry.init({
    // ...
    transport: myTransport,
});
```

### URL

You can change the URL of the tunnel if required. The default value is `/sentry/tunnel`

```dotenv
SENTRY_TUNNEL_URL="/super/secret/tunnel"
```

## Usage

Consult [Sentry's documentation](https://docs.sentry.io/platforms/javascript/troubleshooting/#using-the-tunnel-option).


# Citations

This package has been forked from [naugrim/laravel-sentry-tunnel](https://github.com/Naugrimm/laravel-sentry-tunnel), with some slight changes.


# License

Author: [Alexis Saettler](https://github.com/asbiin)

Copyright © 2024.

Licensed under the MIT License. [View license](/LICENSE.md).
