<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\Cohere;

use Easy\Container\Attributes\Inject;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use function \safe_json_encode;

class Client
{
    private string $baseUrl = 'https://api.cohere.com/v1/';

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,

        #[Inject('option.cohere.api_key')]
        private ?string $apiKey = null,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws ClientExceptionInterface
     */
    public function sendRequest(
        string $method,
        string $path,
        array $body = [],
        array $params = [],
        array $headers = []
    ): ResponseInterface {
        $baseUrl = $this->baseUrl;

        $req = $this->requestFactory
            ->createRequest($method, $baseUrl . trim($path, '/'))
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/json');

        if ($body) {
            $req = $req
                ->withBody($this->streamFactory->createStream(
                    safe_json_encode($body, JSON_THROW_ON_ERROR)
                ));
        }

        if ($params) {
            $req = $req->withUri(
                $req->getUri()->withQuery(http_build_query($params))
            );
        }

        if ($headers) {
            foreach ($headers as $key => $value) {
                $req = $req->withHeader($key, $value);
            }
        }

        return $this->client->sendRequest($req);
    }
}
