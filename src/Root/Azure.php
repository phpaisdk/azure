<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Azure\AzureOptions;
use AiSdk\Azure\AzureProvider;
use AiSdk\Azure\Webhooks\AzureWebhookVerifier;
use AiSdk\Contracts\Model;

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

    public static function model(string $modelId): Model
    {
        return self::default()->model($modelId);
    }

    /**
     * Verify and decode an Azure OpenAI webhook from its exact raw request body.
     *
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, mixed>
     */
    public static function verifyWebhook(string $payload, array $headers, string $signingSecret): array
    {
        return AzureWebhookVerifier::verify($payload, $headers, $signingSecret);
    }
}
