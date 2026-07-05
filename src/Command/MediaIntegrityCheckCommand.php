<?php declare(strict_types=1);

namespace Four\MediaIntegrityChecker\Command;

use Four\MediaIntegrityChecker\Service\MediaIntegrityChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'media:integrity:check',
    description: 'Check media files and thumbnails for availability on the filesystem',
)]
class MediaIntegrityCheckCommand extends Command
{
    public function __construct(
        private readonly MediaIntegrityChecker $checker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'batch-size',
            'b',
            InputOption::VALUE_REQUIRED,
            'Number of media to process per batch',
            100,
        );
        $this->addOption(
            'no-thumbnails',
            null,
            InputOption::VALUE_NONE,
            'Skip thumbnail checks',
        );
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_REQUIRED,
            'Limit total number of media to check (0 = all)',
            0,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = (int) $input->getOption('batch-size');
        $checkThumbnails = !$input->getOption('no-thumbnails');
        $limit = (int) $input->getOption('limit');

        $io->title('Media Integrity Check');

        $io->text(\sprintf(
            'Batch size: %d | Thumbnails: %s | Limit: %s',
            $batchSize,
            $checkThumbnails ? 'Yes' : 'No',
            $limit > 0 ? (string) $limit : 'All',
        ));

        $io->newLine();
        $io->section('Scanning media files...');

        $progressBar = null;
        $progressCallback = function (int $checked, int $total) use ($io, &$progressBar): void {
            if ($progressBar === null) {
                $progressBar = $io->createProgressBar($total);
                $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
            }
            $progressBar->setMessage(\sprintf('Checked %d of %d media', $checked, $total));
            $progressBar->setProgress($checked);
        };

        $result = $this->checker->check($batchSize, $checkThumbnails, $limit, $progressCallback);

        if ($progressBar !== null) {
            $progressBar->finish();
            $io->newLine(2);
        }

        $io->section('Results');

        $io->table(
            ['Metric', 'Value'],
            [
                ['Total media in database', (string) $result->totalCount],
                ['Media checked', (string) $result->checkedCount],
                ['Skipped (no file data)', (string) $result->skippedNoFile],
                ['Skipped (external URL)', (string) $result->skippedExternal],
                ['Missing files', (string) \count($result->missingFiles)],
                ['Missing thumbnails', (string) \count($result->missingThumbnails)],
            ],
        );

        if (\count($result->missingFiles) > 0) {
            $io->section('Missing Files');

            $rows = \array_map(
                fn (array $missing): array => [
                    \mb_substr($missing['mediaId'], 0, 8) . '...',
                    $missing['fileName'],
                    $missing['path'],
                ],
                \array_slice($result->missingFiles, 0, 50),
            );

            $io->table(['Media ID', 'File Name', 'Path'], $rows);

            if (\count($result->missingFiles) > 50) {
                $io->note(\sprintf(
                    '... and %d more missing files (see log for full list)',
                    \count($result->missingFiles) - 50,
                ));
            }
        }

        if (\count($result->missingThumbnails) > 0 && $checkThumbnails) {
            $io->section('Missing Thumbnails');

            $rows = \array_map(
                fn (array $missing): array => [
                    \mb_substr($missing['mediaId'], 0, 8) . '...',
                    $missing['thumbnailSize'],
                    $missing['path'],
                ],
                \array_slice($result->missingThumbnails, 0, 50),
            );

            $io->table(['Media ID', 'Thumbnail Size', 'Path'], $rows);

            if (\count($result->missingThumbnails) > 50) {
                $io->note(\sprintf(
                    '... and %d more missing thumbnails (see log for full list)',
                    \count($result->missingThumbnails) - 50,
                ));
            }
        }

        if (\count($result->missingFiles) === 0 && \count($result->missingThumbnails) === 0) {
            $io->success('All media files and thumbnails are present.');
        } else {
            $io->warning(\sprintf(
                'Found %d missing files and %d missing thumbnails.',
                \count($result->missingFiles),
                \count($result->missingThumbnails),
            ));
        }

        return Command::SUCCESS;
    }
}
