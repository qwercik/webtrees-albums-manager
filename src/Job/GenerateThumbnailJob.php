<?php

declare(strict_types=1);

namespace Komputeryk\Webtrees\AlbumsManager\Job;

use Fisharebest\Webtrees\Contracts\ImageFactoryInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Throwable;

class GenerateThumbnailJob
{
    private ImageFactoryInterface $imageFactory;

    public function __construct()
    {
        $this->imageFactory = Registry::imageFactory();
    }

    public function run(array $jobs): array
    {
        global $allow_generating_thumbnails;
        $allow_generating_thumbnails = true;
        $results = $this->generateThumbnails($jobs);
        $allow_generating_thumbnails = false;
        return $results;
    }

    private function generateThumbnails(array $jobs): array
    {
        $paths = array_map(fn($job) => $job->data['path'], $jobs);
        $media_files = $this->fetchMediaFiles($paths);

        $results = [];
        foreach ($jobs as $task) {
            $path = $task->data['path'];
            if (empty($media_files[$path])) {
                $results[$task->id] = $this->makeErrorResponse('Media file not found');
                continue;
            }

            try {
                $this->imageFactory->mediaFileThumbnailResponse(
                    $media_files[$path],
                    $task->data['width'],
                    $task->data['height'],
                    $task->data['fit'],
                    $task->data['add_watermark'],
                    $task->data['display_params'],
                );
            } catch (Throwable $e) {
                $results[$task->id] = $this->makeErrorResponse($e->getMessage());
                continue;
            }

            $results[$task->id] = $this->makeSuccessResponse();
        }

        return $results;
    }

    private function fetchMediaFiles(array $paths): array
    {
        $trees = (new TreeService(new GedcomImportService))->all();   
        $media_file_rows = DB::table('media_file')
            ->join('media', 'media_file.m_id', '=', 'media.m_id')
            ->whereIn('media_file.multimedia_file_refn', $paths)
            ->select('media_file.m_id', 'media_file.multimedia_file_refn', 'media.m_gedcom', 'media_file.m_file')
            ->get()
            ->toArray();

        $media_files = [];
        foreach ($media_file_rows as $row) {
            $path = $row->multimedia_file_refn;
            $tree = $trees->first(fn($tree) => $tree->id() === (int)$row->m_file);
            $media = new Media($row->m_id, $row->m_gedcom, null, $tree);
            $media_files[$path] = $media->mediaFiles()->first(fn($file) => $file->filename() === $path);
        }

        return $media_files;
    }

    private function makeSuccessResponse(): array
    {
        return [
            'status' => 'success',
        ];
    }

    private function makeErrorResponse(string $message): array
    {
        return [
            'status' => 'error',
            'message' => $message,
        ];
    }
}
