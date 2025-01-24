<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\App\Account;

use Easy\Http\Message\RequestMethod;
use Easy\Router\Attributes\Path;
use Easy\Router\Attributes\Route;
use Presentation\RequestHandlers\App\AppView;
use Presentation\Response\ViewResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Path('/account')]
#[Route(path: '/[email|password:name]', method: RequestMethod::GET)]
class AccountView extends AppView implements
    RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $name = $request->getAttribute('name');

        return new ViewResponse(
            '/templates/app/account/' . $name . '.twig',
        );
    }
}
