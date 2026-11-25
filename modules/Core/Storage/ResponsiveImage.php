<?php

declare(strict_types=1);

namespace Modules\Core\Storage;

use Throwable;

/**
 * The `<img>` of a stored image with its responsive variants (`ui_image()`, D-54-01): `src` = the
 * widest variant (or the file), `srcset` = every variant plus the file, `sizes`, the stored
 * image's `width`/`height` (no layout shift), `alt`, `class`, `loading="lazy"` and
 * `decoding="async"`. Everything is escaped; no inline styles.
 *
 * Options: `alt` (''), `class`, `sizes` (default `100vw`), `lazy` (true; false for the image
 * above the fold), `async` (true), `id`.
 */
final class ResponsiveImage
{
    public const DEFAULT_SIZES = '100vw';

    public function __construct(private readonly ?Storage $storage = null)
    {
    }

    private function storage(): Storage
    {
        /** @var Storage */
        return $this->storage ?? service('storage');
    }

    /**
     * A stored file from an id, a file key or a site URL of a public file; null otherwise.
     */
    public function resolve(StoredFile|int|string|null $file): ?StoredFile
    {
        try {
            if ($file instanceof StoredFile || $file === null) {
                return $file;
            }

            if (is_int($file)) {
                return $this->storage()->findById($file);
            }

            if (preg_match('/^[a-f0-9]{32}$/', $file) === 1) {
                return $this->storage()->find($file);
            }

            return $this->fromUrl($file);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The stored file of a public disk URL (`https://site/uploads/modules/news/…/x.jpg` or a path
     * `/uploads/…`), or null.
     */
    public function fromUrl(string $url): ?StoredFile
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== parse_url(base_url(), PHP_URL_HOST)) {
            return null; // another site
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach ($this->storage()->config()->disks as $name => $disk) {
            $prefix = $disk['url'] === null ? '' : rtrim((string) parse_url(base_url($disk['url']), PHP_URL_PATH), '/') . '/';

            if ($prefix === '' || ! str_starts_with($path, $prefix)) {
                continue;
            }

            try {
                $relative = PathNormalizer::normalize(rawurldecode(substr($path, strlen($prefix))));
            } catch (StorageException) {
                return null;
            }

            $row = db_connect()->table('stored_files')->where('disk', $name)->where('path', $relative)->get()->getRowArray();

            return is_array($row) ? StoredFile::fromRow($row) : null;
        }

        return null;
    }

    /**
     * `src`, `srcset`, `width`, `height` of a stored image (`srcset` empty without variants).
     *
     * @return array{src: string, srcset: string, width: int|null, height: int|null}
     */
    public function attributes(StoredFile $file): array
    {
        $storage  = $this->storage();
        $url      = $storage->url($file);
        $variants = $storage->variants()->eligible($file) ? $storage->variants()->of($file->id) : [];

        if ($variants === []) {
            return ['src' => $url, 'srcset' => '', 'width' => $file->width, 'height' => $file->height];
        }

        $set = [];

        foreach ($variants as $variant) {
            $set[] = $storage->variants()->url($file, $variant['path']) . ' ' . $variant['width'] . 'w';
        }

        $set[]  = $url . ' ' . (int) $file->width . 'w';
        $widest = $variants[count($variants) - 1];

        return [
            'src'    => $storage->variants()->url($file, $widest['path']),
            'srcset' => implode(', ', $set),
            'width'  => $file->width,
            'height' => $file->height,
        ];
    }

    /**
     * The URL of the widest variant at most `$width` px wide, or the file's own URL.
     */
    public function urlAtMost(StoredFile $file, int $width): string
    {
        $storage = $this->storage();
        $best    = null;

        foreach ($storage->variants()->eligible($file) ? $storage->variants()->of($file->id) : [] as $variant) {
            if ($variant['width'] <= $width) {
                $best = $variant;
            }
        }

        return $best === null ? $storage->url($file) : $storage->variants()->url($file, $best['path']);
    }

    /**
     * @param array{alt?: string, class?: string, sizes?: string, lazy?: bool, async?: bool, id?: string} $options
     */
    public function html(StoredFile|int|string|null $file, array $options = []): string
    {
        $stored = $this->resolve($file);

        if ($stored === null) {
            if (! is_string($file) || $file === '' || preg_match('/^[a-f0-9]{32}$/', $file) === 1) {
                return '';
            }

            $url = ui_safe_url($file);

            return $url === '#' ? '' : self::tag(['src' => $url, 'srcset' => '', 'width' => null, 'height' => null], $options);
        }

        if (! $stored->isImage()) {
            return '';
        }

        return self::tag($this->attributes($stored), $options);
    }

    /**
     * @param array{src: string, srcset: string, width: int|null, height: int|null} $attributes
     * @param array{alt?: string, class?: string, sizes?: string, lazy?: bool, async?: bool, id?: string} $options
     */
    private static function tag(array $attributes, array $options): string
    {
        $html = '<img';

        if (($options['id'] ?? '') !== '') {
            $html .= ' id="' . esc((string) $options['id']) . '"';
        }

        if (($options['class'] ?? '') !== '') {
            $html .= ' class="' . esc((string) $options['class']) . '"';
        }

        $html .= ' src="' . esc($attributes['src']) . '"';

        if ($attributes['srcset'] !== '') {
            $html .= ' srcset="' . esc($attributes['srcset']) . '"'
                . ' sizes="' . esc((string) ($options['sizes'] ?? self::DEFAULT_SIZES)) . '"';
        }

        $html .= ' alt="' . esc((string) ($options['alt'] ?? '')) . '"';

        if ($attributes['width'] !== null && $attributes['height'] !== null) {
            $html .= ' width="' . (int) $attributes['width'] . '" height="' . (int) $attributes['height'] . '"';
        }

        if ($options['lazy'] ?? true) {
            $html .= ' loading="lazy"';
        }

        if ($options['async'] ?? true) {
            $html .= ' decoding="async"';
        }

        return $html . '>';
    }
}
