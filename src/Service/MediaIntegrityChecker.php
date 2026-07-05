<?php declare(strict_types=1);

namespace Four\MediaIntegrityChecker\Service;

use Four\MediaIntegrityChecker\Struct\MediaIntegrityResult;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class MediaIntegrityChecker
{
    private const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityRepository $mediaRepository,
        private readonly FilesystemOperator $filesystem,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Run a full check (used by scheduled task).
     */
    public function checkAll(): void
    {
        $result = $this->check(self::DEFAULT_BATCH_SIZE, true, 0, null);

        $this->logger->info('Scheduled media integrity check completed', $result->toArray());
    }

    /**
     * Check media files and optionally thumbnails with batching.
     *
     * @param int $batchSize Number of media per batch
     * @param bool $checkThumbnails Whether to check thumbnail files
     * @param int $limit Max total media to check (0 = all)
     * @param callable|null $progressCallback Called with (checkedCount, totalCount) after each batch
     */
    public function check(
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        bool $checkThumbnails = true,
        int $limit = 0,
        ?callable $progressCallback = null,
    ): MediaIntegrityResult {
        $context = Context::createCLIContext();
        $result = new MediaIntegrityResult();

        // Get total media count for progress reporting
        $totalCriteria = new Criteria();
        $totalCriteria->setLimit(1);
        $totalCount = $this->mediaRepository->searchIds($totalCriteria, $context)->getTotal();
        $result->totalCount = $totalCount;

        $this->logger->info('Starting media integrity check', [
            'totalMedia' => $totalCount,
            'batchSize' => $batchSize,
            'checkThumbnails' => $checkThumbnails,
            'limit' => $limit,
        ]);

        $offset = 0;
        $checkedSoFar = 0;

        while (true) {
            $criteria = new Criteria();
            $criteria->setLimit($batchSize);
            $criteria->setOffset($offset);
            $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));

            if ($checkThumbnails) {
                $criteria->addAssociation('thumbnails');
            }

            $mediaIds = $this->mediaRepository->searchIds($criteria, $context);

            if (empty($mediaIds->getIds())) {
                break;
            }

            $entities = $this->mediaRepository->search($criteria, $context);

            /** @var MediaEntity $media */
            foreach ($entities as $media) {
                $checkedSoFar++;
                $result->checkedCount++;

                // Skip media entries without actual file data
                if (!$media->hasFile()) {
                    $result->skippedNoFile++;
                    continue;
                }

                $path = $media->getPath();
                if ($path === null || $path === '') {
                    $result->skippedNoFile++;
                    continue;
                }

                // Skip external URLs (e.g., CDN-hosted files that reference http URLs directly)
                if (\str_starts_with($path, 'http://') || \str_starts_with($path, 'https://')) {
                    $result->skippedExternal++;
                    continue;
                }

                // Build the full filename including extension
                $fileName = \sprintf(
                    '%s.%s',
                    $media->getFileName() ?? 'unknown',
                    $media->getFileExtension() ?? 'unknown',
                );

                // Check if the main media file exists on the filesystem
                if (!$this->filesystem->fileExists($path)) {
                    $result->addMissingFile($media->getId(), $fileName, $path);
                    $this->logger->warning('Media file missing', [
                        'mediaId' => $media->getId(),
                        'fileName' => $fileName,
                        'path' => $path,
                    ]);
                }

                // Check thumbnails if enabled and available
                if ($checkThumbnails) {
                    $thumbnails = $media->getThumbnails();
                    if ($thumbnails !== null && $thumbnails->count() > 0) {
                        foreach ($thumbnails as $thumbnail) {
                            $thumbPath = $thumbnail->getPath();
                            if ($thumbPath === null || $thumbPath === '') {
                                continue;
                            }

                            if (!$this->filesystem->fileExists($thumbPath)) {
                                $thumbSize = \sprintf(
                                    '%dx%d',
                                    $thumbnail->getWidth(),
                                    $thumbnail->getHeight(),
                                );
                                $result->addMissingThumbnail($media->getId(), $thumbSize, $thumbPath);
                                $this->logger->warning('Media thumbnail missing', [
                                    'mediaId' => $media->getId(),
                                    'thumbnailSize' => $thumbSize,
                                    'path' => $thumbPath,
                                ]);
                            }
                        }
                    }
                }

                // Stop if limit reached
                if ($limit > 0 && $checkedSoFar >= $limit) {
                    break 2;
                }
            }

            if ($progressCallback !== null) {
                $progressCallback($checkedSoFar, $totalCount);
            }

            $offset += $batchSize;

            // Break if we've fetched fewer than batch size (last page)
            if (\count($mediaIds->getIds()) < $batchSize) {
                break;
            }
        }

        $this->logger->info('Media integrity check completed', $result->toArray());

        return $result;
    }
}
