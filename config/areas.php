<?php

use CircusCirculi\VideoOptimizer\Exception\ApiException;
use CircusCirculi\VideoOptimizer\Exception\ConfigurationException;
use CircusCirculi\VideoOptimizer\Plugin;
use Kirby\Cms\App;

/**
 * Panel area "Videos": video list, video details and libraries
 */
return [
    'videooptimizer' => function (App $kirby) {
        /**
         * Loads view data and turns API errors into a message for the view
         */
        $load = function (Closure $loader) {
            try {
                return ['error' => null] + $loader(Plugin::client());
            } catch (ConfigurationException|ApiException $e) {
                return ['error' => $e->getMessage()];
            }
        };

        $canManage = fn () => $kirby->user()?->role()->permissions()->for('pages', 'update') === true;

        return [
            'label' => t('videooptimizer.area'),
            'icon'  => 'video',
            'menu'  => fn () => $canManage() ? true : 'disabled',
            'link'  => 'videos',
            'views' => [
                [
                    'pattern' => 'videos',
                    'action'  => function () use ($kirby, $load) {
                        Plugin::authorize();
                        $library = (string)$kirby->request()->get('library');

                        return [
                            'component' => 'k-videooptimizer-videos-view',
                            'title'     => t('videooptimizer.area'),
                            'props'     => [
                                'configured' => Plugin::token() !== null,
                            ] + $load(function ($client) use ($library) {
                                $libraries = $client->libraries();

                                // An unknown library, e.g. from an old link, shows all videos
                                if (in_array($library, array_column($libraries, 'id'), true) === false) {
                                    $library = '';
                                }

                                return [
                                    'libraries' => $libraries,
                                    'videos'    => $client->videos($library !== '' ? $library : null),
                                    'library'   => $library,
                                ];
                            }),
                        ];
                    },
                ],
                [
                    'pattern' => 'videos/libraries',
                    'action'  => function () use ($load) {
                        Plugin::authorize();

                        return [
                            'component'  => 'k-videooptimizer-libraries-view',
                            'title'      => t('videooptimizer.libraries'),
                            'breadcrumb' => [
                                ['label' => t('videooptimizer.libraries'), 'link' => 'videos/libraries'],
                            ],
                            'props'      => [
                                'configured' => Plugin::token() !== null,
                            ] + $load(fn ($client) => [
                                'libraries' => $client->libraries(),
                                'encodings' => $client->encodings(),
                            ]),
                        ];
                    },
                ],
                [
                    'pattern' => 'videos/(:any)',
                    'action'  => function (string $uuid) use ($load) {
                        Plugin::authorize();

                        $props = $load(function ($client) use ($uuid) {
                            $video   = $client->video($uuid);
                            $library = isset($video['library_id']) ? $client->library($video['library_id']) : null;

                            // The poster frames only exist once the video is encoded
                            $thumbnails = ($video['status'] ?? null) === 'ready' && ($library['media_managed'] ?? false)
                                ? $client->thumbnails($uuid)
                                : [];

                            return [
                                'video'      => $video,
                                'library'    => $library,
                                'thumbnails' => $thumbnails,
                                'embedUrl'   => $client->embedPlayerUrl($uuid),
                            ];
                        });

                        $title = $props['video']['title'] ?? $uuid;

                        return [
                            'component'  => 'k-videooptimizer-video-view',
                            'title'      => $title,
                            'breadcrumb' => [
                                ['label' => $title, 'link' => 'videos/' . $uuid],
                            ],
                            'props'      => ['uuid' => $uuid] + $props,
                        ];
                    },
                ],
            ],
        ];
    },
];
