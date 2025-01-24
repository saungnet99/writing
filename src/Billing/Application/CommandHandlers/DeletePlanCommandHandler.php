<?php

declare(strict_types=1);

namespace Billing\Application\CommandHandlers;

use Billing\Application\Commands\DeletePlanCommand;
use Billing\Domain\Events\PlanDeletedEvent;
use Billing\Domain\Exceptions\PlanNotFoundException;
use Billing\Domain\Repositories\PlanRepositoryInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class DeletePlanCommandHandler
{
    /**
     * @param PlanRepositoryInterface $repo
     * @param EventDispatcherInterface $dispatcher
     * @return void
     */
    public function __construct(
        private PlanRepositoryInterface $repo,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @param DeletePlanCommand $cmd
     * @return void
     * @throws PlanNotFoundException
     */
    public function handle(DeletePlanCommand $cmd): void
    {
        $plan = $this->repo->ofId($cmd->id);

        // Delete the plan from the repository
        $this->repo->remove($plan);

        // Dispatch the plan deleted event
        $event = new PlanDeletedEvent($plan);
        $this->dispatcher->dispatch($event);
    }
}
