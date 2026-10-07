<?php

namespace CircusCirculi\VideoOptimizer;

use Kirby\Cms\App;

/**
 * Builds the client from the plugin options.
 *
 * Credentials are never stored in content files. The token comes from the
 * `circus-circuli.videooptimizer.token` option, which falls back to the
 * `VIDEOOPTIMIZER_API_TOKEN` environment variable.
 */
class Plugin
{
    public const ENV_TOKEN = 'VIDEOOPTIMIZER_API_TOKEN';

    private static ?Client $client = null;

    public static function client(): Client
    {
        $kirby = App::instance();

        return static::$client ??= new Client(
            token: static::token(),
            apiUrl: $kirby->option('circus-circuli.videooptimizer.apiUrl', Client::DEFAULT_API_URL),
            embedUrl: $kirby->option('circus-circuli.videooptimizer.embedUrl', Client::DEFAULT_EMBED_URL),
            cache: $kirby->cache('circus-circuli.videooptimizer'),
        );
    }

    public static function token(): ?string
    {
        $token = App::instance()->option('circus-circuli.videooptimizer.token');

        if (is_string($token) === false || $token === '') {
            $token = getenv(static::ENV_TOKEN) ?: ($_SERVER[static::ENV_TOKEN] ?? $_ENV[static::ENV_TOKEN] ?? null);
        }

        return is_string($token) && $token !== '' ? $token : null;
    }
}
