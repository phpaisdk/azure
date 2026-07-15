<?php

declare(strict_types=1);

namespace AiSdk\Azure\Tests\Fakes;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeLiveHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param list<array{status: int, body: string, headers?: array<string, string>}> $responses
     */
    public function __construct(private array $responses) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses) ?? ['status' => 500, 'body' => '{"error":{"message":"No fake response queued."}}'];

        return new Response(
            $response['status'],
            $response['headers'] ?? ['Content-Type' => 'application/json'],
            $response['body'],
        );
    }

    /** @return array<string, mixed> */
    public function sentJson(int $index): array
    {
        $decoded = json_decode((string) $this->requests[$index]->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
