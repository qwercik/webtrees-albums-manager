<?php

declare(strict_types=1);

namespace Komputeryk\Webtrees\AlbumsManager\Controller;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Http\RequestHandlers\LoginPage;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\MediaFile;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Komputeryk\Webtrees\AlbumsManager\AlbumsManagerModule;
use Komputeryk\Webtrees\AlbumsManager\Helper\PathHelper;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

enum EntryType {
    case File;
    case Directory;
}

final class AlbumsPage implements RequestHandlerInterface
{
    use ViewResponseTrait;

    private const CURRENT_VIEW_KEY = 'albums_current_view';
    private const DEFAULT_VIEW = 'browse';
    private const AVAILABLE_VIEWS = ['browse', 'manage'];

    public function __construct(private AlbumsManagerModule $module) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::check()) {
            return redirect(route(LoginPage::class));
        }

        $tree = Validator::attributes($request)->tree();
        $path = Validator::attributes($request)->array('path');

        $view = Validator::queryParams($request)->string('view', '');
        if (in_array($view, self::AVAILABLE_VIEWS, true)) {
            $_SESSION[self::CURRENT_VIEW_KEY] = $view;
        } elseif ($view !== '') {
            unset($_SESSION[self::CURRENT_VIEW_KEY]);
        }

        if ($view !== '') {
            return redirect(route(AlbumsPage::class, [
                'tree' => $tree->name(),
                'path' => $path,
            ]));
        }

        if (PathHelper::removeTrailingSlashes($path)) {
            return redirect(route(AlbumsPage::class, [
                'tree' => $tree->name(),
                'path' => $path,
            ]));
        }

        $fs = $tree->mediaFilesystem();
        $realPath = PathHelper::getRealPath($fs, $path);
        $files = $this->getEntries($fs, $realPath, EntryType::File);
        $directories = $this->getEntries($fs, $realPath, EntryType::Directory);
        $this->natsort($files);
        $this->natsort($directories);

        $pattern = empty($realPath) ? '' : "{$realPath}/";
        $media_data = DB::table('media_file')
            ->join('media', 'media_file.m_id', '=', 'media.m_id')
            ->where('media_file.m_file', '=', $tree->id())
            ->where(fn($query) => $query
                ->where('media_file.multimedia_file_refn', 'LIKE', "{$pattern}%")
                ->where('media_file.multimedia_file_refn', 'NOT LIKE', "{$pattern}%/%")
            )
            ->orWhere('media_file.multimedia_file_refn', 'LIKE', "{$pattern}%/0.jpg")
            ->select('media_file.m_id', 'media_file.multimedia_file_refn', 'media.m_gedcom')
            ->get()
            ->toArray();

        $thumbnails = array_combine(
            array_map(fn($file) => $file->multimedia_file_refn, $media_data),
            array_map(fn($file) => $this->generateThumbnailLink($tree, $file->m_id, $file->m_gedcom, $file->multimedia_file_refn), $media_data),
        );
        $xrefs = array_combine(
            array_map(fn($file) => $file->multimedia_file_refn, $media_data),
            array_map(fn($file) => $file->m_id, $media_data),
        );

        $covers = [];
        foreach ($media_data as $file) {
            $file_path = $file->multimedia_file_refn;
            $dirname = PathHelper::getDirname($file_path);
            $covers[$dirname] = $this->generateThumbnailUrl($tree, $file->m_id, $file->m_gedcom, $file_path);
        }

        $imported_files = array_values(array_filter(
            $files,
            fn($file) => array_key_exists($file, $thumbnails)
        ));
        $other_files = array_values(array_filter($files, fn($file) => !PathHelper::isImage($file)));
        $unimported_files = array_diff($files, $imported_files, $other_files);

        return $this->viewResponse($this->module->name() . '::albums', [
            'tree' => $tree,
            'current_view' => $_SESSION[self::CURRENT_VIEW_KEY] ?? self::DEFAULT_VIEW,
            'title' => empty($path) ? I18N::translate('LBL_ALBUMS_LIST') : $realPath,
            'path' => $path,
            'real_path' => $realPath,
            'folder_icon' => $this->module->getIcon('folder.svg'),
            'unimported_icon' => $this->module->getIcon('denied.svg'),
            'directories' => array_map(fn($d) => $this->getName($realPath, $d), $directories),
            'imported_files' => array_map(fn($file) => [
                'xref' => $xrefs[$file],
                'name' => $this->getName($realPath, $file),
                'thumbnail' => $thumbnails[$file],
            ], $imported_files),
            'unimported_files' => array_map(fn($file) => $this->getName($realPath, $file), $unimported_files),
            'covers' => $covers,
        ]);
    }

    private function natsort(array &$items): void
    {
        natsort($items);
    }

    private function getName(string $basePath, string $absolutePath): string
    {
        return empty($basePath)
            ? $absolutePath
            : substr($absolutePath, strlen($basePath) + 1);
    }

    private function generateThumbnailLink(Tree $tree, string $xref, string $gedcom, string $filename): string
    {
        $mediaFile = $this->findMediaFile($tree, $xref, $gedcom, $filename);
        return $mediaFile->displayImage(200, 200, 'crop', ['class' => 'img-thumbnail img-fluid w-100', 'loading' => 'lazy']);
    }

    private function generateThumbnailUrl(Tree $tree, string $xref, string $gedcom, string $filename): string
    {
        $mediaFile = $this->findMediaFile($tree, $xref, $gedcom, $filename);
        return $mediaFile->imageUrl(200, 200, 'crop');
    }

    private function findMediaFile(Tree $tree, string $xref, string $gedcom, string $filename): MediaFile
    {
        $media = new Media($xref, $gedcom, null, $tree);
        $mediaFile = array_values(array_filter(
            $media->mediaFiles()->toArray(),
            fn($mediaFile) => $mediaFile->filename() === $filename, 
        ))[0];
        return $mediaFile;
    }

    private function getEntries(FilesystemOperator $fs, string $directory, EntryType $type): array
    {
        try {
            return $fs->listContents($directory)
                ->filter(fn($attributes) => match ($type) {
                    EntryType::File => $attributes->isFile(),
                    EntryType::Directory => $attributes->isDir(),
                })
                ->map(fn($attributes) => $attributes->path())
                ->toArray();
        } catch (FilesystemException $e) {
            return [];
        }
    }
}
