<?php

namespace App\Services\Channels;

use App\Contracts\ChannelAdapter;
use App\Support\ParsedInboundMessage;
use App\Support\SentMessageResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramAdapter implements ChannelAdapter
{
    public function __construct(private readonly string $botToken)
    {
    }

    public function parseInbound(array $payload): ParsedInboundMessage
    {
        $message = $payload['message'] ?? throw new RuntimeException('Update payload missing "message" key.');

        return new ParsedInboundMessage(
            externalEventId: (string) $payload['update_id'],
            externalContactId: (string) $message['chat']['id'],
            contactName: $message['from']['username'] ?? $message['from']['first_name'] ?? null,
            body: $message['text'] ?? '',
            rawPayload: $payload,
        );
    }

    public function send(string $externalContactId, string $body): SentMessageResult
    {
        $response = Http::post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
            'chat_id' => $externalContactId,
            'text' => $body,
        ]);

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            throw new RuntimeException('Telegram sendMessage failed: '.$response->body());
        }

        return new SentMessageResult(
            externalMessageId: (string) $response->json('result.message_id'),
        );
    }
}
