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
    name: 'media:integrity:fix',
    description: 'Check and fix: delete media/thumbnail database entries whose files are missing',
)]
class MediaIntegrityFixCommand extends Command
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
            'Skip thumbnail checks/fixes',
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
        $fixThumbnails = !$input->getOption('no-thumbnails');
        $limit = (int) $input->getOption('limit');

        $io->title('Media Integrity Fix');

        $io->warning([
            'This command will DELETE database entries for missing files:',
            '  • Missing media files → MediaEntity will be DELETED (unrecoverable)',
            '  • Missing thumbnail files → MediaThumbnailEntity will be DELETED (auto-regenerated on next access)',
        ]);

        if (!$io->confirm('Are you sure you want to proceed?', false)) {
            $io->note('Aborted.');

            return Command::SUCCESS;
        }

        $io->newLine();
        $io->section('Scanning and fixing...');

        $progressBar = null;
        $progressCallback = function (int $checked, int $total) use ($io, &$progressBar): void {
            if ($progressBar === null) {
                $progressBar = $io->createProgressBar($total);
                $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
            }
            $progressBar->setMessage(\sprintf('Checked %d of %d media', $checked, $total));
            $progressBar->setProgress($checked);
        };

        $result = $this->checker->fix($batchSize, $fixThumbnails, $limit, $progressCallback);

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
                ['Missing files found', (string) \count($result->missingFiles)],
                ['Missing thumbnails found', (string) \count($result->missingThumbnails)],
                ['Media entities deleted', (string) $result->deletedMedia],
                ['Thumbnail entities deleted', (string) $result->deletedThumbnails],
                ['Skipped (still referenced)', (string) $result->skippedReferenced],
            ],
        );

        if ($result->deletedMedia === 0 && $result->deletedThumbnails === 0) {
            $io->success('No orphaned entries found — nothing to fix.');
        } else {
            $io->success(\sprintf(
                'Fixed: %d media + %d thumbnail entries deleted. Missing thumbnails will be regenerated on next request.',
                $result->deletedMedia,
                $result->deletedThumbnails,
            ));
        }

        return Command::SUCCESS;
    }
}
