<?php

declare(strict_types=1);

namespace Komputeryk\Webtrees\AlbumsManager\Job;

use Exception;
use Fisharebest\Webtrees\Contracts\ImageFactoryInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Komputeryk\Webtrees\JobQueue\Job;

class GenerateThumbnailJob
{
    private ImageFactoryInterface $imageFactory;

    public function __construct()
    {
        $this->imageFactory = Registry::imageFactory();
    }

    public function run(Job $job): void
    {
        global $allow_generating_thumbnails;
        $allow_generating_thumbnails = true;

        $path = $job->params['path'];
        $media_files = $this->fetchMediaFiles([$path]);
        if (empty($media_files[$path])) {
            throw new Exception('Media file not found');
        }

        $this->imageFactory->mediaFileThumbnailResponse(
            $media_files[$path],
            $job->params['width'],
            $job->params['height'],
            $job->params['fit'],
            $job->params['add_watermark'],
            $job->params['display_params'],
        );
        $allow_generating_thumbnails = false;
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
}
