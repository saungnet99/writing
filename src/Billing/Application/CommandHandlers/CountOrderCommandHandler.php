<?php

declare(strict_types=1);

namespace Billing\Application\CommandHandlers;

use Billing\Application\Commands\CountOrdersCommand;
use Billing\Domain\Repositories\OrderRepositoryInterface;

class CountOrderCommandHandler
{
    public function __construct(
        private OrderRepositoryInterface $repo
    ) {}

    public function handle(CountOrdersCommand $cmd): int
    {
        $subs = $this->repo;

        if ($cmd->status) {
            $subs = $subs->filterByStatus($cmd->status);
        }

        if ($cmd->workspace) {
            $subs = $subs->filterByWorkspace($cmd->workspace);
        }

        if ($cmd->plan) {
            $subs = $subs->filterByPlan($cmd->plan);
        }

        if ($cmd->coupon) {
            $subs = $subs->filterByCoupon($cmd->coupon);
        }

        if ($cmd->planSnapshot) {
            $subs = $subs->filterByPlanSnapshot($cmd->planSnapshot);
        }

        if ($cmd->billingCycle) {
            $subs = $subs->filterByBillingCycle($cmd->billingCycle);
        }

        return $subs->count();
    }
}
