<?php declare(strict_types=1);

namespace Four\MediaIntegrityChecker\ScheduledTask;

use Four\MediaIntegrityChecker\Service\MediaIntegrityChecker;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: MediaIntegrityCheckTask::class)]
class MediaIntegrityCheckTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly MediaIntegrityChecker $checker,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $this->checker->checkAll();
    }
}
