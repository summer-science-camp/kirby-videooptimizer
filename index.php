<?php

use CircusCirculi\VideoOptimizer\Client;
use CircusCirculi\VideoOptimizer\Plugin;
use CircusCirculi\VideoOptimizer\Video;
use Kirby\Cms\App;
use Kirby\Content\Field;

load([
    'CircusCirculi\\VideoOptimizer\\Client'                            => 'src/Client.php',
    'CircusCirculi\\VideoOptimizer\\Plugin'                            => 'src/Plugin.php',
    'CircusCirculi\\VideoOptimizer\\Video'                             => 'src/Video.php',
    'CircusCirculi\\VideoOptimizer\\Exception\\ApiException'           => 'src/Exception/ApiException.php',
    'CircusCirculi\\VideoOptimizer\\Exception\\ConfigurationException' => 'src/Exception/ConfigurationException.php',
], __DIR__);

App::plugin('circus-circuli/videooptimizer', [
    'options' => [
        // API token (vp_...). Leave empty to read the VIDEOOPTIMIZER_API_TOKEN environment variable.
        'token'    => null,
        'apiUrl'   => Client::DEFAULT_API_URL,
        'embedUrl' => Client::DEFAULT_EMBED_URL,
        // Library preselected for uploads
        'library'  => null,
        'cache'    => true,
    ],

    'areas' => require __DIR__ . '/config/areas.php',

    'api' => [
        'routes' => require __DIR__ . '/config/api.php',
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

    'translations' => [
        'de' => require __DIR__ . '/translations/de.php',
        'en' => require __DIR__ . '/translations/en.php',
    ],
]);
