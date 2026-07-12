<?php

declare(strict_types=1);

namespace AiSdk\Azure\Models;

use AiSdk\Azure\AzureApi;
use AiSdk\Azure\AzureOptions;
use AiSdk\Capability;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\OpenAICompatible\ChatRequestBuilder;
use AiSdk\OpenAICompatible\ChatRequestProfile;
use AiSdk\OpenAICompatible\ChatResponseParser;
use AiSdk\OpenAICompatible\ChatStreamParser;
use AiSdk\OpenAICompatible\ResponsesRequestBuilder;
use AiSdk\OpenAICompatible\ResponsesResponseParser;
use AiSdk\OpenAICompatible\ResponsesStreamParser;
use AiSdk\Requests\TextModelRequest;
use AiSdk\Responses\TextModelResponse;
use Generator;

final class AzureTextModel extends BaseModel implements TextModelInterface
{
    private const array ADAPTER_CAPABILITIES = [
        Capability::TextGeneration,
        Capability::Streaming,
        Capability::ToolCalling,
        Capability::StructuredOutput,
        Capability::Reasoning,
        Capability::TextInput,
        Capability::ImageInput,
        Capability::AudioInput,
        Capability::FileInput,
    ];

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

    public function generate(TextModelRequest $request): TextModelResponse
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES);

        $api = $this->resolveApi($request);
        $body = $api === AzureApi::Responses
            ? ResponsesRequestBuilder::build($this->modelId, $this->provider(), $request, stream: false)
            : ChatRequestBuilder::build($this->modelId, $this->provider(), $request, stream: false, profile: ChatRequestProfile::azure());
        unset($body['api']);
        $url = $api === AzureApi::Responses
            ? $this->options->responsesUrl()
            : $this->options->chatCompletionsUrl($this->modelId);

        $payload = $this->runner($this->options->sdk)
            ->postJson($url, $body, $this->options->authHeaders(), $this->provider());

        return $api === AzureApi::Responses
            ? ResponsesResponseParser::parse($payload, $this->provider())
            : ChatResponseParser::parse($payload, $this->provider());
    }

    public function stream(TextModelRequest $request): Generator
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES, streaming: true);

        $api = $this->resolveApi($request);
        $body = $api === AzureApi::Responses
            ? ResponsesRequestBuilder::build($this->modelId, $this->provider(), $request, stream: true)
            : ChatRequestBuilder::build($this->modelId, $this->provider(), $request, stream: true, profile: ChatRequestProfile::azure());
        unset($body['api']);
        $url = $api === AzureApi::Responses
            ? $this->options->responsesUrl()
            : $this->options->chatCompletionsUrl($this->modelId);

        $events = $this->runner($this->options->sdk)
            ->postStream($url, $body, $this->options->authHeaders(), $this->provider());

        yield from $api === AzureApi::Responses
            ? ResponsesStreamParser::parse($events, $this->provider())
            : ChatStreamParser::parse($events, $this->provider());
    }

    private function resolveApi(TextModelRequest $request): AzureApi
    {
        $override = $request->providerOptionsFor($this->provider())['api'] ?? null;

        return $override === null ? $this->options->api : AzureApi::resolve($override);
    }

}
