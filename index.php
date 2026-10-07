<?php

use Kirby\Cms\App;
use Kirby\Content\Field;
use Kirby\Exception\PermissionException;
use Kirby\Http\Response;
use CircusCirculi\VideoOptimizer\Client;
use CircusCirculi\VideoOptimizer\Exception\ApiException;
use CircusCirculi\VideoOptimizer\Exception\ConfigurationException;
use CircusCirculi\VideoOptimizer\Plugin;
use CircusCirculi\VideoOptimizer\Video;

load([
    'CircusCirculi\\VideoOptimizer\\Client'                            => 'src/Client.php',
    'CircusCirculi\\VideoOptimizer\\Plugin'                            => 'src/Plugin.php',
    'CircusCirculi\\VideoOptimizer\\Video'                             => 'src/Video.php',
    'CircusCirculi\\VideoOptimizer\\Exception\\ApiException'           => 'src/Exception/ApiException.php',
    'CircusCirculi\\VideoOptimizer\\Exception\\ConfigurationException' => 'src/Exception/ConfigurationException.php',
], __DIR__);

/**
 * Runs an API action and turns plugin errors into Panel error responses
 */
$respond = function (Closure $action) {
    try {
        return ['data' => $action(Plugin::client())];
    } catch (ConfigurationException $e) {
        return Response::json(['status' => 'error', 'code' => 400, 'message' => $e->getMessage()], 400);
    } catch (ApiException $e) {
        $code = $e->status() >= 400 && $e->status() < 600 ? $e->status() : 502;
        return Response::json(['status' => 'error', 'code' => $code, 'message' => $e->getMessage()], $code);
    }
};

/**
 * Only Panel users who may edit pages can manage videos
 */
$authorize = function (App $kirby): void {
    if ($kirby->user()?->role()->permissions()->for('pages', 'update') !== true) {
        throw new PermissionException(message: t('videooptimizer.permission'));
    }
};

App::plugin('circus-circuli/videooptimizer', [
    'options' => [
        // API token (vp_...). Leave empty to read the VIDEOOPTIMIZER_API_TOKEN environment variable.
        'token'     => null,
        'apiUrl'    => Client::DEFAULT_API_URL,
        'embedUrl'  => Client::DEFAULT_EMBED_URL,
        // Library preselected for uploads
        'library'   => null,
        'cache'     => true,
    ],

    'blueprints' => [
        'blocks/videooptimizer' => __DIR__ . '/blueprints/blocks/videooptimizer.yml',
    ],

    'snippets' => [
        'blocks/videooptimizer' => __DIR__ . '/snippets/blocks/videooptimizer.php',
        'videooptimizer/video'  => __DIR__ . '/snippets/videooptimizer/video.php',
    ],

    'fields' => [
        'videooptimizer' => [
            'props' => [
                'value'   => fn (?string $value = null) => $value,
                // Library preselected for uploads in this field
                'library' => fn (?string $library = null) => $library ?? option('circus-circuli.videooptimizer.library'),
            ],
            'computed' => [
                'configured' => fn () => Plugin::token() !== null,
            ],
        ],
    ],

    'fieldMethods' => [
        /**
         * Resolves a VideoOptimizer field to a playable video or null
         */
        'toVideoOptimizerVideo' => fn (Field $field): ?Video => Video::find((string)$field->value()),
    ],

    'api' => [
        'routes' => fn (App $kirby) => [
            [
                'pattern' => 'videooptimizer/libraries',
                'method'  => 'GET',
                'action'  => function () use ($kirby, $authorize, $respond) {
                    $authorize($kirby);
                    return $respond(fn (Client $client) => $client->libraries());
                },
            ],
            [
                'pattern' => 'videooptimizer/videos',
                'method'  => 'GET',
                'action'  => function () use ($kirby, $authorize, $respond) {
                    $authorize($kirby);
                    $library = $kirby->request()->get('library');
                    return $respond(fn (Client $client) => $client->videos(is_string($library) && $library !== '' ? $library : null));
                },
            ],
            [
                'pattern' => 'videooptimizer/videos/(:any)',
                'method'  => 'GET',
                'action'  => function (string $uuid) use ($kirby, $authorize, $respond) {
                    $authorize($kirby);
                    return $respond(function (Client $client) use ($uuid) {
                        $video = $client->video($uuid);

                        // The status changed, so the next page view fetches fresh player data
                        if (($video['status'] ?? null) !== 'processing') {
                            $client->forgetEmbed($uuid);
                        }

                        return $video;
                    });
                },
            ],
            [
                'pattern' => 'videooptimizer/upload/initiate',
                'method'  => 'POST',
                'action'  => function () use ($kirby, $authorize, $respond) {
                    $authorize($kirby);
                    $body = $kirby->request()->body();

                    return $respond(fn (Client $client) => $client->initiateUpload(
                        (string)$body->get('libraryId'),
                        (string)$body->get('filename'),
                        (string)$body->get('contentType'),
                        (int)$body->get('fileSize'),
                    ));
                },
            ],
            [
                'pattern' => 'videooptimizer/upload/complete',
                'method'  => 'POST',
                'action'  => function () use ($kirby, $authorize, $respond) {
                    $authorize($kirby);
                    $body  = $kirby->request()->body();
                    $parts = array_values(array_filter(array_map(
                        fn ($part) => is_array($part) && isset($part['partNumber'], $part['etag'])
                            ? ['partNumber' => (int)$part['partNumber'], 'etag' => (string)$part['etag']]
                            : null,
                        (array)$body->get('parts')
                    )));

                    return $respond(fn (Client $client) => $client->completeUpload(
                        (string)$body->get('libraryId'),
                        (string)$body->get('uuid'),
                        (string)$body->get('key'),
                        (string)$body->get('uploadId'),
                        $parts,
                        $body->get('title') ? (string)$body->get('title') : null,
                    ));
                },
            ],
            [
                'pattern' => 'videooptimizer/import',
                'method'  => 'POST',
                'action'  => function () use ($kirby, $authorize, $respond) {
                    $authorize($kirby);
                    $body = $kirby->request()->body();
                    $url  = (string)$body->get('sourceUrl');

                    if (str_starts_with(strtolower($url), 'https://') === false || parse_url($url, PHP_URL_HOST) === null) {
                        return Response::json(['status' => 'error', 'code' => 400, 'message' => t('videooptimizer.import.invalid')], 400);
                    }

                    return $respond(fn (Client $client) => $client->importVideo(
                        (string)$body->get('libraryId'),
                        $url,
                        $body->get('title') ? (string)$body->get('title') : null,
                    ));
                },
            ],
        ],
    ],

    'translations' => [
        'de' => require __DIR__ . '/translations/de.php',
        'en' => require __DIR__ . '/translations/en.php',
    ],
]);
