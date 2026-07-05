<?php declare(strict_types=1);

namespace Four\MediaIntegrityChecker\Struct;

class MediaIntegrityResult
{
    public int $totalCount = 0;
    public int $checkedCount = 0;
    public int $skippedNoFile = 0;
    public int $skippedExternal = 0;

    /** @var array<int, array{mediaId: string, fileName: string, path: string}> */
    public array $missingFiles = [];

    /** @var array<int, array{mediaId: string, thumbnailSize: string, path: string}> */
    public array $missingThumbnails = [];

    public function addMissingFile(string $mediaId, string $fileName, string $path): void
    {
        $this->missingFiles[] = [
            'mediaId' => $mediaId,
            'fileName' => $fileName,
            'path' => $path,
        ];
    }

    public function addMissingThumbnail(string $mediaId, string $thumbnailSize, string $path): void
    {
        $this->missingThumbnails[] = [
            'mediaId' => $mediaId,
            'thumbnailSize' => $thumbnailSize,
            'path' => $path,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'totalCount' => $this->totalCount,
            'checkedCount' => $this->checkedCount,
            'skippedNoFile' => $this->skippedNoFile,
            'skippedExternal' => $this->skippedExternal,
            'missingFiles' => \count($this->missingFiles),
            'missingThumbnails' => \count($this->missingThumbnails),
        ];
    }
}
