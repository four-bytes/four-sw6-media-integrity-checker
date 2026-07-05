<?php declare(strict_types=1);

namespace Four\MediaIntegrityChecker\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class MediaIntegrityCheckTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'four_media_integrity_check';
    }

    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
