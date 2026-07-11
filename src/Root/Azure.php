<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Azure\AzureOptions;
use AiSdk\Azure\AzureProvider;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Contracts\TextModelInterface;

final class Azure
{
    private static ?AzureProvider $default = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public static function create(array $config = []): AzureProvider
    {
        return self::$default = new AzureProvider(AzureOptions::fromArray($config));
    }

    public static function default(): AzureProvider
    {
        return self::$default ??= self::create();
    }

    public static function reset(): void
    {
        self::$default = null;
    }

    public static function model(string $modelId): TextModelInterface
    {
        return self::default()->textModel($modelId);
    }

    public static function image(string $modelId): ImageModelInterface
    {
        return self::default()->imageModel($modelId);
    }

    public static function speech(string $modelId): SpeechModelInterface
    {
        return self::default()->speechModel($modelId);
    }
}
