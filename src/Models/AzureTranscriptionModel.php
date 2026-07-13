<?php

declare(strict_types=1);

namespace AiSdk\Azure\Models;

use AiSdk\Azure\AzureOptions;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\TranscriptionModelInterface;
use AiSdk\Generate;
use AiSdk\OpenAICompatible\TranscriptionRequestBuilder;
use AiSdk\OpenAICompatible\TranscriptionResponseParser;
use AiSdk\Requests\TranscriptionRequest;
use AiSdk\Responses\TranscriptionResponse;

final class AzureTranscriptionModel extends BaseModel implements TranscriptionModelInterface
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

    public function transcribe(TranscriptionRequest $request): TranscriptionResponse
    {
        $sdk = $this->options->sdk ?? Generate::sdk();
        $multipart = TranscriptionRequestBuilder::build($this->modelId, $this->provider(), $request);
        $httpRequest = $sdk->requestFactory->createRequest('POST', $this->options->transcriptionUrl($this->modelId))
            ->withBody($sdk->streamFactory->createStream($multipart['body']))
            ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $multipart['boundary'])
            ->withHeader('Accept', 'application/json');

        foreach ($this->options->authHeaders() as $name => $value) {
            $httpRequest = $httpRequest->withHeader($name, $value);
        }

        $response = $this->runner($sdk)->sendRequest($httpRequest, $this->provider());

        return TranscriptionResponseParser::parse($response, $this->provider(), ['model' => $this->modelId]);
    }
}
