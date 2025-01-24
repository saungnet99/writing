<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\App;

use Easy\Container\Attributes\Inject;
use Easy\Http\Message\RequestMethod;
use Easy\Router\Attributes\Route;
use Presentation\Response\RedirectResponse;
use Presentation\Response\ViewResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route(path: '/assistants', method: RequestMethod::GET)]
class AssistantsView extends AppView implements
    RequestHandlerInterface
{
    public function __construct(
        #[Inject('option.features.chat.is_enabled')]
        private bool $isEnabled = false
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isEnabled) {
            return new RedirectResponse('/app');
        }

        return new ViewResponse(
            '/templates/app/assistants.twig',
        );
    }
}
