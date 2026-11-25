<?php

declare(strict_types=1);

namespace Modules\Core\Storage;

use GdImage;

/**
 * Decodes an image with GD, scales it down when its longest side is above the limit (never up;
 * bicubic resampling, transparency kept) and encodes it again in the same format. The new file has only the
 * pixels: EXIF/XMP/IPTC metadata (GPS position, camera, comments) and anything appended or hidden
 * in the original are dropped. The EXIF orientation of JPEG photos is applied first, so they keep
 * looking right without the metadata. Transparency is kept (PNG, WebP, GIF); animated GIFs keep
 * only their first frame.
 */
final class ImageReencoder
{
    /**
     * @param int $maxDimension longest side in px (0 = no limit)
     */
    public function __construct(private readonly int $quality = 85, private readonly int $maxDimension = 0)
    {
    }

    /**
     * The size of an image scaled down to fit a longest side (the same size when it already fits).
     *
     * @return array{0: int, 1: int}
     */
    public static function fit(int $width, int $height, int $maxDimension): array
    {
        $longest = max($width, $height);

        if ($maxDimension <= 0 || $longest <= $maxDimension) {
            return [$width, $height];
        }

        $ratio = $maxDimension / $longest;

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }

    /**
     * Bytes GD needs to decode an image (a conservative estimate: 5 bytes per pixel).
     */
    public static function memoryNeeded(int $width, int $height): int
    {
        return $width * $height * 5;
    }

    /**
     * Whether decoding an image fits in what is left of `memory_limit` (always true without limit).
     */
    public static function fitsInMemory(int $width, int $height, ?int $limit = null): bool
    {
        $limit ??= self::memoryLimit();

        if ($limit <= 0) {
            return true;
        }

        return self::memoryNeeded($width, $height) <= $limit - memory_get_usage();
    }

    /**
     * `memory_limit` in bytes (-1 or 0: no limit).
     */
    public static function memoryLimit(): int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    /**
     * Scales an image down to fit the limit (a new image; the original is destroyed).
     */
    public static function scale(GdImage $image, int $maxDimension): GdImage
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        [$newWidth, $newHeight] = self::fit($width, $height, $maxDimension);

        return $newWidth === $width && $newHeight === $height ? $image : self::resample($image, $newWidth, $newHeight);
    }

    /**
     * A resampled copy of an image (bicubic-like quality, alpha kept).
     */
    public static function resample(GdImage $image, int $width, int $height): GdImage
    {
        if (! imageistruecolor($image)) {
            return self::resamplePalette($image, $width, $height);
        }

        $target = imagecreatetruecolor(max(1, $width), max(1, $height));

        if (! $target instanceof GdImage) {
            return $image;
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $width, $height, $transparent === false ? 0 : $transparent);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $target;
    }

    /**
     * A palette image (GIF) keeps its palette and transparent colour: resized pixel by pixel
     * (blending would invent colours the palette has not got).
     */
    private static function resamplePalette(GdImage $image, int $width, int $height): GdImage
    {
        $target = imagecreate(max(1, $width), max(1, $height));

        if (! $target instanceof GdImage) {
            return $image;
        }

        imagepalettecopy($target, $image);
        $transparent = imagecolortransparent($image);

        if ($transparent >= 0) {
            imagefill($target, 0, 0, $transparent);
            imagecolortransparent($target, $transparent);
        }

        imagecopyresized($target, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $target;
    }

    /**
     * IMAGETYPE_* constant of an image extension, or null.
     */
    public static function typeOf(string $extension): ?int
    {
        return match ($extension) {
            'jpg', 'jpeg' => IMAGETYPE_JPEG,
            'png'  => IMAGETYPE_PNG,
            'gif'  => IMAGETYPE_GIF,
            'webp' => IMAGETYPE_WEBP,
            default => null,
        };
    }

    /**
     * @return array{contents: string, width: int, height: int}
     *
     * @throws UploadRefused when the image cannot be decoded or encoded
     */
    public function reencode(string $path, string $extension): array
    {
        $data  = (string) file_get_contents($path);
        $image = $data === '' ? false : @imagecreatefromstring($data);

        if (! $image instanceof GdImage) {
            throw new UploadRefused('image');
        }

        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            $image = $this->orient($image, $path);
        }

        $image = self::scale($image, $this->maxDimension);

        ob_start();

        try {
            $ok = match ($extension) {
                'jpg', 'jpeg' => imagejpeg($image, null, $this->quality),
                'png'  => $this->png($image),
                'gif'  => imagegif($image),
                'webp' => $this->webp($image),
                default => false,
            };
        } finally {
            $contents = (string) ob_get_clean();
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        if (! $ok || $contents === '') {
            throw new UploadRefused('image');
        }

        return ['contents' => $contents, 'width' => $width, 'height' => $height];
    }

    private function png(GdImage $image): bool
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return imagepng($image, null, 6);
    }

    private function webp(GdImage $image): bool
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return imagewebp($image, null, $this->quality);
    }

    /**
     * Applies the EXIF orientation of a JPEG photo (the tag is lost by the re-encoding).
     */
    private function orient(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif        = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3       => imagerotate($image, 180, 0),
            6       => imagerotate($image, -90, 0),
            8       => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
