<?php

declare(strict_types=1);

namespace Presentation\Middlewares;

use Presentation\Exceptions\UnauthorizedException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use User\Domain\Entities\UserEntity;
use User\Domain\ValueObjects\Role;

class DemoEnvironmentMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        /** @var ?UserEntity */
        $user = $request->getAttribute(UserEntity::class);

        if (
            env('DEMO_SUPERADMIN_EMAIL')
            && $user && $user->getRole() == Role::ADMIN
            && $user->getEmail()->value == env('DEMO_SUPERADMIN_EMAIL')
        ) {
            return $handler->handle($request);
        }

        if (
            env('ENVIRONMENT') == 'demo'
            && $request->getMethod() != 'GET'
        ) {
            throw new UnauthorizedException('This feature is disabled in demo environment.');
        }

        return $handler->handle($request);
    }
}
