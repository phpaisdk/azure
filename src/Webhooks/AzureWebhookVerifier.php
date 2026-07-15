<?php

declare(strict_types=1);

namespace AiSdk\Azure\Webhooks;

use AiSdk\Exceptions\InvalidArgumentException;
use StandardWebhooks\Webhook;
use Throwable;

/** Verifies Azure OpenAI webhook deliveries using its documented signed headers. */
final class AzureWebhookVerifier
{
    /**
     * The payload must be the exact raw request body; parsing and re-encoding it
     * before verification changes the signed bytes.
     *
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, mixed>
     */
    public static function verify(string $payload, array $headers, string $signingSecret): array
    {
        try {
            $event = (new Webhook($signingSecret))->verify($payload, self::headers($headers));
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Invalid Azure OpenAI webhook signature.', [], $exception);
        }

        if (! is_array($event)) {
            throw new InvalidArgumentException('The verified Azure OpenAI webhook payload must be a JSON object.');
        }

        return $event;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, string>
     */
    private static function headers(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = is_array($value) ? implode(' ', $value) : $value;
        }

        return $normalized;
    }
}
