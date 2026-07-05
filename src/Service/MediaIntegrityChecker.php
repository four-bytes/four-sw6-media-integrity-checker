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
        private readonly EntityRepository $thumbnailRepository,
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

        $totalCriteria = new Criteria();
        $totalCriteria->setLimit(1);
        $totalCount = $this->mediaRepository->searchIds($totalCriteria, $context)->getTotal();
        $result->totalCount = $totalCount;

        if ($limit > 0) {
            $totalCount = \min($totalCount, $limit);
            $result->totalCount = $totalCount;
        }

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

                if (!$media->hasFile()) {
                    $result->skippedNoFile++;
                    continue;
                }

                $path = $media->getPath();
                if ($path === null || $path === '') {
                    $result->skippedNoFile++;
                    continue;
                }

                if (\str_starts_with($path, 'http://') || \str_starts_with($path, 'https://')) {
                    $result->skippedExternal++;
                    continue;
                }

                $fileName = \sprintf(
                    '%s.%s',
                    $media->getFileName() ?? 'unknown',
                    $media->getFileExtension() ?? 'unknown',
                );

                if (!$this->filesystem->fileExists($path)) {
                    $result->addMissingFile($media->getId(), $fileName, $path);
                    $this->logger->warning('Media file missing', [
                        'mediaId' => $media->getId(),
                        'fileName' => $fileName,
                        'path' => $path,
                    ]);
                }

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
                                $result->addMissingThumbnail(
                                    $media->getId(),
                                    $thumbnail->getId(),
                                    $thumbSize,
                                    $thumbPath,
                                );
                                $this->logger->warning('Media thumbnail missing', [
                                    'mediaId' => $media->getId(),
                                    'thumbnailId' => $thumbnail->getId(),
                                    'thumbnailSize' => $thumbSize,
                                    'path' => $thumbPath,
                                ]);
                            }
                        }
                    }
                }

                if ($limit > 0 && $checkedSoFar >= $limit) {
                    break 2;
                }
            }

            if ($limit > 0 && $checkedSoFar >= $limit) {
                break;
            }

            if ($progressCallback !== null) {
                $progressCallback($checkedSoFar, $totalCount);
            }

            $offset += $batchSize;

            if (\count($mediaIds->getIds()) < $batchSize) {
                break;
            }
        }

        $this->logger->info('Media integrity check completed', $result->toArray());

        return $result;
    }

    /**
     * Run integrity check and automatically delete entities with missing files.
     *
     * - Missing main file → deletes the MediaEntity (file is unrecoverable)
     * - Missing thumbnail file (main file exists) → deletes MediaThumbnailEntity (Shopware auto-regenerates)
     *
     * @param int $batchSize Number of media per batch
     * @param bool $fixThumbnails Whether to delete missing thumbnail entities
     * @param int $limit Max total media to check (0 = all)
     * @param callable|null $progressCallback Called with (checkedCount, totalCount) after each batch
     */
    public function fix(
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        bool $fixThumbnails = true,
        int $limit = 0,
        ?callable $progressCallback = null,
    ): MediaIntegrityResult {
        $result = $this->check($batchSize, true, $limit, $progressCallback);
        $context = Context::createCLIContext();

        $deletedMedia = 0;
        $skippedReferenced = 0;
        $deletedThumbnails = 0;

        // Delete media entities individually — Shopware FK constraints protect referenced ones
        if (\count($result->missingFiles) > 0) {
            $this->logger->info('Fix mode: deleting media entities with missing files', [
                'count' => \count($result->missingFiles),
            ]);

            foreach ($result->missingFiles as $missing) {
                try {
                    $this->mediaRepository->delete([['id' => $missing['mediaId']]], $context);
                    $deletedMedia++;
                    $this->logger->warning('Deleted media entity (file missing on filesystem)', [
                        'mediaId' => $missing['mediaId'],
                        'fileName' => $missing['fileName'],
                    ]);
                } catch (\Throwable $e) {
                    $skippedReferenced++;
                    $this->logger->info('Skipped media deletion — entity still referenced (FK constraint)', [
                        'mediaId' => $missing['mediaId'],
                        'fileName' => $missing['fileName'],
                    ]);
                }
            }
        }

        // Delete thumbnail entities individually
        if ($fixThumbnails && \count($result->missingThumbnails) > 0) {
            $this->logger->info('Fix mode: deleting thumbnail entities with missing files', [
                'count' => \count($result->missingThumbnails),
            ]);

            foreach ($result->missingThumbnails as $missing) {
                try {
                    $this->thumbnailRepository->delete([['id' => $missing['thumbnailId']]], $context);
                    $deletedThumbnails++;
                    $this->logger->warning('Deleted thumbnail entity (will be regenerated on next access)', [
                        'mediaId' => $missing['mediaId'],
                        'thumbnailId' => $missing['thumbnailId'],
                        'thumbnailSize' => $missing['thumbnailSize'],
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->info('Skipped thumbnail deletion — entity still referenced', [
                        'thumbnailId' => $missing['thumbnailId'],
                        'thumbnailSize' => $missing['thumbnailSize'],
                    ]);
                }
            }
        }

        $result->deletedMedia = $deletedMedia;
        $result->deletedThumbnails = $deletedThumbnails;
        $result->skippedReferenced = $skippedReferenced;

        $this->logger->info('Fix mode completed', [
            'deletedMedia' => $deletedMedia,
            'deletedThumbnails' => $deletedThumbnails,
            'skippedReferenced' => $skippedReferenced,
        ]);

        return $result;
    }
}
