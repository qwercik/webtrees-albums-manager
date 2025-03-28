<?php

declare(strict_types=1);

namespace Komputeryk\Webtrees\AlbumsManager\Controller;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Media;
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

    public function __construct(private AlbumsManagerModule $module) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $path = Validator::attributes($request)->array('path');
        if (PathHelper::removeTrailingSlashes($path)) {
            return redirect(route(AlbumsPage::class, [
                'tree' => $tree->name(),
                'path' => $path,
            ]));
        }

        $fs = $tree->mediaFilesystem();
        PathHelper::validatePath($fs, $path);
        $realPath = implode('/', $path);
        $files = $this->getEntries($fs, $realPath, EntryType::File);
        $directories = $this->getEntries($fs, $realPath, EntryType::Directory);
        natsort($files);
        natsort($directories);

        $pattern = empty($realPath) ? '' : "{$realPath}/";
        $media_data = DB::table('media_file')
            ->join('media', 'media_file.m_id', '=', 'media.m_id')
            ->where('media_file.m_file', '=', $tree->id())
            ->where('media_file.multimedia_file_refn', 'LIKE', "{$pattern}%")
            ->where('media_file.multimedia_file_refn', 'NOT LIKE', "{$pattern}%/%")
            ->select('media_file.m_id', 'media_file.multimedia_file_refn', 'media.m_gedcom')
            ->get()
            ->toArray();

        $thumbnails = array_combine(
            array_map(fn($file) => $file->multimedia_file_refn, $media_data),
            array_map(fn($file) => $this->generateThumbnail($tree, $file->m_id, $file->m_gedcom, $file->multimedia_file_refn), $media_data),
        );
        $xrefs = array_combine(
            array_map(fn($file) => $file->multimedia_file_refn, $media_data),
            array_map(fn($file) => $file->m_id, $media_data),
        );

        $imported_files = array_values(array_filter(
            $files,
            fn($file) => array_key_exists($file, $thumbnails)
        ));
        $other_files = array_values(array_filter($files, fn($file) => !PathHelper::isImage($file)));
        $unimported_files = array_diff($files, $imported_files, $other_files);

        return $this->viewResponse($this->module->name() . '::albums', [
            'tree' => $tree,
            'title' => I18N::translate('LBL_ALBUMS_LIST'),
            'path' => $path,
            'folder_icon' => $this->module->getIcon('folder.svg'),
            'unimported_icon' => $this->module->getIcon('denied.svg'),
            'directories' => array_map(fn($d) => $this->getName($realPath, $d), $directories),
            'imported_files' => array_map(fn($file) => [
                'xref' => $xrefs[$file],
                'name' => $this->getName($realPath, $file),
                'thumbnail' => $thumbnails[$file],
            ], $imported_files),
            'unimported_files' => array_map(fn($file) => $this->getName($realPath, $file), $unimported_files),
        ]);
    }

    private function getName(string $basePath, string $absolutePath): string
    {
        return empty($basePath)
            ? $absolutePath
            : substr($absolutePath, strlen($basePath) + 1);
    }

    private function generateThumbnail(Tree $tree, string $xref, string $gedcom, string $filename): string
    {
        $media = new Media($xref, $gedcom, null, $tree);
        $mediaFile = array_values(array_filter(
            $media->mediaFiles()->toArray(),
            fn($mediaFile) => $mediaFile->filename() === $filename, 
        ))[0];
        return $mediaFile->displayImage(200, 200, 'crop', ['class' => 'img-thumbnail img-fluid w-100']);
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
