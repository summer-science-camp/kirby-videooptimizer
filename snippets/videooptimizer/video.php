<?php
/**
 * Renders a VideoOptimizer video
 *
 * @var \CircusCirculi\VideoOptimizer\Video|null $video
 * @var string|null $presentation `facade` (poster, player on click) or `direct`
 * @var string|null $player `native` (HLS player) or `embed` (hosted iframe player)
 * @var string|null $label Accessible name, defaults to the video title
 * @var bool|null $autoplay Only for `direct`, always muted, skipped for reduced motion
 * @var bool|null $muted
 * @var bool|null $loop
 * @var bool|null $controls
 * @var string|null $class
 */

use CircusCirculi\VideoOptimizer\Plugin;

if (($video ?? null) === null) {
    return;
}

$plugin       = kirby()->plugin('circus-circuli/videooptimizer');
$presentation = ($presentation ?? 'facade') === 'direct' ? 'direct' : 'facade';
$player       = ($player ?? 'native') === 'embed' ? 'embed' : 'native';
$label        = trim((string)($label ?? '')) ?: ($video->title ?: t('videooptimizer.play'));
$options      = [
    'autoplay' => $presentation === 'direct' && ($autoplay ?? false),
    'muted'    => (bool)($muted ?? false),
    'loop'     => (bool)($loop ?? false),
    'controls' => (bool)($controls ?? true),
];
$config       = [
    'player'   => $player,
    'embedUrl' => Plugin::client()->embedPlayerUrl($video->uuid, $options),
    'hls'      => $video->hls,
    'mp4'      => $video->mp4,
    'poster'   => $video->poster,
    'title'    => $label,
    'hlsJs'    => $plugin->asset('hls.light.min.js')?->url(),
] + $options;
?>
<div class="videooptimizer <?= esc($class ?? '', 'attr') ?>" data-videooptimizer="<?= esc(json_encode($config), 'attr') ?>"
  style="position: relative; width: 100%; aspect-ratio: <?= $video->aspectRatio ?>; background: #000; overflow: hidden">
  <?php if ($presentation === 'facade'): ?>
  <button type="button" class="videooptimizer__facade" data-videooptimizer-play aria-label="<?= esc(t('videooptimizer.play') . ': ' . $label, 'attr') ?>"
    style="position: absolute; inset: 0; width: 100%; height: 100%; padding: 0; border: 0; background: none; cursor: pointer">
    <?php if ($video->poster): ?>
    <img src="<?= esc($video->poster, 'attr') ?>" <?= $video->posterSrcset() ? 'srcset="' . esc($video->posterSrcset(), 'attr') . '" sizes="(min-width: 1280px) 1280px, 100vw"' : '' ?>
      alt="" loading="lazy" decoding="async" style="width: 100%; height: 100%; object-fit: cover">
    <?php endif ?>
    <span class="videooptimizer__play" aria-hidden="true"
      style="position: absolute; top: 50%; left: 50%; display: grid; place-items: center; width: 4.5rem; height: 4.5rem; border-radius: 9999px; background: rgb(0 0 0 / 0.65); color: #fff; transform: translate(-50%, -50%)">
      <svg viewBox="0 0 24 24" width="32" height="32" fill="currentColor" focusable="false"><path d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11-6.86a1 1 0 0 0 0-1.72l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg>
    </span>
  </button>
  <?php elseif ($player === 'embed'): ?>
  <iframe src="<?= esc($config['embedUrl'], 'attr') ?>" title="<?= esc($label, 'attr') ?>" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen loading="lazy"
    referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-popups allow-presentation"
    style="position: absolute; inset: 0; width: 100%; height: 100%; border: 0"></iframe>
  <?php else: ?>
  <video aria-label="<?= esc($label, 'attr') ?>" playsinline preload="none" <?= $video->poster ? 'poster="' . esc($video->poster, 'attr') . '"' : '' ?>
    <?= $options['controls'] ? 'controls' : '' ?> <?= $options['loop'] ? 'loop' : '' ?> <?= $options['muted'] || $options['autoplay'] ? 'muted' : '' ?>
    style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain"></video>
  <?php endif ?>
</div>
<script type="module" src="<?= $plugin->asset('player.js')?->url() ?>"></script>
