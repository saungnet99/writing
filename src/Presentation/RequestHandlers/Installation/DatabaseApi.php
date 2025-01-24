<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\Installation;

use Doctrine\DBAL\DriverManager;
use Easy\Http\Message\RequestMethod;
use Easy\Router\Attributes\Route;
use Presentation\Exceptions\UnprocessableEntityException;
use Presentation\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

#[Route(path: '/database', method: RequestMethod::POST)]
class DatabaseApi extends InstallationApi implements
    RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->checkCredentials($request);
        } catch (Throwable $th) {
            throw new UnprocessableEntityException($th->getMessage());
        }
    }

    private function checkCredentials(
        ServerRequestInterface $request
    ): ResponseInterface {
        $payload = $request->getParsedBody();

        $conn = DriverManager::getConnection([
            'dbname' => $payload->name,
            'user' => $payload->user,
            'password' =>  $payload->password,
            'host' => $payload->host,
            'port' => $payload->port,
            'driver' => $payload->driver,
        ]);
        $conn->connect();

        $sm = $conn->createSchemaManager();
        $tables = $sm->listTableNames();

        $migrate = false;

        if (in_array('user', $tables)) {
            $firstUser = $conn->fetchOne('SELECT * FROM user LIMIT 1');
            // Check if user table exists and has data

            if ($firstUser !== false) {
                $migrate = true;
            }
        }

        return new JsonResponse(
            [
                'migrate' => $migrate,
                'has_data' => count($tables) > 0,
            ]
        );
    }
}
