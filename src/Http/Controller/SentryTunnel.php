<?php

namespace SentryTunnel\Http\Controller;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Safe\Exceptions\UrlException;

use function Safe\json_decode;
use function Safe\parse_url;

/**
 * @psalm-suppress UnusedClass
 */
class SentryTunnel extends Controller
{
    /**
     * Tunnel the request to Sentry.
     */
    public function tunnel(Request $request): Response
    {
        $this->checkPayloadSize($request);

        $envelope = $request->getContent();
        abort_if(trim($envelope) === '', 422, 'empty envelope');

        abort_if($this->exceedsMaxPayloadSize(strlen($envelope)), 413, 'payload too large');

        $pieces = explode("\n", $envelope, 2);

        try {
            $header = json_decode($pieces[0], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(422, 'invalid envelope header');
        }

        [$user, $host, $projectId] = $this->parseDsn($header);

        $this->checkProjectId($projectId);

        return $this->relay($envelope, $user, $host, $projectId);
    }

    /**
     * Send the envelope to Sentry and answer the caller with what Sentry said.
     *
     * An upstream failure is relayed, not thrown: an unhandled exception here
     * would be reported by the host application to the very Sentry instance
     * that is already failing.
     */
    private function relay(string $envelope, string $user, string $host, int $projectId): Response
    {
        try {
            $response = Http::withBody($envelope, 'application/x-sentry-envelope')
                ->withQueryParameters(['sentry_key' => $user])
                ->connectTimeout(config('sentry-tunnel.connect-timeout'))
                ->timeout(config('sentry-tunnel.timeout'))
                ->post("https://$host/api/$projectId/envelope/");
        } catch (ConnectionException) {
            $this->logFailure($host, $projectId, null);

            return response('Sentry could not be reached.', 504);
        }

        if ($response->failed()) {
            $this->logFailure($host, $projectId, $response->status());

            // The upstream body is not disclosed: it is Sentry's error text,
            // which the caller has no need for and may not be shown.
            return response('Sentry rejected the envelope.', $response->status(), $this->relayedHeaders($response));
        }

        return response($response->body(), $response->status(), $this->relayedHeaders($response, true));
    }

    /**
     * The upstream headers the caller is allowed to see.
     *
     * This is an allowlist rather than a denylist, so a header Sentry adds in
     * future is dropped by default instead of relayed by accident. The content
     * type is relayed only alongside the upstream body; on the error path the
     * body is ours, so the type must describe ours.
     *
     * @return array<string, string>
     */
    private function relayedHeaders(ClientResponse $response, bool $withContentType = false): array
    {
        $relayed = ['retry-after', 'x-sentry-rate-limits'];

        if ($withContentType) {
            $relayed[] = 'content-type';
        }

        $headers = [];

        /** @var array<string, array<int, string>> $upstream */
        $upstream = $response->headers();

        foreach ($upstream as $name => $values) {
            if (in_array(strtolower($name), $relayed, true) && $values !== []) {
                $headers[$name] = $values[0];
            }
        }

        return $headers;
    }

    /**
     * Record that the tunnel could not deliver, without the upstream response
     * text and without the envelope, which may carry end-user data.
     */
    private function logFailure(string $host, int $projectId, ?int $status): void
    {
        Log::log(config('sentry-tunnel.log-level', 'warning'), 'Sentry tunnel: the upstream request failed.', [
            'status' => $status,
            'host' => $host,
            'project' => $projectId,
        ]);
    }

    /**
     * Refuse an oversized envelope from Content-Length, before the body is read.
     */
    private function checkPayloadSize(Request $request): void
    {
        $length = $request->header('Content-Length');

        abort_if($length !== null && $this->exceedsMaxPayloadSize((int) $length), 413, 'payload too large');
    }

    /**
     * Whether a length is over the configured ceiling. A null ceiling disables it.
     */
    private function exceedsMaxPayloadSize(int $length): bool
    {
        $max = config('sentry-tunnel.max-payload-size');

        return $max !== null && $length > (int) $max;
    }

    /**
     * parse the dsn will all controls.
     */
    private function parseDsn(mixed $header): array
    {
        abort_if(($dsn = data_get($header, 'dsn')) === null, 422, 'no dsn');
        abort_if(! is_string($dsn), 422, 'invalid dsn');

        try {
            $user = parse_url($dsn, PHP_URL_USER);
            $host = parse_url($dsn, PHP_URL_HOST);
            $path = parse_url($dsn, PHP_URL_PATH);
        } catch (UrlException) {
            abort(422, 'invalid dsn');
        }

        abort_if($user === null || $user === '', 401, 'no user');
        abort_if($host === null, 401, 'no host');

        $host = strtolower($host);
        abort_if(! in_array($host, $this->allowedHosts(), true), 401, 'invalid host');

        $path = trim((string) $path, '/');
        abort_if(($projectId = intval($path)) === 0, 422, 'no project');

        return [$user, $host, $projectId];
    }

    /**
     * Get the allowed hosts.
     */
    private function allowedHosts(): array
    {
        return $this->entries('sentry-tunnel.allowed-hosts', strtolower(...));
    }

    /**
     * Get the allowed projects.
     */
    private function allowedProjects(): array
    {
        return array_map('intval', $this->entries('sentry-tunnel.allowed-projects'));
    }

    /**
     * Read a configured list, trimming each entry and discarding the blank ones.
     *
     * @return array<int, string>
     */
    private function entries(string $key, ?callable $normalize = null): array
    {
        $entries = array_map(
            static fn (mixed $entry): string => trim((string) $entry),
            Arr::flatten([config($key, [])])
        );

        if ($normalize !== null) {
            $entries = array_map($normalize, $entries);
        }

        return array_values(array_filter(
            $entries,
            static fn (string $entry): bool => $entry !== ''
        ));
    }

    /**
     * Check the projectId.
     */
    private function checkProjectId(int $projectId): void
    {
        $allowedProjects = $this->allowedProjects();

        abort_if(count($allowedProjects) > 0 && ! in_array($projectId, $allowedProjects, true), 401, 'invalid project');
    }
}
