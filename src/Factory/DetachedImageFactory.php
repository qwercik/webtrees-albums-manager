<?php

declare(strict_types=1);

namespace Komputeryk\Webtrees\AlbumsManager\Factory;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Factories\ImageFactory;
use Fisharebest\Webtrees\MediaFile;
use Fisharebest\Webtrees\Registry;
use Intervention\Gif\Exceptions\NotReadableException;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use Psr\Http\Message\ResponseInterface;
use Komputeryk\Webtrees\AlbumsManager\Exception\ThumbnailNotGeneratedYetException;
use Throwable;

class DetachedImageFactory extends ImageFactory
{
    public function mediaFileThumbnailResponse(
        MediaFile $media_file,
        int $width,
        int $height,
        string $fit,
        bool $add_watermark,
        string $display_params,
    ): ResponseInterface {
        // Where are the images stored.
        $filesystem = $media_file->media()->tree()->mediaFilesystem();

        // Where is the image stored in the filesystem.
        $path = $media_file->filename();

        try {
            $mime_type = $filesystem->mimeType(path: $path);

            $key = implode(separator: ':', array: [
                $media_file->media()->tree()->name(),
                $path,
                $filesystem->lastModified(path: $path),
                (string) $width,
                (string) $height,
                $fit,
                (string) $add_watermark,
                $display_params,
            ]);

            $closure = function () use ($filesystem, $path, $width, $height, $fit, $add_watermark, $media_file, $display_params): string {
                global $allow_generating_thumbnails;
                $jobParams = [
                    'path' => $path,
                    'width' => $width,
                    'height' => $height,
                    'fit' => $fit,
                    'add_watermark' => $add_watermark,
                    'display_params' => $display_params,
                ];
                if (empty($allow_generating_thumbnails) && $this->requiresDetachedGenerating($jobParams)) {
                    throw new ThumbnailNotGeneratedYetException;
                }

                $image = $this->imageManager()->read(input: $filesystem->readStream($path));

                $display_params = $this->parseDisplayParams($display_params);
                if (isset($display_params['crop'])) {
                    list($x1, $y1, $x2, $y2) = explode(',', $display_params['crop']);
                    $image = $image->crop(
                        (int)$x2 - (int)$x1,
                        (int)$y2 - (int)$y1,
                        (int)$x1,
                        (int)$y1,
                    );
                }

                $image = $this->resizeImage(image: $image, width: $width, height: $height, fit: $fit);

                if ($add_watermark) {
                    $watermark = $this->createWatermark(width: $image->width(), height: $image->height(), media_file: $media_file);
                    $image     = $this->addWatermark(image: $image, watermark: $watermark);
                }

                $quality = $this->extractImageQuality(image: $image, default:  static::GD_DEFAULT_THUMBNAIL_QUALITY);

                return $image->encodeByMediaType(type: $media_file->mimeType(), quality: $quality)->toString();
            };

            // Images and Responses both contain resources - which cannot be serialized.
            // So cache the raw image data.
            $data = Registry::cache()->file()->remember(key: $key, closure: $closure, ttl: static::THUMBNAIL_CACHE_TTL);

            return $this->imageResponse(data: $data, mime_type:  $mime_type, filename:  '');
        } catch (ThumbnailNotGeneratedYetException) {
            return $this->replacementImageResponse(text: '⏳')
                ->withHeader('x-thumbnail-exception', 'Thumbnail not generated yet');
        } catch (NotReadableException $ex) {
            return $this
                ->replacementImageResponse(text: '.' . pathinfo(path: $path, flags:  PATHINFO_EXTENSION))
                ->withHeader('x-thumbnail-exception', get_class(object: $ex) . ': ' . $ex->getMessage());
        } catch (FilesystemException | UnableToReadFile $ex) {
            return $this
                ->replacementImageResponse(text: (string) StatusCodeInterface::STATUS_NOT_FOUND)
                ->withHeader('x-thumbnail-exception', get_class(object: $ex) . ': ' . $ex->getMessage());
        } catch (Throwable $ex) {
            return $this
                ->replacementImageResponse(text: (string) StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR)
                ->withHeader('x-thumbnail-exception', get_class(object: $ex) . ': ' . $ex->getMessage());
        }
    }

    private function requiresDetachedGenerating(array $params): bool
    {
        return $params['width'] === 200
            && $params['height'] === 200
            && $params['fit'] === 'crop'
            && $params['add_watermark'] === false
            && $params['display_params'] === '';
    }
}
