<?php

namespace SummerScienceCamp\VideoOptimizer;

use Kirby\Cms\App;
use Throwable;

/**
 * Everything a template needs to render one video: sources, poster and
 * aspect ratio from the public embed endpoint.
 */
class Video
{
    private function __construct(
        public readonly string $uuid,
        public readonly ?string $hls,
        public readonly ?string $mp4,
        public readonly ?string $poster,
        public readonly array $posterSrcset,
        public readonly ?string $title,
        public readonly ?float $duration,
        public readonly string $aspectRatio,
    ) {
    }

    /**
     * Returns null when the video does not exist, is not ready yet or the
     * API is unreachable. The error is logged instead of breaking the page.
     */
    public static function find(string $uuid, ?Client $client = null): ?static
    {
        $uuid = trim($uuid);

        if ($uuid === '') {
            return null;
        }

        try {
            $embed = ($client ?? Plugin::client())->embed($uuid);
        } catch (Throwable $e) {
            App::instance()->trigger('videooptimizer.error', ['uuid' => $uuid, 'exception' => $e]);
            return null;
        }

        $sources = $embed['sources'] ?? [];

        if ($sources === []) {
            return null;
        }

        return new static(
            uuid: $uuid,
            hls: static::pickHls($sources),
            mp4: static::pickMp4($sources),
            poster: is_string($embed['poster'] ?? null) ? $embed['poster'] : null,
            posterSrcset: is_array($embed['posterSrcset'] ?? null) ? $embed['posterSrcset'] : [],
            title: is_string($embed['title'] ?? null) ? $embed['title'] : null,
            duration: is_numeric($embed['duration'] ?? null) ? (float)$embed['duration'] : null,
            aspectRatio: static::aspectRatio($embed['resolution'] ?? null),
        );
    }

    /**
     * `srcset` attribute value for the poster image
     */
    public function posterSrcset(): string
    {
        $candidates = [];

        foreach ($this->posterSrcset as $size) {
            if (is_string($size['url'] ?? null) && (int)($size['width'] ?? 0) > 0) {
                $candidates[] = $size['url'] . ' ' . (int)$size['width'] . 'w';
            }
        }

        return implode(', ', $candidates);
    }

    private static function pickHls(array $sources): ?string
    {
        foreach ($sources as $source) {
            $isHls = ($source['codec'] ?? null) === 'hls' || ($source['type'] ?? null) === 'application/vnd.apple.mpegurl';

            if ($isHls && static::playable($source)) {
                return $source['src'];
            }
        }

        return null;
    }

    /**
     * Largest progressive MP4 as fallback for browsers without HLS support
     */
    private static function pickMp4(array $sources): ?string
    {
        $best     = null;
        $bestSize = -1;

        foreach ($sources as $source) {
            if (($source['type'] ?? null) !== 'video/mp4' || static::playable($source) === false || str_ends_with($source['src'], '.mp4') === false) {
                continue;
            }

            if ((int)($source['size'] ?? 0) > $bestSize) {
                $bestSize = (int)($source['size'] ?? 0);
                $best     = $source['src'];
            }
        }

        return $best;
    }

    private static function playable(array $source): bool
    {
        return is_string($source['src'] ?? null) && $source['src'] !== '';
    }

    private static function aspectRatio(mixed $resolution): string
    {
        if (is_string($resolution) && preg_match('/^(\d+)\s*x\s*(\d+)$/i', trim($resolution), $match) && (int)$match[1] > 0 && (int)$match[2] > 0) {
            return $match[1] . ' / ' . $match[2];
        }

        return '16 / 9';
    }
}
