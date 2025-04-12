<?php

declare(strict_types=1);

namespace Komputeryk\Webtrees\AlbumsManager\Controller;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\MediaFileService;
use Fisharebest\Webtrees\Services\PendingChangesService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Komputeryk\Webtrees\AlbumsManager\AlbumsManagerModule;
use Komputeryk\Webtrees\AlbumsManager\Helper\PathHelper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ImportAlbumsAction implements RequestHandlerInterface
{
    private MediaFileService $mediaFileService;
    private PendingChangesService $pendingChangesService;

    public function __construct(
        private AlbumsManagerModule $module
    )
    {
        $this->mediaFileService = new MediaFileService();
        $this->pendingChangesService = new PendingChangesService(new GedcomImportService);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $path = Validator::attributes($request)->array('path');
        $importFiles = Validator::parsedBody($request)->array('import_files');
        $type = Validator::parsedBody($request)->string('type', '');
        $fs = $tree->mediaFilesystem();
        $realPath = PathHelper::getRealPath($fs, $path);

        if (empty($importFiles)) {
            FlashMessages::addMessage(I18N::translate('LBL_NO_FILES_SELECTED'), 'warning');
            return redirect(route(AlbumsPage::class, [
                'tree' => $tree->name(),
                'path' => $path,
            ]));
        }

        $this->importPhotos($tree, $realPath, $importFiles, [
            'type' => $type,
        ]);

        return redirect(route(AlbumsPage::class, [
            'tree' => $tree->name(),
            'path' => $path,
        ]));
    }

    private function importPhotos(Tree $tree, string $path, array $files, array $params): void
    {
        $paths = array_map(fn($filename) => PathHelper::getPath($path, $filename), $files);
        $paths = $this->getUnimportedFiles($paths);
        natsort($paths);

        // UWAGA: póki co nie obsługuję sytuacji, kiedy importuję na raty pliki z tym samym prefiksem (które powinny trafić do tego samego obiektu media)
        $groupedPaths = $this->groupPathsByFilenamePrefix($paths);
        foreach ($groupedPaths as $currentPaths) {
            $this->createMedia($tree, $currentPaths, $params);
        }
        FlashMessages::addMessage(
            I18N::translate('LBL_IMPORT_SUCCESS', count($paths)),
            'success'
        );
    }

    private function createMedia(Tree $tree, array $paths, array $params): void
    {
        $gedcom = implode("\n", [
            '0 @@ OBJE',
            ...array_map(fn($path) => $this->prepareMediaFileGedcom($path, $params), $paths)
        ]);
        $record = $tree->createMediaObject($gedcom);
        $this->pendingChangesService->acceptRecord($record);
    }

    private function prepareMediaFileGedcom(string $path, array $params): string
    {
        return $this->mediaFileService->createMediaFileGedcom(
            file: $path,
            type: $params['type'] ?? '',
            title: PathHelper::getFilename($path),
            note: ''
        );
    }

    private function getUnimportedFiles(array $paths): array
    {
        $imported_files = DB::table('media_file')
            ->whereIn('multimedia_file_refn', $paths)
            ->pluck('multimedia_file_refn')
            ->all();

        return array_diff($paths, $imported_files);
    }

    private function groupPathsByFilenamePrefix(array $paths): array
    {
        $grouped = [];
        foreach ($paths as $path) {
            $filename = PathHelper::getFilename($path);
            $prefix = PathHelper::extractPrefix($filename);
            $grouped[$prefix][] = $path;
        }

        return $grouped;
    }
}
