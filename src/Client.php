<?php

namespace SummerScienceCamp\VideoOptimizer;

use SummerScienceCamp\VideoOptimizer\Exception\ApiException;
use SummerScienceCamp\VideoOptimizer\Exception\ConfigurationException;
use Kirby\Cache\Cache;
use Kirby\Http\Remote;
use Throwable;

/**
 * Server-side client for the VideoOptimizer REST API.
 *
 * The API token only ever lives on the server: the Panel talks to the plugin's
 * own API routes, which forward the request with the token attached.
 *
 * @see https://videooptimizer.eu/docs/developers
 */
class Client
{
    public const DEFAULT_API_URL   = 'https://api.videooptimizer.eu/api/v1';
    public const DEFAULT_EMBED_URL = 'https://videooptimizer.eu';

    private const PAGE_LIMIT         = 100;
    private const MAX_PAGES          = 100;
    private const TIMEOUT            = 30;
    private const EMBED_TIMEOUT      = 3;
    private const EMBED_TTL          = 60;   // minutes
    private const EMBED_FAILURE_TTL  = 1;    // minutes
    private const EMBED_NOT_READY_TTL = 1;   // minutes

    public function __construct(
        private readonly ?string $token,
        private readonly string $apiUrl = self::DEFAULT_API_URL,
        private readonly string $embedUrl = self::DEFAULT_EMBED_URL,
        private readonly ?Cache $cache = null,
    ) {
    }

    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    // Libraries

    public function libraries(): array
    {
        return $this->allPages('/libraries');
    }

    // Videos

    /**
     * Videos of all libraries, newest first, optionally limited to one library
     */
    public function videos(?string $libraryId = null): array
    {
        $query = $libraryId ? ['library_id' => $libraryId] : [];
        return $this->allPages('/videos', $query);
    }

    public function video(string $uuid): array
    {
        return $this->data('GET', '/videos/' . rawurlencode($uuid));
    }

    /**
     * Imports a video from a public https URL. Returns immediately with
     * status `processing`.
     */
    public function importVideo(string $libraryId, string $sourceUrl, ?string $title = null): array
    {
        return $this->data('POST', '/videos', array_filter([
            'library_id' => $libraryId,
            'source_url' => $sourceUrl,
            'title'      => $title,
        ]));
    }

    /**
     * Starts a presigned multipart upload. The browser uploads the parts
     * straight to storage, the file never passes through Kirby.
     */
    public function initiateUpload(string $libraryId, string $filename, string $contentType, int $fileSize): array
    {
        return $this->data('POST', '/videos/upload/initiate', [
            'libraryId'   => $libraryId,
            'filename'    => $filename,
            'contentType' => $contentType,
            'fileSize'    => $fileSize,
        ]);
    }

    /**
     * @param list<array{partNumber: int, etag: string}> $parts
     */
    public function completeUpload(string $libraryId, string $uuid, string $key, string $uploadId, array $parts, ?string $title = null): array
    {
        return $this->data('POST', '/videos/upload/complete', array_filter([
            'libraryId' => $libraryId,
            'uuid'      => $uuid,
            'key'       => $key,
            'uploadId'  => $uploadId,
            'parts'     => $parts,
            'title'     => $title,
        ], fn ($value) => $value !== null));
    }

    // Embed (public, no token)

    /**
     * Player configuration of a video: sources, poster and metadata.
     *
     * Cached per video, so rendering a page does not hit the API on every
     * request. Failures and videos that are still processing are cached
     * briefly, so an unreachable API cannot slow down every page view.
     *
     * @throws ApiException
     */
    public function embed(string $uuid): array
    {
        $key    = 'embed.' . md5($uuid);
        $cached = $this->cache?->get($key);

        if (is_array($cached)) {
            if (($cached['failed'] ?? false) === true) {
                throw new ApiException(503, 'VideoOptimizer embed temporarily unavailable (cached failure).');
            }

            return $cached;
        }

        try {
            $embed = $this->data('GET', '/embed/' . rawurlencode($uuid), null, false, self::EMBED_TIMEOUT);
        } catch (Throwable $e) {
            $this->cache?->set($key, ['failed' => true], self::EMBED_FAILURE_TTL);
            throw $e instanceof ApiException ? $e : new ApiException(503, $e->getMessage());
        }

        $ready = ($embed['sources'] ?? []) !== [];
        $this->cache?->set($key, $embed, $ready ? self::EMBED_TTL : self::EMBED_NOT_READY_TTL);

        return $embed;
    }

    public function forgetEmbed(string $uuid): void
    {
        $this->cache?->remove('embed.' . md5($uuid));
    }

    /**
     * Hosted iframe player URL with playback options
     */
    public function embedPlayerUrl(string $uuid, array $options = []): string
    {
        $query = http_build_query([
            'autoplay' => ($options['autoplay'] ?? false) ? '1' : '0',
            'muted'    => ($options['muted'] ?? false) ? '1' : '0',
            'loop'     => ($options['loop'] ?? false) ? '1' : '0',
            'controls' => ($options['controls'] ?? true) ? '1' : '0',
        ]);

        return $this->assertHttps($this->embedUrl, 'embed') . '/embed/' . rawurlencode($uuid) . '?' . $query;
    }

    // HTTP

    /**
     * Fetches all pages of a cursor paginated list endpoint
     */
    private function allPages(string $path, array $query = []): array
    {
        $items  = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $params   = $query + ['limit' => self::PAGE_LIMIT] + ($cursor ? ['cursor' => $cursor] : []);
            $response = $this->request('GET', $path . '?' . http_build_query($params));

            foreach ($response['data'] ?? [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $next = $response['pagination']['next_cursor'] ?? null;

            // Stop on the last page or a stuck cursor
            if (($response['pagination']['has_more'] ?? false) !== true || !is_string($next) || $next === '' || $next === $cursor) {
                break;
            }

            $cursor = $next;
        }

        return $items;
    }

    private function data(string $method, string $path, ?array $body = null, bool $auth = true, int $timeout = self::TIMEOUT): array
    {
        $data = $this->request($method, $path, $body, $auth, $timeout)['data'] ?? null;
        return is_array($data) ? $data : [];
    }

    /**
     * @throws ApiException|ConfigurationException
     */
    private function request(string $method, string $path, ?array $body = null, bool $auth = true, int $timeout = self::TIMEOUT): array
    {
        $headers = ['Accept: application/json'];

        if ($auth === true) {
            if ($this->hasToken() === false) {
                throw new ConfigurationException('No VideoOptimizer API token configured.');
            }

            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $url     = $this->assertHttps($this->apiUrl, 'API') . $path;
        $payload = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;
        $result  = $this->send($method, $url, $headers, $payload, $timeout);

        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw ApiException::fromResponse($result['status'], $result['body']);
        }

        if ($result['body'] === '') {
            return [];
        }

        $decoded = json_decode($result['body'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        try {
            $response = Remote::request($url, [
                'method'  => $method,
                'headers' => $headers,
                'data'    => $body ?? [],
                'timeout' => $timeout,
            ]);
        } catch (Throwable $e) {
            throw new ApiException(503, 'VideoOptimizer is not reachable: ' . $e->getMessage());
        }

        return ['status' => $response->code() ?? 0, 'body' => $response->content() ?? ''];
    }

    private function assertHttps(string $url, string $name): string
    {
        $parts = parse_url($url);

        if (strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new ConfigurationException('The VideoOptimizer ' . $name . ' URL must be an absolute https URL.');
        }

        return rtrim($url, '/');
    }
}
