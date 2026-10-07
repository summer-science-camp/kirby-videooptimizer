<?php
/**
 * @var \Kirby\Cms\Block $block
 */
$video = $block->video()->toVideoOptimizerVideo();

if ($video === null) {
    return;
}
?>
<figure class="videooptimizer-block">
  <?php snippet('videooptimizer/video', [
      'video'        => $video,
      'presentation' => $block->presentation()->value(),
      'player'       => $block->player()->value(),
      'label'        => $block->label()->value(),
      'autoplay'     => $block->autoplay()->toBool(),
      'muted'        => $block->muted()->toBool(),
      'loop'         => $block->loop()->toBool(),
      'controls'     => $block->controls()->toBool(true),
  ]) ?>
  <?php if ($block->caption()->isNotEmpty()): ?>
  <figcaption><?= $block->caption() ?></figcaption>
  <?php endif ?>
</figure>
