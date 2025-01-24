<?php

declare(strict_types=1);

namespace Cron\Domain\Events;

use Cron\Infrastructure\Listeners\EndCancelledSubscriptions;
use Cron\Infrastructure\Listeners\RenewSubscriptions;
use Easy\EventDispatcher\Attributes\Listener;

#[Listener(RenewSubscriptions::class)]
#[Listener(EndCancelledSubscriptions::class)]
class CronEvent
{
}
