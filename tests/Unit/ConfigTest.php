<?php

namespace SentryTunnel\Tests\Unit;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use SentryTunnel\Tests\TestCase;

class ConfigTest extends TestCase
{
    #[Test]
    public function it_set_allowed_hosts_from_sentry_url()
    {
        putenv('SENTRY_LARAVEL_DSN=https://test@account.sentry.test/123');

        $config = require __DIR__.'/../../config/sentry-tunnel.php';

        $this->assertEquals('account.sentry.test', $config['allowed-hosts'][0]);
    }

    #[Test]
    public function it_set_allowed_projects_from_sentry_url()
    {
        putenv('SENTRY_LARAVEL_DSN=https://test@account.sentry.test/123');

        $config = require __DIR__.'/../../config/sentry-tunnel.php';

        $this->assertEquals('123', $config['allowed-projects'][0]);
    }

    #[Test]
    public function it_discards_allowlist_entries_that_are_empty_after_trimming()
    {
        config(['sentry-tunnel.allowed-hosts' => ['  ', '', 'account.sentry.test']]);

        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@ /123',
        ]);

        $response->assertStatus(401);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_defaults_the_log_level_to_warning()
    {
        $this->assertSame('warning', config('sentry-tunnel.log-level'));
    }

    #[Test]
    public function it_defaults_the_timeouts()
    {
        $this->assertSame(5, config('sentry-tunnel.timeout'));
        $this->assertSame(2, config('sentry-tunnel.connect-timeout'));
    }

    #[Test]
    public function it_defaults_the_max_payload_size_to_20_mib()
    {
        $config = require __DIR__.'/../../config/sentry-tunnel.php';

        $this->assertSame(20 * 1024 * 1024, $config['max-payload-size']);
    }

    #[Test]
    public function it_ships_a_rate_limit_in_the_default_middleware()
    {
        $config = require __DIR__.'/../../config/sentry-tunnel.php';

        $this->assertSame(['web', 'auth', 'throttle:300,1'], $config['middleware']);
    }
}
