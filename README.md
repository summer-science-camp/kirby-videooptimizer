# VideoOptimizer for Kirby

Kirby 5 plugin for [VideoOptimizer](https://videooptimizer.eu): pick, upload and import videos in the Panel and play them on the website as adaptive HLS streams from the VideoOptimizer EU CDN, without YouTube, Vimeo or self-hosted MP4 files.

The plugin follows the official [VideoOptimizer plugin for Shopware](https://github.com/ScaleCommerce/videooptimizer-shopware) (MIT) and brings its core features to Kirby.

## Features

- **Panel field** `videooptimizer` to pick a video from all libraries, with library filter, search and status (ready, processing, failed)
- **Upload** straight from the Panel: the browser sends the file in parts to VideoOptimizer storage via presigned URLs, the file never passes through the Kirby server
- **Import** of a video from a public `https` URL
- **Block** `videooptimizer` with poster facade (player loads on click) or direct player, own HLS player or hosted VideoOptimizer player (iframe), autoplay, muted, loop, controls and caption
- **Player** with native HLS (Safari, iOS, current Chrome), [hls.js](https://github.com/video-dev/hls.js) loaded on demand elsewhere and MP4 fallback
- **Server-side token**: all Panel requests go through the plugin's Kirby API routes, the token never reaches the browser
- **Caching** of the player data per video, so page views do not hit the API
- **Accessibility**: facade button with accessible name, titled iframes, labelled video elements, no autoplay for visitors who prefer reduced motion

## Requirements

- Kirby 5
- PHP 8.2 or newer
- A VideoOptimizer account and an API token (`vp_…`), created in the app under **Account → API-Tokens**
- Uploads and URL imports need a self-hosted ("media managed") library

## Installation

Composer (the repository is private, so add it as VCS repository first):

```json
"repositories": [
  { "type": "vcs", "url": "git@github.com:summer-science-camp/kirby-videooptimizer.git" }
]
```

```bash
composer require summer-science-camp/kirby-videooptimizer
```

Alternatively copy or clone the plugin to `site/plugins/kirby-videooptimizer`.

Then add the block to the fieldsets of a blocks or layout field:

```yaml
fieldsets:
  - heading
  - text
  - videooptimizer
```

## Credentials

The API token grants write access to all videos and libraries of the organization, so it must stay out of Git, out of content files and out of the browser. The plugin reads it in this order:

1. The option `summer-science-camp.videooptimizer.token`
2. The environment variable `VIDEOOPTIMIZER_API_TOKEN`

### Recommended: environment variable

Set `VIDEOOPTIMIZER_API_TOKEN` on the server and leave the option empty. Nothing secret ends up in the project.

| Environment | Setup |
| --- | --- |
| DDEV | `ddev dotenv set .ddev/.env --videooptimizer-api-token=vp_…`, then `ddev restart`. Keep `.ddev/.env` out of Git. |
| Apache | `SetEnv VIDEOOPTIMIZER_API_TOKEN vp_…` in the vhost (not in a `.htaccess` inside the repository) |
| nginx + PHP-FPM | `fastcgi_param VIDEOOPTIMIZER_API_TOKEN vp_…;` or `env[VIDEOOPTIMIZER_API_TOKEN] = vp_…` in the FPM pool |
| Managed hosting | Environment variable setting of the hosting panel |

PHP-FPM only passes variables on with `clear_env = no` or an explicit `env[...]` entry.

### Alternative: host-specific Kirby config

For hosting without access to environment variables, set the option in a config file that only exists on the server and is excluded from Git, e.g. `site/config/config.www.example.org.php`:

```php
<?php

return [
    'summer-science-camp.videooptimizer.token' => 'vp_…',
];
```

Make sure the deployment does not overwrite or publish this file.

### Not in the Panel

Unlike the Shopware plugin, which stores the token in the database, there is deliberately no Panel setting. Kirby stores Panel input as plain text files in `content/`, which are often versioned or backed up and are readable by every Panel user.

### Good practice

- Create a dedicated token for each website and environment, so it can be revoked on its own
- Revoke the token immediately in the VideoOptimizer app if it was exposed
- Without a token the website keeps playing existing videos (the embed endpoint is public); only the Panel features need it

## Options

```php
return [
    'summer-science-camp.videooptimizer' => [
        'token'    => null,                                   // see Credentials
        'apiUrl'   => 'https://api.videooptimizer.eu/api/v1',
        'embedUrl' => 'https://videooptimizer.eu',            // host of the hosted player
        'library'  => null,                                   // library preselected for uploads
        'cache'    => true,
    ],
];
```

Both URLs must be absolute `https` URLs.

## Usage

### Block

Editors add the block **Video (VideoOptimizer)**, pick or upload a video and choose:

| Setting | Values |
| --- | --- |
| Presentation | Poster with the player loading on click (default, no data loaded before the click) or player directly |
| Player | Own HLS player (default) or hosted VideoOptimizer player (iframe) |
| Description for screen readers | Accessible name, defaults to the video title |
| Autoplay | Only for the direct player, always muted, skipped for reduced motion |
| Muted, loop, controls | Playback options |
| Caption | Shown below the video |

### Field in your own blueprints

```yaml
fields:
  video:
    label: Video
    type: videooptimizer
    library: 2f6c…  # optional, preselected library for uploads
```

### Templates

```php
<?php if ($video = $page->video()->toVideoOptimizerVideo()): ?>
  <?php snippet('videooptimizer/video', [
      'video'        => $video,
      'presentation' => 'facade', // or 'direct'
      'player'       => 'native', // or 'embed'
      'label'        => 'Trailer of the show',
  ]) ?>
<?php endif ?>
```

`toVideoOptimizerVideo()` returns `null` when the field is empty, the video does not exist, is still processing or the API is unreachable, so templates never break. Failed lookups trigger the hook `videooptimizer.error` with the video UUID and the exception, e.g. for logging.

The `Video` object exposes `uuid`, `hls`, `mp4`, `poster`, `posterSrcset()`, `title`, `duration` and `aspectRatio`.

## Caching

The player data of a video is cached for 60 minutes. Failed lookups and videos that are still processing are cached for one minute, so an unreachable API cannot slow down every page view. Opening a video in the Panel refreshes its cached data once it is no longer processing. Clear the cache `summer-science-camp.videooptimizer` to refresh everything at once.

## Permissions

The plugin's API routes require a Panel user who may update pages.

## Differences to the Shopware plugin

Not included yet:

- Library management (create, edit encoding ladder, reprocess) and deleting or renaming videos: use the VideoOptimizer app
- Poster selection and custom poster upload
- The Shopware layout elements media split, background hero, spotlight and video grid
- Webhooks; the Panel polls the status of processing videos instead

## License

MIT, see [LICENSE](LICENSE). Third-party code and attributions are listed in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
