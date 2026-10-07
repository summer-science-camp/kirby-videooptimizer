<?php

use CircusCirculi\VideoOptimizer\Client;
use CircusCirculi\VideoOptimizer\Plugin;
use Kirby\Cms\App;

/**
 * Panel API routes. They forward requests to VideoOptimizer with the token,
 * which never leaves the server.
 */
return function (App $kirby) {
    $body = fn () => $kirby->request()->body();

    // Codecs and resolutions arrive as lists from the Panel checkboxes
    $ladder = fn (mixed $value): ?string => is_array($value)
        ? implode(',', array_filter(array_map('strval', $value)))
        : (is_string($value) ? $value : null);

    $library = fn (bool $create) => array_filter([
        'name'        => trim((string)$body()->get('name')),
        'description' => (string)$body()->get('description'),
        'codec'       => $ladder($body()->get('codec')),
        'resolutions' => $ladder($body()->get('resolutions')),
    ], fn ($value, $key) => $value !== null && ($value !== '' || ($create === false && $key === 'description')), ARRAY_FILTER_USE_BOTH);

    return [
        // Libraries

        [
            'pattern' => 'videooptimizer/libraries',
            'method'  => 'GET',
            'action'  => fn () => Plugin::respond(fn (Client $client) => $client->libraries()),
        ],
        [
            'pattern' => 'videooptimizer/libraries',
            'method'  => 'POST',
            'action'  => function () use ($library) {
                $data = $library(true);

                if (($data['name'] ?? '') === '') {
                    return Plugin::error(t('videooptimizer.library.name.required'));
                }

                return Plugin::respond(fn (Client $client) => $client->createLibrary($data));
            },
        ],
        [
            'pattern' => 'videooptimizer/libraries/(:any)',
            'method'  => 'PATCH',
            'action'  => fn (string $id) => Plugin::respond(fn (Client $client) => $client->updateLibrary($id, $library(false))),
        ],
        [
            'pattern' => 'videooptimizer/libraries/(:any)',
            'method'  => 'DELETE',
            'action'  => fn (string $id) => Plugin::respond(fn (Client $client) => $client->deleteLibrary($id)),
        ],
        [
            'pattern' => 'videooptimizer/libraries/(:any)/reprocess',
            'method'  => 'POST',
            'action'  => fn (string $id) => Plugin::respond(fn (Client $client) => $client->reprocessLibrary($id)),
        ],

        // Videos

        [
            'pattern' => 'videooptimizer/videos',
            'method'  => 'GET',
            'action'  => function () use ($kirby) {
                $library = $kirby->request()->get('library');
                return Plugin::respond(fn (Client $client) => $client->videos(is_string($library) && $library !== '' ? $library : null));
            },
        ],
        [
            'pattern' => 'videooptimizer/videos/(:any)',
            'method'  => 'GET',
            'action'  => fn (string $uuid) => Plugin::respond(function (Client $client) use ($uuid) {
                $video = $client->video($uuid);

                // The status changed, so the next page view fetches fresh player data
                if (($video['status'] ?? null) !== 'processing') {
                    $client->forgetEmbed($uuid);
                }

                return $video;
            }),
        ],
        [
            'pattern' => 'videooptimizer/videos/(:any)',
            'method'  => 'PATCH',
            'action'  => function (string $uuid) use ($body) {
                $title = trim((string)$body()->get('title'));

                if ($title === '') {
                    return Plugin::error(t('videooptimizer.title.required'));
                }

                return Plugin::respond(fn (Client $client) => $client->renameVideo($uuid, $title));
            },
        ],
        [
            'pattern' => 'videooptimizer/videos/(:any)',
            'method'  => 'DELETE',
            'action'  => fn (string $uuid) => Plugin::respond(fn (Client $client) => $client->deleteVideo($uuid)),
        ],
        [
            'pattern' => 'videooptimizer/videos/(:any)/thumbnail',
            'method'  => 'POST',
            'action'  => function (string $uuid) use ($body) {
                $index = (int)$body()->get('index');

                if ($index < 0 || $index > 9) {
                    return Plugin::error('Invalid thumbnail index.');
                }

                return Plugin::respond(fn (Client $client) => $client->selectThumbnail($uuid, $index));
            },
        ],
        [
            'pattern' => 'videooptimizer/videos/(:any)/poster/initiate',
            'method'  => 'POST',
            'action'  => function (string $uuid) use ($body) {
                $type = (string)$body()->get('contentType');

                if (in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true) === false) {
                    return Plugin::error(t('videooptimizer.poster.type'));
                }

                return Plugin::respond(fn (Client $client) => $client->initiatePosterUpload($uuid, $type, (int)$body()->get('fileSize')));
            },
        ],
        [
            'pattern' => 'videooptimizer/videos/(:any)/poster/complete',
            'method'  => 'POST',
            'action'  => fn (string $uuid) => Plugin::respond(fn (Client $client) => $client->completePosterUpload($uuid, (string)$body()->get('key'))),
        ],
        [
            'pattern' => 'videooptimizer/videos/(:any)/poster/select',
            'method'  => 'POST',
            'action'  => fn (string $uuid) => Plugin::respond(fn (Client $client) => $client->selectPoster(
                $uuid,
                $body()->get('source') === 'custom' ? 'custom' : 'thumbnail'
            )),
        ],
        [
            'pattern' => 'videooptimizer/videos/(:any)/poster',
            'method'  => 'DELETE',
            'action'  => fn (string $uuid) => Plugin::respond(fn (Client $client) => $client->deletePoster($uuid)),
        ],

        // Uploads

        [
            'pattern' => 'videooptimizer/upload/initiate',
            'method'  => 'POST',
            'action'  => fn () => Plugin::respond(fn (Client $client) => $client->initiateUpload(
                (string)$body()->get('libraryId'),
                (string)$body()->get('filename'),
                (string)$body()->get('contentType'),
                (int)$body()->get('fileSize'),
            )),
        ],
        [
            'pattern' => 'videooptimizer/upload/complete',
            'method'  => 'POST',
            'action'  => function () use ($body) {
                $parts = array_values(array_filter(array_map(
                    fn ($part) => is_array($part) && isset($part['partNumber'], $part['etag'])
                        ? ['partNumber' => (int)$part['partNumber'], 'etag' => (string)$part['etag']]
                        : null,
                    (array)$body()->get('parts')
                )));

                return Plugin::respond(fn (Client $client) => $client->completeUpload(
                    (string)$body()->get('libraryId'),
                    (string)$body()->get('uuid'),
                    (string)$body()->get('key'),
                    (string)$body()->get('uploadId'),
                    $parts,
                    $body()->get('title') ? (string)$body()->get('title') : null,
                ));
            },
        ],
        [
            'pattern' => 'videooptimizer/import',
            'method'  => 'POST',
            'action'  => function () use ($body) {
                $url = (string)$body()->get('sourceUrl');

                if (str_starts_with(strtolower($url), 'https://') === false || parse_url($url, PHP_URL_HOST) === null) {
                    return Plugin::error(t('videooptimizer.import.invalid'));
                }

                return Plugin::respond(fn (Client $client) => $client->importVideo(
                    (string)$body()->get('libraryId'),
                    $url,
                    $body()->get('title') ? (string)$body()->get('title') : null,
                ));
            },
        ],
    ];
};
