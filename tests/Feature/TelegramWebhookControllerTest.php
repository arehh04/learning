<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Message;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

function telegramUpdate(int $updateId = 800): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => 1,
            'from' => ['id' => 555, 'first_name' => 'Dana'],
            'chat' => ['id' => 555, 'type' => 'private'],
            'date' => 1690000000,
            'text' => 'Hi there',
        ],
    ];
}

class TelegramWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.webhook_secret' => 'test-secret']);
    }

    public function test_webhook_rejects_requests_with_a_missing_or_invalid_secret_token(): void
    {
        Channel::factory()->create(['type' => 'telegram']);

        $response = $this->postJson('/webhooks/telegram', telegramUpdate(), [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, Message::count());
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_webhook_persists_a_message_when_the_secret_token_is_valid(): void
    {
        Channel::factory()->create(['type' => 'telegram']);

        $response = $this->postJson('/webhooks/telegram', telegramUpdate(updateId: 900), [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ]);

        $response->assertStatus(200);
        $this->assertSame(1, Message::count());
    }

    public function test_webhook_is_idempotent_for_a_redelivered_update(): void
    {
        Channel::factory()->create(['type' => 'telegram']);
        $payload = telegramUpdate(updateId: 901);

        $this->postJson('/webhooks/telegram', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']);
        $this->postJson('/webhooks/telegram', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']);

        $this->assertSame(1, Message::count());
    }
}
