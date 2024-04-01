# Laravel Sentry Tunnel

This package provides a URL for use with the [`tunnel`-option](https://docs.sentry.io/platforms/javascript/troubleshooting/#using-the-tunnel-option) of the Sentry SDK.

## Installation

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

### Security

This essentially creates a reverse proxy to the `SENTRY_TUNNEL_ALLOWED_HOSTS`. As the Sentry DSN is not kept secret, this enables everyone to send messages to these hosts that seem to originate from your server.

Therefore, the default middleware list for the tunnel URL includes `web` and `auth` (so that only authenticated users can use the endpoint).

You can change the middleware list of the tunnel endpoint by setting the `sentry-tunnel.middleware` value of your `config/sentry-tunnel.php` file.

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
