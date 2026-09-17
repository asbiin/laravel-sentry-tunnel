<?php

namespace SentryTunnel\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SentryTunnel\Tests\TestCase;

class SentryTunnelTest extends TestCase
{
    #[Test]
    public function it_proxies_a_request()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $data = [
            'dsn' => 'https://user@account.sentry.test/123',
        ];
        $response = $this->postJson('/sentry/tunnel', $data);

        $response->assertStatus(200);

        Http::assertSent(function (Request $request) use ($data) {
            $this->assertEquals('https://account.sentry.test/api/123/envelope/?sentry_key=user', $request->url());
            $this->assertTrue($request->hasHeader('Content-Type', 'application/x-sentry-envelope'));
            $this->assertEquals(json_encode($data), $request->body());

            return true;
        });
    }

    #[Test]
    public function it_proxies_the_enveloppe()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $data = implode("\n", [
            json_encode([
                'dsn' => 'https://user@account.sentry.test/123',
            ]),
            json_encode([
                'type' => 'client_report',
            ]),
            json_encode([
                'timestamp' => '123',
            ]),
        ]);

        $response = $this->call('POST', '/sentry/tunnel', content: $data);

        $response->assertStatus(200);

        Http::assertSent(function (Request $request) use ($data) {
            $this->assertEquals('https://account.sentry.test/api/123/envelope/?sentry_key=user', $request->url());
            $this->assertTrue($request->hasHeader('Content-Type', 'application/x-sentry-envelope'));
            $this->assertEquals($data, $request->body());

            return true;
        });
    }

    #[Test]
    public function it_fails_if_no_user()
    {
        Http::fake([
            '*' => Http::response(),
        ]);

        $data = [
            'dsn' => 'https://account.sentry.test/123',
        ];
        $response = $this->postJson('/sentry/tunnel', $data);

        $response->assertStatus(401);
        $response->assertSee('no user');

        Http::assertNothingSent();
    }

    #[Test]
    public function it_fails_if_invalid_host()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $data = [
            'dsn' => 'https://user@host',
        ];
        $response = $this->postJson('/sentry/tunnel', $data);

        $response->assertStatus(401);
        $response->assertSee('invalid host');

        Http::assertNothingSent();
    }

    #[Test]
    public function it_fails_if_no_project()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $data = [
            'dsn' => 'https://user@account.sentry.test',
        ];
        $response = $this->postJson('/sentry/tunnel', $data);

        $response->assertStatus(422);
        $response->assertSee('no project');

        Http::assertNothingSent();
    }

    #[Test]
    public function it_fails_if_project_void()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $data = [
            'dsn' => 'https://user@account.sentry.test/0',
        ];
        $response = $this->postJson('/sentry/tunnel', $data);

        $response->assertStatus(422);
        $response->assertSee('no project');

        Http::assertNothingSent();
    }

    #[Test]
    public function it_fails_if_project_not_allowed()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $data = [
            'dsn' => 'https://user@account.sentry.test/456',
        ];
        $response = $this->postJson('/sentry/tunnel', $data);

        $response->assertStatus(401);
        $response->assertSee('invalid project');

        Http::assertNothingSent();
    }

    #[Test]
    public function it_relays_an_upstream_error_status()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Http::fake([
            '*' => Http::response('error', status: 500),
        ]);

        $data = [
            'dsn' => 'https://user@account.sentry.test/123',
        ];
        $response = $this->postJson('/sentry/tunnel', $data);

        $response->assertStatus(500);

        Http::assertSent(function (Request $request) use ($data) {
            $this->assertEquals('https://account.sentry.test/api/123/envelope/?sentry_key=user', $request->url());
            $this->assertTrue($request->hasHeader('Content-Type', 'application/x-sentry-envelope'));
            $this->assertEquals(json_encode($data), $request->body());

            return true;
        });
    }

    #[Test]
    public function it_relays_the_upstream_success_status_and_headers()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Http::fake([
            '*' => Http::response('{"id":"abc"}', 202, [
                'Content-Type' => 'application/json',
                'X-Sentry-Rate-Limits' => '60::organization',
            ]),
        ]);

        $response = $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@account.sentry.test/123',
        ]);

        $response->assertStatus(202);
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('60::organization', $response->headers->get('X-Sentry-Rate-Limits'));
        $this->assertSame('{"id":"abc"}', $response->getContent());
    }

    #[Test]
    public function it_relays_the_throttling_signal()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Http::fake([
            '*' => Http::response('rate limited', 429, [
                'Retry-After' => '60',
                'X-Sentry-Rate-Limits' => '60::organization',
            ]),
        ]);

        $response = $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@account.sentry.test/123',
        ]);

        $response->assertStatus(429);
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertSame('60::organization', $response->headers->get('X-Sentry-Rate-Limits'));
    }

    #[Test]
    public function it_does_not_relay_headers_outside_the_allowlist()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Http::fake([
            '*' => Http::response('ok', 200, [
                'Content-Type' => 'application/json',
                'X-Internal-Secret' => 'leak-me',
            ]),
        ]);

        $response = $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@account.sentry.test/123',
        ]);

        $response->assertStatus(200);
        $this->assertNull($response->headers->get('X-Internal-Secret'));
    }

    #[Test]
    public function it_never_discloses_the_upstream_response_text()
    {
        config(['app.debug' => true]);
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Http::fake([
            '*' => Http::response('bad envelope', 400),
        ]);

        $response = $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@account.sentry.test/123',
        ]);

        $response->assertStatus(400);
        $this->assertStringNotContainsString('bad envelope', $response->getContent());
    }

    #[Test]
    public function it_does_not_report_an_upstream_error_as_an_application_failure()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        $this->fakeExceptionReporting();
        Http::fake([
            '*' => Http::response('boom', 503),
        ]);

        $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@account.sentry.test/123',
        ])->assertStatus(503);

        $this->assertNothingReported();
    }

    #[Test]
    public function it_logs_an_upstream_error_without_the_response_text()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Log::spy();
        Http::fake([
            '*' => Http::response('secret upstream detail', 503),
        ]);

        $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@account.sentry.test/123',
        ])->assertStatus(503);

        Log::shouldHaveReceived('log')->once()->withArgs(function ($level, $message, $context) {
            $this->assertSame('warning', $level);
            $this->assertSame(503, $context['status']);
            $this->assertSame('account.sentry.test', $context['host']);
            $this->assertSame(123, $context['project']);
            $this->assertStringNotContainsString('secret upstream detail', $message.json_encode($context));

            return true;
        });
    }

    #[Test]
    public function it_answers_504_when_the_upstream_is_unreachable()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Log::spy();
        $this->fakeExceptionReporting();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@account.sentry.test/123',
        ])->assertStatus(504);

        $this->assertNothingReported();
        Log::shouldHaveReceived('log')->once();
    }

    #[Test]
    public function it_applies_the_configured_timeouts()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);
        config(['sentry-tunnel.timeout' => 7]);
        config(['sentry-tunnel.connect-timeout' => 3]);

        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response();
        });

        $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@account.sentry.test/123',
        ])->assertStatus(200);

        $this->assertSame(7, $seen['timeout']);
        $this->assertSame(3, $seen['connect_timeout']);
    }

    #[Test]
    public function it_encodes_the_dsn_key_as_a_single_query_parameter()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://legit&sentry_client=pwn&extra=1@account.sentry.test/123',
        ]);

        $response->assertStatus(200);

        Http::assertSent(function (Request $request) {
            $this->assertEquals('https://account.sentry.test/api/123/envelope/?sentry_key=legit%26sentry_client%3Dpwn%26extra%3D1', $request->url());

            return true;
        });
    }

    #[Test]
    public function it_transmits_an_unusual_key_intact()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://key with space%2F@account.sentry.test/123',
        ]);

        $response->assertStatus(200);

        Http::assertSent(function (Request $request) {
            $this->assertStringStartsWith('https://account.sentry.test/api/123/envelope/?sentry_key=', $request->url());
            $this->assertEquals('key with space%2F', urldecode(Str::after($request->url(), 'sentry_key=')));

            return true;
        });
    }

    #[Test]
    public function it_fails_if_empty_user()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://@account.sentry.test/123',
        ]);

        $response->assertStatus(401);
        $response->assertSee('no user');

        Http::assertNothingSent();
    }

    public static function malformedBodyProvider(): array
    {
        return [
            'empty body' => [''],
            'blank body' => ["   \n  "],
            'not json' => ['this is not json'],
            'truncated json' => ['{"dsn":'],
        ];
    }

    #[Test]
    #[DataProvider('malformedBodyProvider')]
    public function it_fails_on_malformed_body(string $body)
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);

        $this->fakeExceptionReporting();
        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->call('POST', '/sentry/tunnel', content: $body);

        $response->assertStatus(422);

        Http::assertNothingSent();
        $this->assertNothingReported();
    }

    public static function badDsnProvider(): array
    {
        return [
            'array' => [['a' => 'b']],
            'integer' => [123],
            'boolean' => [true],
            'unparseable' => ['http:///'],
        ];
    }

    #[Test]
    #[DataProvider('badDsnProvider')]
    public function it_fails_on_invalid_dsn(mixed $dsn)
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);

        $this->fakeExceptionReporting();
        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->postJson('/sentry/tunnel', ['dsn' => $dsn]);

        $response->assertStatus(422);

        Http::assertNothingSent();
        $this->assertNothingReported();
    }

    #[Test]
    public function it_does_not_trigger_a_deprecation_when_the_dsn_has_no_path()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $response = null;
        $deprecations = $this->captureDeprecations(function () use (&$response) {
            $response = $this->postJson('/sentry/tunnel', [
                'dsn' => 'https://user@account.sentry.test',
            ]);
        });

        $response->assertStatus(422);
        $response->assertSee('no project');

        $this->assertSame([], $deprecations);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_accepts_an_allowed_host_written_with_spaces()
    {
        config(['sentry-tunnel.allowed-hosts' => explode(',', 'a.sentry.test, account.sentry.test')]);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@account.sentry.test/123',
        ]);

        $response->assertStatus(200);
    }

    #[Test]
    public function it_compares_the_host_without_regard_to_case()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@ACCOUNT.SENTRY.TEST/123',
        ]);

        $response->assertStatus(200);
    }

    #[Test]
    public function it_fails_closed_when_the_host_allowlist_is_empty()
    {
        config(['sentry-tunnel.allowed-hosts' => '']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@account.sentry.test/123',
        ]);

        $response->assertStatus(401);
        $response->assertSee('invalid host');

        Http::assertNothingSent();
    }

    #[Test]
    public function it_still_refuses_a_host_absent_from_the_allowlist()
    {
        config(['sentry-tunnel.allowed-hosts' => explode(',', 'a.sentry.test, account.sentry.test')]);

        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@evil.test/123',
        ]);

        $response->assertStatus(401);
        $response->assertSee('invalid host');

        Http::assertNothingSent();
    }

    #[Test]
    public function it_refuses_a_payload_over_the_ceiling()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);
        config(['sentry-tunnel.max-payload-size' => 32]);

        $this->fakeExceptionReporting();
        Http::fake([
            '*' => Http::response(),
        ]);

        $body = json_encode(['dsn' => 'https://user@account.sentry.test/123']);

        $response = $this->call('POST', '/sentry/tunnel', content: $body);

        $response->assertStatus(413);

        Http::assertNothingSent();
        $this->assertNothingReported();
    }

    #[Test]
    public function it_accepts_a_payload_when_the_ceiling_is_disabled()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);
        config(['sentry-tunnel.max-payload-size' => null]);

        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->call('POST', '/sentry/tunnel', content: json_encode([
            'dsn' => 'https://user@account.sentry.test/123',
        ]));

        $response->assertStatus(200);
    }

    #[Test]
    public function it_does_not_reimpose_the_default_middleware_when_overridden()
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => str_contains($route->uri(), 'sentry/tunnel'));

        $this->assertNotNull($route);
        $this->assertNotContains('throttle:300,1', $route->gatherMiddleware());
    }

    #[Test]
    public function it_honours_an_overridden_log_level()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);
        config(['sentry-tunnel.log-level' => 'error']);

        Log::spy();
        Http::fake([
            '*' => Http::response('nope', 503),
        ]);

        $this->postJson('/sentry/tunnel', [
            'dsn' => 'https://user@account.sentry.test/123',
        ])->assertStatus(503);

        Log::shouldHaveReceived('log')->once()->withArgs(function ($level) {
            $this->assertSame('error', $level);

            return true;
        });
    }

    #[Test]
    public function it_fails_if_no_dsn()
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);

        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->postJson('/sentry/tunnel', ['something' => 'else']);

        $response->assertStatus(422);
        $response->assertSee('no dsn');

        Http::assertNothingSent();
    }

    public static function hostileInputProvider(): array
    {
        return [
            'no host' => ['https://user@/123'],
            'scheme only' => ['https://'],
            'empty string' => [''],
            'control characters' => ["https://user@account.sentry.test/123\r\nX: y"],
            'newline in key' => ["https://us\ner@account.sentry.test/123"],
            'very long key' => ['https://'.str_repeat('a', 5000).'@account.sentry.test/123'],
        ];
    }

    #[Test]
    #[DataProvider('hostileInputProvider')]
    public function it_never_answers_with_a_server_error(string $dsn)
    {
        config(['sentry-tunnel.allowed-hosts' => 'account.sentry.test']);
        config(['sentry-tunnel.allowed-projects' => '123']);

        $this->fakeExceptionReporting();
        Http::fake([
            '*' => Http::response(),
        ]);

        $response = $this->postJson('/sentry/tunnel', ['dsn' => $dsn]);

        $this->assertLessThan(500, $response->getStatusCode(), "DSN {$dsn} produced a server error");
        $this->assertNothingReported();
    }
}
