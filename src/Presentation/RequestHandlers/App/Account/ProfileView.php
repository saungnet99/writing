<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\App\Account;

use Easy\Container\Attributes\Inject;
use Easy\Http\Message\RequestMethod;
use Easy\Router\Attributes\Route;
use Presentation\Response\ViewResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route(path: '/[profile]?', method: RequestMethod::GET)]
class ProfileView extends AccountView implements
    RequestHandlerInterface
{
    public function __construct(
        #[Inject('config.locale.locales')]
        private array $locales = []
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new ViewResponse(
            '/templates/app/account/profile.twig',
            [
                'locales' => $this->locales,
            ]
        );
    }
}
