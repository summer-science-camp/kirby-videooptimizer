<?php

namespace CircusCirculi\VideoOptimizer;

use Closure;
use CircusCirculi\VideoOptimizer\Exception\ApiException;
use CircusCirculi\VideoOptimizer\Exception\ConfigurationException;
use Kirby\Cms\App;
use Kirby\Exception\PermissionException;
use Kirby\Http\Response;

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

    /**
     * Only Panel users who may edit pages can manage videos
     */
    public static function authorize(): void
    {
        if (App::instance()->user()?->role()->permissions()->for('pages', 'update') !== true) {
            throw new PermissionException(message: t('videooptimizer.permission'));
        }
    }

    /**
     * Runs an API action for the Panel and turns plugin errors into error
     * responses the Panel shows as notification
     */
    public static function respond(Closure $action): array|Response
    {
        static::authorize();

        try {
            return ['data' => $action(static::client())];
        } catch (ConfigurationException $e) {
            return static::error($e->getMessage(), 400);
        } catch (ApiException $e) {
            return static::error($e->getMessage(), $e->status() >= 400 && $e->status() < 600 ? $e->status() : 502);
        }
    }

    public static function error(string $message, int $code = 400): Response
    {
        return Response::json(['status' => 'error', 'code' => $code, 'message' => $message], $code);
    }
}
