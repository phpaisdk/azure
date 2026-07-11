<?php

declare(strict_types=1);

namespace AiSdk\Azure\Models;

use AiSdk\Azure\AzureOptions;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\OpenAICompatible\EmbeddingRequestBuilder;
use AiSdk\OpenAICompatible\EmbeddingResponseParser;
use AiSdk\Requests\EmbeddingRequest;
use AiSdk\Responses\EmbeddingResponse;

final class AzureEmbeddingModel extends BaseModel implements EmbeddingModelInterface
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

    public function generate(EmbeddingRequest $request): EmbeddingResponse
    {
        $body = EmbeddingRequestBuilder::build($this->modelId, $this->provider(), $request);
        if ($this->options->useDeploymentBasedUrls) {
            unset($body['model']);
        }

        $payload = $this->runner($this->options->sdk)->postJson(
            $this->options->embeddingsUrl($this->modelId),
            $body,
            $this->options->embeddingAuthHeaders(),
            $this->provider(),
        );

        return EmbeddingResponseParser::parse($payload, $this->provider(), count($request->inputs));
    }
}
