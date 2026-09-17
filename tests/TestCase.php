<?php

namespace SentryTunnel\Tests;

use Illuminate\Support\Facades\Exceptions;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;
use SentryTunnel\Provider;

class TestCase extends BaseTestCase
{
    use WithWorkbench;

    protected function getPackageProviders($app)
    {
        return [
            Provider::class,
        ];
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('sentry-tunnel.middleware', null);
    }

    /**
     * Run a callback and return every deprecation it raised.
     *
     * PHPUnit's --fail-on-deprecation cannot be used for this: Laravel's
     * HandleExceptions bootstrapper installs its own error handler and routes
     * deprecations to the deprecation log channel before PHPUnit sees them.
     *
     * @return array<int, string>
     */
    protected function captureDeprecations(callable $callback): array
    {
        $deprecations = [];

        set_error_handler(function (int $errno, string $message) use (&$deprecations): bool {
            if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
                $deprecations[] = $message;
            }

            return true;
        });

        try {
            $callback();
        } finally {
            restore_error_handler();
        }

        return $deprecations;
    }

    /**
     * Record exceptions instead of reporting them, so that a test can assert
     * the tunnel did not turn an upstream failure into an application error.
     *
     * An application error would be reported to Sentry by the host application,
     * which is the amplification loop the tunnel must not create.
     */
    protected function fakeExceptionReporting(): void
    {
        Exceptions::fake();
    }

    /**
     * Assert no exception was reported during the request.
     */
    protected function assertNothingReported(): void
    {
        Exceptions::assertNothingReported();
    }
}
