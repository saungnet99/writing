<?php

declare(strict_types=1);

namespace Affiliate\Application\CommandHandlers;

use Affiliate\Application\Commands\CountPayoutsCommand;
use Affiliate\Domain\Repositories\PayoutRepositoryInterface;

class CountPayoutsCommandHandler
{
    public function __construct(
        private PayoutRepositoryInterface $repo
    ) {}

    public function handle(CountPayoutsCommand $cmd): int
    {
        $payouts = $this->repo;

        if ($cmd->status) {
            $payouts = $payouts->filterByStatus($cmd->status);
        }

        if ($cmd->user) {
            $payouts = $payouts->filterByUser($cmd->user);
        }

        return $payouts->count();
    }
}
