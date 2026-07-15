<?php

declare(strict_types=1);

namespace AiSdk\Azure\Tests\Fakes;

use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\TransportEndpoint;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\WebSocketEndpoint;

final class FakeLiveTransport implements TransportInterface
{
    public ?TransportEndpoint $endpoint = null;

    public readonly FakeLiveConnection $connection;

    /** @param list<TransportFrame> $incoming */
    public function __construct(array $incoming = [], bool $acknowledgeSession = true)
    {
        if ($acknowledgeSession) {
            array_unshift($incoming, TransportFrame::text('{"type":"session.updated"}'));
        }

        $this->connection = new FakeLiveConnection($incoming);
    }

    public function supports(TransportEndpoint $endpoint): bool
    {
        return $endpoint instanceof WebSocketEndpoint;
    }

    public function connect(TransportEndpoint $endpoint): TransportConnectionInterface
    {
        $this->endpoint = $endpoint;

        return $this->connection;
    }
}

final class FakeLiveConnection implements TransportConnectionInterface
{
    /** @var list<TransportFrame> */
    public array $sent = [];

    private bool $closed = false;

    /** @param list<TransportFrame> $incoming */
    public function __construct(private array $incoming = []) {}

    public function send(TransportFrame $frame): void
    {
        $this->sent[] = $frame;
    }

    public function receive(): ?TransportFrame
    {
        return array_shift($this->incoming);
    }

    public function finishSending(): void {}

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** @return array<string, mixed> */
    public function sentJson(int $index): array
    {
        $decoded = json_decode($this->sent[$index]->payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
