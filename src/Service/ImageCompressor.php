<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Re-encodes captured snapshots into a smaller file before they are written to disk.
 *
 * Everything is configurable through the app.snapshot.* parameters (see config/services.yaml).
 * If GD cannot read the bytes, or the re-encoded result is not smaller, the original bytes
 * are returned untouched, so a save never fails because of compression.
 */
final class ImageCompressor
{
    private const EXTENSIONS = ['webp' => 'webp', 'jpeg' => 'jpg', 'png' => 'png'];
    private const MIME_TYPES = ['webp' => 'image/webp', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    /** WebP quality value that switches libwebp to lossless mode. */
    private const WEBP_LOSSLESS = 101;

    public function __construct(
        #[Autowire('%app.snapshot.format%')] private readonly string $format,
        #[Autowire('%app.snapshot.quality%')] private readonly int $quality,
        #[Autowire('%app.snapshot.max_width%')] private readonly int $maxWidth,
        #[Autowire('%app.snapshot.max_height%')] private readonly int $maxHeight,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function compress(string $binary, string $fallbackExtension = 'png', string $fallbackMimeType = 'image/png'): CompressedImage
    {
        $original = new CompressedImage($binary, $fallbackExtension, $fallbackMimeType);

        if (!\extension_loaded('gd')) {
            $this->logger?->warning('Snapshot compression skipped: the GD extension is not available.');

            return $original;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            $this->logger?->warning('Snapshot compression skipped: GD could not read the uploaded image.');

            return $original;
        }

        $resized = $this->downscale($image);
        $wasResized = $resized !== $image;
        if ($wasResized) {
            imagedestroy($image);
            $image = $resized;
        }

        $format = $this->resolveFormat();

        if ($format === 'jpeg') {
            // JPEG has no alpha channel: flatten transparency onto white first.
            $flattened = $this->flatten($image);
            if ($flattened !== $image) {
                // Free the source right away: holding both costs width * height * 4 bytes twice.
                imagedestroy($image);
                $image = $flattened;
            }
        } elseif (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $encoded = $this->encode($image, $format);
        imagedestroy($image);

        if ($encoded === null) {
            return $original;
        }

        // Re-encoding a already-small image can make it bigger; keep whichever wins.
        if (!$wasResized && \strlen($encoded) >= \strlen($binary)) {
            return $original;
        }

        return new CompressedImage($encoded, self::EXTENSIONS[$format], self::MIME_TYPES[$format]);
    }

    /**
     * Returns the same image when no downscaling is configured or needed.
     */
    private function downscale(\GdImage $image): \GdImage
    {
        if ($this->maxWidth <= 0 && $this->maxHeight <= 0) {
            return $image;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $ratio = 1.0;
        if ($this->maxWidth > 0 && $width > $this->maxWidth) {
            $ratio = $this->maxWidth / $width;
        }
        if ($this->maxHeight > 0 && $height > $this->maxHeight) {
            $ratio = min($ratio, $this->maxHeight / $height);
        }

        if ($ratio >= 1.0) {
            return $image;
        }

        // IMG_BICUBIC is rejected by some libgd builds; the fixed-point filters are always available.
        $scaled = @imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)), IMG_BILINEAR_FIXED);

        if ($scaled === false) {
            $this->logger?->warning('Snapshot downscaling failed, keeping the captured resolution.');

            return $image;
        }

        return $scaled;
    }

    private function encode(\GdImage $image, string $format): ?string
    {
        if ($format !== 'jpeg') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();
        $ok = match ($format) {
            'webp' => imagewebp($image, null, $this->webpQuality()),
            'jpeg' => imagejpeg($image, null, $this->clamp($this->quality, 0, 100)),
            'png' => imagepng($image, null, 9),
        };
        $bytes = ob_get_clean();

        if (!$ok || !is_string($bytes) || $bytes === '') {
            $this->logger?->warning('Snapshot compression failed while encoding to {format}.', ['format' => $format]);

            return null;
        }

        return $bytes;
    }

    private function flatten(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $flattened = imagecreatetruecolor($width, $height);
        if ($flattened === false) {
            return $image;
        }

        // Never imagefill() here: the flood fill allocates a scratch stack of
        // width * height * 4 bytes (22 MB for a 2720x2048 capture) and exhausts memory_limit.
        // On a blank canvas a filled rectangle gives the same result and allocates nothing.
        imagealphablending($flattened, false);
        imagefilledrectangle($flattened, 0, 0, $width - 1, $height - 1, imagecolorallocate($flattened, 255, 255, 255));

        // Blending back on so the source alpha is composited onto the white background.
        imagealphablending($flattened, true);
        imagecopy($flattened, $image, 0, 0, 0, 0, $width, $height);

        return $flattened;
    }

    private function resolveFormat(): string
    {
        $format = strtolower($this->format);
        $format = $format === 'jpg' ? 'jpeg' : $format;

        if (!isset(self::EXTENSIONS[$format])) {
            $this->logger?->warning('Unknown snapshot format "{format}", falling back to PNG.', ['format' => $this->format]);

            return 'png';
        }

        if ($format === 'webp' && !\function_exists('imagewebp')) {
            $this->logger?->warning('GD was built without WebP support, falling back to PNG.');

            return 'png';
        }

        return $format;
    }

    private function webpQuality(): int
    {
        return $this->quality >= self::WEBP_LOSSLESS ? self::WEBP_LOSSLESS : $this->clamp($this->quality, 0, 100);
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
