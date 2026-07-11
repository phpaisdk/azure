<?php

declare(strict_types=1);

namespace AiSdk\Azure\Models;

use AiSdk\Azure\AzureOptions;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\OpenAICompatible\ImageRequestBuilder;
use AiSdk\OpenAICompatible\ImageResponseParser;
use AiSdk\Requests\ImageRequest;
use AiSdk\Responses\ImageResponse;

final class AzureImageModel extends BaseModel implements ImageModelInterface
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

    public function generate(ImageRequest $request): ImageResponse
    {
        if ($request->seed !== null) {
            throw new InvalidArgumentException('Azure OpenAI image generation does not support the portable seed() option. Use providerOptions() for provider-specific request fields.');
        }

        $body = ImageRequestBuilder::build(
            $this->modelId,
            $this->provider(),
            $request,
            ['includeResponseFormat' => false, 'seedParameter' => null],
        );
        $payload = $this->runner($this->options->sdk)->postJson(
            $this->options->imageGenerationsUrl($this->modelId),
            $body,
            $this->options->authHeaders(),
            $this->provider(),
        );

        return ImageResponseParser::parse($payload, $this->provider());
    }

}
