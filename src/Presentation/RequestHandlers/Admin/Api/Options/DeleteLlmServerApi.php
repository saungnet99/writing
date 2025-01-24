<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\Admin\Api\Options;

use Easy\Container\Attributes\Inject;
use Easy\Http\Message\RequestMethod;
use Easy\Router\Attributes\Route;
use Option\Application\Commands\DeleteOptionCommand;
use Presentation\Response\EmptyResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shared\Infrastructure\CommandBus\Dispatcher;

#[Route(path: '/llms/[uuid:id]', method: RequestMethod::DELETE)]
class DeleteLlmServerApi extends OptionsApi
implements RequestHandlerInterface
{
    public function __construct(
        private Dispatcher $dispatcher,

        #[Inject('option.llms')]
        private array $llms  = [],
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = $request->getAttribute('id');

        // Save the updated LLMs configuration
        $cmd = new DeleteOptionCommand('llms.' . $id);
        $this->dispatcher->dispatch($cmd);

        return new EmptyResponse();
    }
}
