<?php

declare(strict_types=1);

namespace Billing\Application\CommandHandlers;

use Billing\Application\Commands\ListOrdersCommand;
use Billing\Domain\Entities\OrderEntity;
use Billing\Domain\Exceptions\OrderNotFoundException;
use Billing\Domain\Repositories\OrderRepositoryInterface;
use Shared\Domain\ValueObjects\CursorDirection;
use Traversable;

class ListOrdersCommandHandler
{
    public function __construct(
        private OrderRepositoryInterface $repo,
    ) {}

    /**
     * @return Traversable<OrderEntity>
     * @throws OrderNotFoundException
     */
    public function handle(ListOrdersCommand $cmd): Traversable
    {
        $cursor = $cmd->cursor
            ? $this->repo->ofId($cmd->cursor)
            : null;

        $subs = $this->repo;

        if ($cmd->sortDirection) {
            $subs = $subs->sort($cmd->sortDirection, $cmd->sortParameter);
        }

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

        if ($cmd->maxResults) {
            $subs = $subs->setMaxResults($cmd->maxResults);
        }

        if ($cursor) {
            if ($cmd->cursorDirection == CursorDirection::ENDING_BEFORE) {
                return $subs = $subs->endingBefore($cursor);
            }

            return $subs->startingAfter($cursor);
        }

        return $subs;
    }
}
