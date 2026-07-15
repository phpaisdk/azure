<?php

declare(strict_types=1);

namespace AiSdk\Azure\Live;

use AiSdk\Azure\AzureOptions;
use AiSdk\Generate;
use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\ProviderLiveCallInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\LiveRequest;
use AiSdk\Utils\Http\HttpRunner;

/** Azure provider-hosted SIP/WebRTC call lifecycle and sideband attachment. */
final readonly class AzureLiveCall implements ProviderLiveCallInterface
{
    public function __construct(
        private string $callId,
        private string $modelId,
        private AzureOptions $options,
        private LiveRequest $request,
    ) {}

    public function id(): string
    {
        return $this->callId;
    }

    public function accept(): void
    {
        $this->runner()->postRaw(
            $this->options->liveCallActionUrl($this->callId, 'accept'),
            AzureLiveConfiguration::forCreation($this->modelId, $this->request),
            $this->options->authHeaders(),
            AzureOptions::PROVIDER_NAME,
        );
    }

    public function connect(TransportInterface $transport): LiveSessionDriverInterface
    {
        return new AzureLiveSessionDriver(
            $this->modelId,
            $this->options,
            $this->request,
            $transport,
            $this->callId,
        );
    }

    public function hangup(): void
    {
        $this->runner()->postRaw(
            $this->options->liveCallActionUrl($this->callId, 'hangup'),
            [],
            $this->options->authHeaders(),
            AzureOptions::PROVIDER_NAME,
        );
    }

    private function runner(): HttpRunner
    {
        return HttpRunner::fromSdk($this->options->sdk ?? Generate::sdk());
    }
}
