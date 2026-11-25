<?php
/**
 * An image of a section (`ImageField` resolved): its size, alternative text (empty when decorative)
 * and lazy loading unless it is in the first section. Printed by `ui_image()` with the stored
 * image's responsive variants (D-54-01).
 *
 * @var array{key?: string, url: string, width: int|null, height: int|null, alt: string}|null $image
 * @var string|null                                                                         $imageClass
 * @var bool|null                                                                           $eager
 * @var string|null                                                                         $sizes   the `sizes` attribute (default 100vw)
 */
?>
<?php if (is_array($image ?? null)): ?>
    <?= ui_image(($image['key'] ?? '') !== '' ? (string) $image['key'] : $image['url'], ['alt' => $image['alt'], 'class' => $imageClass ?? 'img-fluid', 'lazy' => empty($eager), 'sizes' => $sizes ?? '100vw']) ?>
<?php endif ?>
