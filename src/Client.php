<?php

namespace CircusCirculi\VideoOptimizer;

use CircusCirculi\VideoOptimizer\Exception\ApiException;
use CircusCirculi\VideoOptimizer\Exception\ConfigurationException;
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

    public function library(string $id): array
    {
        return $this->data('GET', '/libraries/' . rawurlencode($id));
    }

    /**
     * @param array{name?: string, description?: string, codec?: string, resolutions?: string} $data
     */
    public function createLibrary(array $data): array
    {
        return $this->data('POST', '/libraries', $data);
    }

    public function updateLibrary(string $id, array $data): array
    {
        return $this->data('PATCH', '/libraries/' . rawurlencode($id), $data);
    }

    /**
     * Deletes the library with all its videos
     */
    public function deleteLibrary(string $id): void
    {
        $this->request('DELETE', '/libraries/' . rawurlencode($id));
    }

    /**
     * Encodes all videos of the library again with its current encoding ladder
     */
    public function reprocessLibrary(string $id): array
    {
        return $this->data('POST', '/libraries/' . rawurlencode($id) . '/reprocess');
    }

    /**
     * Codecs and resolutions the organization may enable on a library.
     * Not wrapped in `data`.
     */
    public function encodings(): array
    {
        return $this->request('GET', '/encodings');
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

    public function renameVideo(string $uuid, string $title): array
    {
        $video = $this->data('PATCH', '/videos/' . rawurlencode($uuid), ['title' => $title]);
        $this->forgetEmbed($uuid);

        return $video;
    }

    public function deleteVideo(string $uuid): void
    {
        $this->request('DELETE', '/videos/' . rawurlencode($uuid));
        $this->forgetEmbed($uuid);
    }

    /**
     * The 10 auto-generated frames to choose the poster from
     *
     * @return list<array{index: int, url: string}>
     */
    public function thumbnails(string $uuid): array
    {
        return $this->data('GET', '/videos/' . rawurlencode($uuid) . '/thumbnails')['thumbnails'] ?? [];
    }

    /**
     * Uses one of the auto-generated frames as poster
     */
    public function selectThumbnail(string $uuid, int $index): array
    {
        $result = $this->data('POST', '/videos/' . rawurlencode($uuid) . '/thumbnail', ['thumbnailIndex' => $index]);
        $this->forgetEmbed($uuid);

        return $result;
    }

    /**
     * Starts a custom poster upload, returns a presigned PUT URL
     */
    public function initiatePosterUpload(string $uuid, string $contentType, int $fileSize): array
    {
        return $this->data('POST', '/videos/' . rawurlencode($uuid) . '/poster/initiate', [
            'contentType' => $contentType,
            'fileSize'    => $fileSize,
        ]);
    }

    public function completePosterUpload(string $uuid, string $key): array
    {
        $result = $this->data('POST', '/videos/' . rawurlencode($uuid) . '/poster/complete', ['key' => $key]);
        $this->forgetEmbed($uuid);

        return $result;
    }

    /**
     * Switches the active poster between the custom upload and the frame
     */
    public function selectPoster(string $uuid, string $source): array
    {
        $result = $this->data('POST', '/videos/' . rawurlencode($uuid) . '/poster/select', ['source' => $source]);
        $this->forgetEmbed($uuid);

        return $result;
    }

    public function deletePoster(string $uuid): void
    {
        $this->request('DELETE', '/videos/' . rawurlencode($uuid) . '/poster');
        $this->forgetEmbed($uuid);
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

    // Subtitles

    /**
     * Subtitle languages of the organization, in the order of the player menu
     *
     * @return list<array{code: string, label: string, video_count?: int}>
     */
    public function subtitleLanguages(): array
    {
        return array_values(array_filter($this->data('GET', '/subtitle-languages'), 'is_array'));
    }

    /**
     * Adds a subtitle language. The BCP 47 code cannot be changed later.
     */
    public function addSubtitleLanguage(string $code, ?string $label = null): array
    {
        return $this->data('POST', '/subtitle-languages', array_filter([
            'code'  => $code,
            'label' => $label,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * One entry per language
     */
    public function subtitles(string $uuid): array
    {
        return array_values(array_filter($this->data('GET', '/videos/' . rawurlencode($uuid) . '/subtitles'), 'is_array'));
    }

    /**
     * The stored WebVTT file of one language
     */
    public function subtitleFile(string $uuid, string $language): string
    {
        return $this->raw('GET', '/videos/' . rawurlencode($uuid) . '/subtitles/' . rawurlencode($language), accept: 'text/vtt, application/json');
    }

    /**
     * Uploads an SRT or WebVTT file for one language and replaces an existing
     * subtitle in that language. `kind` and `isDefault` are kept on replace
     * when null.
     *
     * @return array{subtitle?: array, skipped_cues?: int}
     */
    public function uploadSubtitle(string $uuid, string $language, string $filename, string $content, ?string $kind = null, ?bool $isDefault = null): array
    {
        $fields = array_filter([
            'language'   => $language,
            'kind'       => $kind,
            'is_default' => $isDefault === null ? null : ($isDefault ? 'true' : 'false'),
        ], fn ($value) => $value !== null);

        $boundary = 'videooptimizer-' . bin2hex(random_bytes(12));
        $body     = '';

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n"
                . $value . "\r\n";
        }

        $body .= '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="file"; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"' . "\r\n"
            . 'Content-Type: ' . (str_ends_with(strtolower($filename), '.vtt') ? 'text/vtt' : 'application/x-subrip') . "\r\n\r\n"
            . $content . "\r\n"
            . '--' . $boundary . "--\r\n";

        $result = $this->request('POST', '/videos/' . rawurlencode($uuid) . '/subtitles', $body, contentType: 'multipart/form-data; boundary=' . $boundary)['data'] ?? [];
        $this->forgetEmbed($uuid);

        return is_array($result) ? $result : [];
    }

    /**
     * Changes kind or default flag of a subtitle. The API has no update
     * endpoint, so the stored file is uploaded again with the new values.
     */
    public function updateSubtitle(string $uuid, string $language, ?string $kind = null, ?bool $isDefault = null): array
    {
        $content = $this->subtitleFile($uuid, $language);

        return $this->uploadSubtitle($uuid, $language, $language . '.vtt', $content, $kind, $isDefault);
    }

    public function deleteSubtitle(string $uuid, string $language): void
    {
        $this->request('DELETE', '/videos/' . rawurlencode($uuid) . '/subtitles/' . rawurlencode($language));
        $this->forgetEmbed($uuid);
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
     * JSON request. An array body is sent as JSON, a string body as is with
     * the given content type.
     *
     * @throws ApiException|ConfigurationException
     */
    private function request(string $method, string $path, array|string|null $body = null, bool $auth = true, int $timeout = self::TIMEOUT, string $contentType = 'application/json'): array
    {
        $payload = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;
        $result  = $this->raw($method, $path, $payload, $auth, $timeout, $contentType);

        if ($result === '') {
            return [];
        }

        $decoded = json_decode($result, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Request that returns the response body unparsed
     *
     * @throws ApiException|ConfigurationException
     */
    private function raw(string $method, string $path, ?string $body = null, bool $auth = true, int $timeout = self::TIMEOUT, string $contentType = 'application/json', string $accept = 'application/json'): string
    {
        $headers = ['Accept: ' . $accept];

        if ($auth === true) {
            if ($this->hasToken() === false) {
                throw new ConfigurationException('No VideoOptimizer API token configured.');
            }

            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        if ($body !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        $url    = $this->assertHttps($this->apiUrl, 'API') . $path;
        $result = $this->send($method, $url, $headers, $body, $timeout);

        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw ApiException::fromResponse($result['status'], $result['body']);
        }

        return $result['body'];
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
