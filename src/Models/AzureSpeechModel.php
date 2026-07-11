<?php

declare(strict_types=1);

namespace AiSdk\Azure\Models;

use AiSdk\Azure\AzureOptions;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\OpenAICompatible\SpeechRequestBuilder;
use AiSdk\OpenAICompatible\SpeechResponseParser;
use AiSdk\Requests\SpeechRequest;
use AiSdk\Responses\SpeechResponse;

final class AzureSpeechModel extends BaseModel implements SpeechModelInterface
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

    public function generate(SpeechRequest $request): SpeechResponse
    {
        $body = SpeechRequestBuilder::build($this->modelId, $this->provider(), $request);
        $format = (string) ($body['response_format'] ?? 'mp3');
        $mimeType = SpeechRequestBuilder::expectedMimeType($format);
        $response = $this->runner($this->options->sdk)->postRaw(
            $this->options->speechUrl($this->modelId),
            $body,
            array_replace(['Accept' => $mimeType], $this->options->authHeaders()),
            $this->provider(),
        );

        return SpeechResponseParser::parse($response, $this->provider(), $mimeType, [
            'model' => $this->modelId,
            'format' => $format,
        ]);
    }

}
