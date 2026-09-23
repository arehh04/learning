<?php

namespace Tests\Feature;

use App\Services\Channels\TelegramAdapter;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TelegramAdapterTest extends TestCase
{
    public function test_parse_inbound_extracts_fields_from_a_telegram_update_payload(): void
    {
        $adapter = new TelegramAdapter('fake-token');

        $payload = [
            'update_id' => 555666777,
            'message' => [
                'message_id' => 42,
                'from' => ['id' => 999, 'first_name' => 'Alice', 'username' => 'alice'],
                'chat' => ['id' => 999, 'type' => 'private'],
                'date' => 1690000000,
                'text' => 'Hello there',
            ],
        ];

        $parsed = $adapter->parseInbound($payload);

        $this->assertSame('555666777', $parsed->externalEventId);
        $this->assertSame('999', $parsed->externalContactId);
        $this->assertSame('alice', $parsed->contactName);
        $this->assertSame('Hello there', $parsed->body);
    }

    public function test_send_posts_to_the_telegram_send_message_endpoint_and_returns_the_message_id(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200),
        ]);

        $adapter = new TelegramAdapter('fake-token');

        $result = $adapter->send('999', 'Hi back');

        $this->assertSame('42', $result->externalMessageId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org/botfake-token/sendMessage')
                && $request['chat_id'] === '999'
                && $request['text'] === 'Hi back';
        });
    }

    public function test_send_throws_when_telegram_responds_with_an_error(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad request'], 400),
        ]);

        $adapter = new TelegramAdapter('fake-token');

        $this->expectException(RuntimeException::class);

        $adapter->send('999', 'Hi');
    }
}
