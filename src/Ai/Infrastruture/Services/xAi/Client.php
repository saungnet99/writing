<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\xAi;

use Ai\Domain\Exceptions\ApiException;
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
    private string $baseUrl = 'https://api.x.ai/';

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,

        #[Inject('option.xai.api_key')]
        private ?string $apiKey = null,
    ) {}

    /**
     * @throws InvalidArgumentException
     * @throws ClientExceptionInterface
     * @throws ApiException
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
            ->createRequest($method, $baseUrl . ltrim($path, '/'))
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

        $resp = $this->client->sendRequest($req);

        if ($resp->getStatusCode() !== 200) {
            $contents = $resp->getBody()->getContents();
            $body = json_decode($contents);

            $msg = $body ? ($body->error ?? $body->code ?? 'Unexpected error occurred while communicating with xAI API') : $contents;
            throw new ApiException($msg);
        }

        return $resp;
    }
}
