<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\Admin\Api\Reports;

use DateTime;
use Easy\Http\Message\RequestMethod;
use Easy\Router\Attributes\Route;
use Presentation\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shared\Infrastructure\CommandBus\Dispatcher;
use Stat\Application\Commands\ReadStatCommand;
use Stat\Domain\ValueObjects\StatType;

#[Route(path: '/stats', method: RequestMethod::GET)]
class StatsApi extends ReportsApi implements RequestHandlerInterface
{
    public function __construct(
        private Dispatcher $dispatcher
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $stats = [];

        foreach (StatType::cases() as $type) {
            $cmd = new ReadStatCommand($type);
            $cmd->day = new DateTime();

            $last = $this->dispatcher->dispatch($cmd);

            $cmd->day = (new DateTime())->modify('-1 day');
            $prev = $this->dispatcher->dispatch($cmd);

            if ($prev == $last) {
                $changePer = 0;
            } else {
                $changePer = $prev === 0 ? 100 : ($last === 0 ? -100 : (($last - $prev) / $prev) * 100);
            }

            $stats[$type->value] = [
                'metric' => $last,
                'change' => round($changePer, 2),
            ];
        }

        return new JsonResponse($stats);
    }
}
