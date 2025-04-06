<?php

declare(strict_types=1);

namespace Komputeryk\Webtrees\AlbumsManager\Helper;

use Fisharebest\Webtrees\Http\Exceptions\HttpBadRequestException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\I18N;
use League\Flysystem\FilesystemOperator;

class PathHelper
{
    private const IMAGE_EXSTENSIONS = ['bmp', 'gif', 'jpeg', 'jpg', 'png', 'tif', 'tiff', 'webp'];

    public static function isImage(string $filename): bool
    {
        foreach (self::IMAGE_EXSTENSIONS as $ext) {
            if (str_ends_with($filename, ".$ext")) {
                return true;
            }
        }
        return false;
    }

    public static function removeTrailingSlashes(array &$path): bool
    {
        if (empty($path)) {
            return false;
        }

        $result = false;
        for ($i = count($path) - 1; $i >= 0; $i--) {
            if ($path[$i] !== '') {
                break;
            }

            $result = true;
            unset($path[$i]);
        }

        return $result;
    }

    public static function validatePath(FilesystemOperator $fs, array $path): void
    {
        $currentPath = '';
        foreach ($path as $dir) {
            if (empty($dir) || $dir === '..') {
                throw new HttpBadRequestException(I18N::translate('The parameter “path” is invalid.'));
            }

            $entries = $fs->listContents($currentPath)
                ->filter(fn($attributes) => $attributes->isDir())
                ->map(fn($attributes) => PathHelper::getFilename($attributes->path()))            
                ->toArray();

            if (!in_array($dir, $entries)) {
                throw new HttpNotFoundException(I18N::translate('LBL_DIR_NOT_FOUND'));
            }

            $currentPath .= "$dir/";
        }
    }

    public static function getPath(array $basePath, string $filename): string
    {
        return empty($basePath)
            ? $filename
            : implode('/', $basePath) . '/' . $filename;
    }

    public static function getFilename(string $path): string
    {
        $parts = explode('/', $path);
        return end($parts);
    }

    public static function extractPrefix(string $filename): string
    {
        $pos = strpos($filename, '.');
        return $pos === false ? $filename : substr($filename, 0, $pos);
    }
}

