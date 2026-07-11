<?php

declare(strict_types=1);

namespace AiSdk\Azure;

use AiSdk\Azure\Models\AzureEmbeddingModel;
use AiSdk\Azure\Models\AzureImageModel;
use AiSdk\Azure\Models\AzureSpeechModel;
use AiSdk\Azure\Models\AzureTextModel;
use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Contracts\EmbeddingProviderInterface;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\ImageProviderInterface;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Contracts\SpeechProviderInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TextProviderInterface;

final class AzureProvider extends BaseProvider implements EmbeddingProviderInterface, ImageProviderInterface, SpeechProviderInterface, TextProviderInterface
{
    public function __construct(public readonly AzureOptions $options) {}

    public function name(): string
    {
        return AzureOptions::PROVIDER_NAME;
    }

    public function textModel(string $modelId): TextModelInterface
    {
        return new AzureTextModel($modelId, $this->options);
    }

    public function imageModel(string $modelId): ImageModelInterface
    {
        return new AzureImageModel($modelId, $this->options);
    }

    public function speechModel(string $modelId): SpeechModelInterface
    {
        return new AzureSpeechModel($modelId, $this->options);
    }

    public function embeddingModel(string $modelId): EmbeddingModelInterface
    {
        return new AzureEmbeddingModel($modelId, $this->options);
    }
}
