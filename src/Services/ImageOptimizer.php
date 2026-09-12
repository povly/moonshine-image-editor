<?php

declare(strict_types=1);

namespace Povly\MoonShineImageEditor\Services;

use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Laravel\Facades\Image;
use Povly\MoonShineImageEditor\Contracts\ImageOptimizerInterface;
use Povly\MoonShineImageEditor\Enums\ImageExtension;

final class ImageOptimizer implements ImageOptimizerInterface
{
    public function __construct(
        private string $fullPath,
        private array $config = [],
    ) {}

    public function process(): void
    {
        $this->optimize();

        $sourceFormat = strtolower(pathinfo($this->fullPath, PATHINFO_EXTENSION));

        if ($sourceFormat !== 'webp' && ($this->config['convert']['webp']['enabled'] ?? false)) {
            $this->convertWebp();
        }

        if ($sourceFormat !== 'avif' && ($this->config['convert']['avif']['enabled'] ?? false)) {
            $this->convertAvif();
        }
    }

    public function convertFormat(string $targetFormat): string
    {
        $sourceFormat = strtolower(pathinfo($this->fullPath, PATHINFO_EXTENSION));

        if ($sourceFormat === $targetFormat) {
            return $this->fullPath;
        }

        $image = Image::decodePath($this->fullPath);

        $targetPath = $this->getConvertedPath($this->fullPath, $targetFormat);
        $quality = $this->config['quality'][$targetFormat] ?? 82;

        $encoded = match ($targetFormat) {
            'jpg', 'jpeg' => $image->encode(new JpegEncoder(
                quality: $quality,
                progressive: true,
                strip: $this->config['optimize']['strip_metadata'] ?? true,
            )),
            'png' => $image->encode(new PngEncoder()),
            default => null,
        };

        if ($encoded === null) {
            return $this->fullPath;
        }

        $this->writeEncoded($encoded, $targetPath);

        if (! file_exists($targetPath)) {
            return $this->fullPath;
        }

        if (filesize($targetPath) >= filesize($this->fullPath)) {
            @unlink($targetPath);

            return $this->fullPath;
        }

        if ($sourceFormat !== $targetFormat && file_exists($this->fullPath) && $this->fullPath !== $targetPath) {
            @unlink($this->fullPath);
        }

        return $targetPath;
    }

    public function optimize(): void
    {
        $extension = strtolower(pathinfo($this->fullPath, PATHINFO_EXTENSION));

        if (! in_array($extension, ImageExtension::optimizable(), true)) {
            return;
        }

        $sizeBefore = filesize($this->fullPath);

        $image = Image::decodePath($this->fullPath);

        $maxWidth = $this->config['optimize']['max_width'] ?? null;
        $maxHeight = $this->config['optimize']['max_height'] ?? null;

        if ($maxWidth !== null || $maxHeight !== null) {
            $image = $image->scaleDown(width: $maxWidth, height: $maxHeight);
        }

        $stripMetadata = $this->config['optimize']['strip_metadata'] ?? true;
        $quality = $this->config['quality'][$extension] ?? 85;

        $encoded = match ($extension) {
            'jpg', 'jpeg' => $image->encode(new JpegEncoder(
                quality: $quality,
                progressive: true,
                strip: $stripMetadata,
            )),
            'png' => $image->encode(new PngEncoder()),
            default => null,
        };

        if ($encoded === null) {
            return;
        }

        $tempPath = dirname($this->fullPath).'/'.pathinfo($this->fullPath, PATHINFO_FILENAME).'.'.uniqid('', true).'.tmp';

        try {
            $this->writeEncoded($encoded, $tempPath);

            if (! file_exists($tempPath)) {
                return;
            }

            $sizeAfter = filesize($tempPath);

            if ($sizeAfter < $sizeBefore) {
                rename($tempPath, $this->fullPath);
            } else {
                @unlink($tempPath);
            }
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw $e;
        }
    }

    public function convertWebp(): void
    {
        $webpPath = $this->getConvertedPath($this->fullPath, 'webp');
        $quality = $this->config['convert']['webp']['quality'] ?? 80;

        try {
            $image = Image::decodePath($this->fullPath);
            $this->writeEncoded($image->encode(new WebpEncoder(quality: $quality)), $webpPath);

            if (! file_exists($webpPath)) {
                return;
            }

            $originalSize = filesize($this->fullPath);

            if (filesize($webpPath) >= $originalSize) {
                unlink($webpPath);
            }
        } catch (\Throwable $e) {
            if (file_exists($webpPath)) {
                unlink($webpPath);
            }
        }
    }

    public function convertAvif(): void
    {
        $avifPath = $this->getConvertedPath($this->fullPath, 'avif');
        $quality = $this->config['convert']['avif']['quality'] ?? 65;

        try {
            $image = Image::decodePath($this->fullPath);
            $this->writeEncoded($image->encode(new AvifEncoder(quality: $quality)), $avifPath);

            if (! file_exists($avifPath)) {
                return;
            }

            $webpPath = $this->getConvertedPath($this->fullPath, 'webp');

            if (file_exists($webpPath)) {
                $comparisonSize = filesize($webpPath);
            } else {
                $comparisonSize = filesize($this->fullPath);
            }

            if (filesize($avifPath) >= $comparisonSize) {
                unlink($avifPath);
            }
        } catch (\Throwable $e) {
            if (file_exists($avifPath)) {
                unlink($avifPath);
            }
        }
    }

    /**
     * Write an encoded image to disk. Intervention Image v4 removed
     * EncodedImage::save(); encoded data is written via stream contents.
     */
    private function writeEncoded(EncodedImageInterface $encoded, string $path): void
    {
        file_put_contents($path, $encoded->toString());
    }

    private function getConvertedPath(string $originalPath, string $format): string
    {
        $info = pathinfo($originalPath);

        return $info['dirname'].'/'.$info['filename'].'.'.$format;
    }
}
