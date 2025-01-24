<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services\StabilityAi;

use Easy\Container\Attributes\Inject;
use Http\Message\MultipartStream\MultipartStreamBuilder;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

class Client
{
    private const BASE_URL = "https://api.stability.ai";

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,

        #[Inject('version')]
        private string $version = '1.0.0',

        #[Inject('option.stabilityai.api_key')]
        private ?string $apiKey = null,
    ) {}

    /**
     * @throws InvalidArgumentException
     * @throws ClientExceptionInterface
     */
    public function sendRequest(
        string $method,
        string $path,
        array $data = [],
        array $headers = []
    ): ResponseInterface {
        $req = $this->requestFactory->createRequest(
            $method,
            self::BASE_URL . $path
        );

        $req = $req->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Stability-Client-ID', 'aikeedo')
            ->withHeader('Stability-Client-Version', trim($this->version));

        if ($this->apiKey) {
            $req = $req->withHeader('Authorization', 'Bearer ' . $this->apiKey);
        }

        foreach ($headers as $key => $value) {
            $req = $req->withHeader($key, $value);
        }

        $isMultiPart = false;
        $contentType = $req->getHeaderLine('Content-Type');
        if (str_starts_with($contentType, 'multipart/')) {
            $isMultiPart = true;
        }

        if ($isMultiPart) {
            $builder = new MultipartStreamBuilder($this->streamFactory);

            foreach ($data as $key => $value) {
                $builder->addResource($key, $value);
            }

            $multipartStream = $builder->build();
            $boundary = $builder->getBoundary();

            $req = $req
                ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
                ->withBody($multipartStream);
        } else {
            if ($data) {
                $stream = $req->getBody();
                $stream->write(json_encode($data));
                $req = $req->withBody($stream);
            }
        }

        return $this->client->sendRequest($req);
    }
}
