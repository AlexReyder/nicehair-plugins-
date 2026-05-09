<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

trait NH_TKI_ImageProcessorTrait
{
    private function read_zip_image_source(ZipArchive $zip, string $entryName, string $reportSku, array &$report): ?array
    {
        $extension = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
        $baseName = sanitize_file_name(basename($entryName));
        $rawBytes = $zip->getFromName($entryName);

        if (! is_string($rawBytes) || $rawBytes === '') {
            $report['unsupported_images'][] = [
                'sku' => $reportSku,
                'file' => $entryName,
                'reason' => 'Empty or unreadable ZIP entry.',
            ];

            return null;
        }

        return [
            'entry_name' => $entryName,
            'extension' => $extension,
            'base_name' => $baseName,
            'raw_bytes' => $rawBytes,
            'hash' => hash('sha256', $rawBytes),
        ];
    }
    private function prepare_image_source_for_upload(array $source, string $reportSku, array &$report): ?array
    {
        $entryName = (string) ($source['entry_name'] ?? '');
        $extension = strtolower((string) ($source['extension'] ?? ''));
        $baseName = (string) ($source['base_name'] ?? '');
        $rawBytes = (string) ($source['raw_bytes'] ?? '');

        if ($entryName === '' || $baseName === '' || $rawBytes === '') {
            return null;
        }

        $targetName = $baseName;
        $targetBytes = $rawBytes;

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            // keep original bytes
        } elseif (in_array($extension, ['heic', 'heif', 'dng'], true)) {
            if (! class_exists('Imagick')) {
                $report['unsupported_images'][] = [
                    'sku' => $reportSku,
                    'file' => $entryName,
                    'reason' => 'Imagick is not available for HEIC/DNG conversion.',
                ];

                return null;
            }

            $converted = $this->convert_raw_image_to_jpg($rawBytes, $baseName);

            if (! is_array($converted)) {
                $report['unsupported_images'][] = [
                    'sku' => $reportSku,
                    'file' => $entryName,
                    'reason' => 'Failed to convert HEIC/DNG to JPG.',
                ];

                return null;
            }

            $targetName = $converted['name'];
            $targetBytes = $converted['bytes'];
        } else {
            $report['unsupported_images'][] = [
                'sku' => $reportSku,
                'file' => $entryName,
                'reason' => 'Unsupported image extension.',
            ];

            return null;
        }

        $normalized = $this->normalize_image_payload($targetBytes, $targetName);

        if (is_array($normalized)) {
            $targetName = $normalized['name'];
            $targetBytes = $normalized['bytes'];
        }

        $tmpFile = wp_tempnam($targetName);

        if (! $tmpFile) {
            $report['unsupported_images'][] = [
                'sku' => $reportSku,
                'file' => $entryName,
                'reason' => 'Unable to create temporary file.',
            ];

            return null;
        }

        file_put_contents($tmpFile, $targetBytes);

        return [
            'name' => $targetName,
            'tmp_name' => $tmpFile,
        ];
    }
    private function convert_raw_image_to_jpg(string $rawBytes, string $baseName): ?array
    {
        $tmpInput = wp_tempnam($baseName);

        if (! $tmpInput) {
            return null;
        }

        file_put_contents($tmpInput, $rawBytes);

        try {
            $imagick = new Imagick();
            $imagick->readImage($tmpInput);
            $hasAlpha = $this->prepare_imagick_source_image($imagick);

            if ($hasAlpha && method_exists($imagick, 'mergeImageLayers')) {
                $imagick->setImageBackgroundColor(new ImagickPixel('white'));
                $flattened = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $flattened->setImagePage(0, 0, 0, 0);
                $imagick->clear();
                $imagick->destroy();
                $imagick = $flattened;
            }

            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(self::JPEG_QUALITY);
            $bytes = $imagick->getImageBlob();
            $name = preg_replace('/\.[^.]+$/', '.jpg', $baseName) ?? ($baseName . '.jpg');
            $imagick->clear();
            $imagick->destroy();
            @unlink($tmpInput);

            if (! is_string($bytes) || $bytes === '') {
                return null;
            }

            return [
                'name' => sanitize_file_name($name),
                'bytes' => $bytes,
            ];
        } catch (Throwable) {
            @unlink($tmpInput);

            return null;
        }
    }
    private function normalize_image_payload(string $rawBytes, string $fileName): ?array
    {
        if (! class_exists('Imagick')) {
            return [
                'name' => sanitize_file_name($fileName),
                'bytes' => $rawBytes,
            ];
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        try {
            $imagick = new Imagick();
            $imagick->readImageBlob($rawBytes);
            $this->prepare_imagick_source_image($imagick);

            $width = (int) $imagick->getImageWidth();
            $height = (int) $imagick->getImageHeight();

            if (max($width, $height) > self::MAX_SOURCE_DIMENSION) {
                $this->resize_imagick_to_max_dimension($imagick, $width, $height);
            }

            switch ($extension) {
                case 'jpg':
                case 'jpeg':
                    $imagick->setImageFormat('jpeg');
                    $imagick->setImageCompressionQuality(self::JPEG_QUALITY);

                    if (defined('Imagick::INTERLACE_PLANE')) {
                        $imagick->setInterlaceScheme(Imagick::INTERLACE_PLANE);
                    }
                    break;

                case 'webp':
                    $imagick->setImageFormat('webp');
                    $imagick->setImageCompressionQuality(self::WEBP_QUALITY);
                    break;

                case 'png':
                    $imagick->setImageFormat('png');
                    $imagick->setOption('png:compression-level', '8');
                    break;
            }

            $bytes = $imagick->getImageBlob();
            $imagick->clear();
            $imagick->destroy();

            if (! is_string($bytes) || $bytes === '') {
                return null;
            }

            return [
                'name' => sanitize_file_name($fileName),
                'bytes' => $bytes,
            ];
        } catch (Throwable) {
            return [
                'name' => sanitize_file_name($fileName),
                'bytes' => $rawBytes,
            ];
        }
    }
    private function prepare_imagick_source_image(Imagick $imagick): bool
    {
        if (method_exists($imagick, 'setIteratorIndex')) {
            $imagick->setIteratorIndex(0);
        }

        if (method_exists($imagick, 'autoOrientImage')) {
            $imagick->autoOrientImage();
        }

        $page = $imagick->getImagePage();
        $hasPageOffset = ! empty($page['x']) || ! empty($page['y']);
        $hasAlpha = false;

        if (method_exists($imagick, 'getImageAlphaChannel')) {
            try {
                $hasAlpha = (bool) $imagick->getImageAlphaChannel();
            } catch (Throwable) {
                $hasAlpha = false;
            }
        }

        if ($hasAlpha || $hasPageOffset) {
            try {
                $imagick->trimImage(0);
            } catch (Throwable) {
                // Keep original canvas if trim is unsupported for this source codec.
            }
        }

        $imagick->setImagePage(0, 0, 0, 0);

        return $hasAlpha;
    }
    private function resize_imagick_to_max_dimension(Imagick $imagick, int $width, int $height): void
    {
        $maxDimension = self::MAX_SOURCE_DIMENSION;
        $scale = min($maxDimension / max($width, 1), $maxDimension / max($height, 1), 1);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $imagick->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1, true);
        $imagick->setImagePage(0, 0, 0, 0);
    }
    public static function limit_intermediate_image_sizes(array $sizes): array
    {
        return array_intersect_key($sizes, array_flip(self::ALLOWED_IMAGE_SIZES));
    }
}
