<?php

declare(strict_types=1);

namespace AiSdk\Azure\Models;

use AiSdk\Azure\AzureOptions;
use AiSdk\Azure\Live\AzureLiveCall;
use AiSdk\Azure\Live\AzureLiveConfiguration;
use AiSdk\Azure\Live\AzureLiveSessionDriver;
use AiSdk\Contracts\BaseModel;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\Generate;
use AiSdk\Live\ClientSecret;
use AiSdk\Live\Contracts\LiveCallModelInterface;
use AiSdk\Live\Contracts\LiveClientSecretModelInterface;
use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\LiveWebRtcModelInterface;
use AiSdk\Live\Contracts\ProviderLiveCallInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\LiveRequest;
use AiSdk\Live\WebRtcAnswer;

final class AzureLiveModel extends BaseModel implements LiveCallModelInterface, LiveClientSecretModelInterface, LiveWebRtcModelInterface
{
    public function __construct(
        private readonly string $modelId,
        private readonly AzureOptions $options,
    ) {}

    public function provider(): string
    {
        return AzureOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function createLiveSession(LiveRequest $request, TransportInterface $transport): LiveSessionDriverInterface
    {
        return new AzureLiveSessionDriver($this->modelId, $this->options, $request, $transport);
    }

    public function clientSecret(LiveRequest $request): ClientSecret
    {
        /** @var array<string, mixed> $body */
        $body = [
            'session' => AzureLiveConfiguration::forCreation($this->modelId, $request),
        ];

        $providerOptions = $request->providerOptions[AzureOptions::PROVIDER_NAME] ?? [];
        $secretOptions = $providerOptions['clientSecret'] ?? $providerOptions['client_secret'] ?? null;
        if (is_array($secretOptions)) {
            $body = array_replace_recursive($body, $secretOptions);
        }

        $response = $this->runner($this->options->sdk)->postJson(
            $this->options->liveClientSecretsUrl(),
            $body,
            $this->options->authHeaders(),
            $this->provider(),
        );

        $value = $response['value'] ?? null;
        if (! is_string($value) || $value === '') {
            throw InvalidResponseException::forProvider(
                $this->provider(),
                'Azure OpenAI returned no Realtime client secret.',
                ['response' => $response],
            );
        }

        $expiresAt = is_numeric($response['expires_at'] ?? null) ? (int) $response['expires_at'] : null;
        $session = is_array($response['session'] ?? null) ? $response['session'] : [];
        $sessionId = is_string($session['id'] ?? null) ? $session['id'] : null;

        return new ClientSecret($value, $expiresAt, $sessionId, $response);
    }

    public function webRtc(LiveRequest $request, string $offerSdp): WebRtcAnswer
    {
        $secret = $this->clientSecret($request);
        $sdk = $this->options->sdk ?? Generate::sdk();
        $httpRequest = $sdk->requestFactory->createRequest('POST', $this->options->liveCallsUrl())
            ->withBody($sdk->streamFactory->createStream($offerSdp))
            ->withHeader('Authorization', 'Bearer ' . $secret->value)
            ->withHeader('Content-Type', 'application/sdp')
            ->withHeader('Accept', 'application/sdp');

        $response = $this->runner($sdk)->sendRequest($httpRequest, $this->provider());
        $answer = (string) $response->getBody();
        if ($answer === '') {
            throw InvalidResponseException::forProvider(
                $this->provider(),
                'Azure OpenAI returned no WebRTC SDP answer.',
            );
        }

        $location = $response->getHeaderLine('Location');
        $callId = self::callIdFromLocation($location);

        return new WebRtcAnswer(
            $answer,
            $callId,
            ['location' => $location, 'status' => $response->getStatusCode()],
        );
    }

    public function call(LiveRequest $request, string $callId): ProviderLiveCallInterface
    {
        return new AzureLiveCall($callId, $this->modelId, $this->options, $request);
    }

    private static function callIdFromLocation(string $location): ?string
    {
        if ($location === '') {
            return null;
        }

        $path = parse_url($location, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $callId = basename($path);

        return $callId !== '' ? rawurldecode($callId) : null;
    }
}
