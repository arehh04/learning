<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channels\TelegramAdapter;
use App\Services\MessageIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

function ingestionPayload(int $updateId, int $chatId = 42, string $text = 'Hi'): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => 1,
            'from' => ['id' => $chatId, 'first_name' => 'Bob'],
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'date' => 1690000000,
            'text' => $text,
        ],
    ];
}

class MessageIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_telegram_update_produces_exactly_one_message(): void
    {
        $channel = Channel::factory()->create();
        $adapter = new TelegramAdapter('fake-token');
        $service = app(MessageIngestionService::class);

        $parsed = $adapter->parseInbound(ingestionPayload(111));

        $first = $service->ingest($channel, $parsed);
        $second = $service->ingest($channel, $parsed);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, Message::count());
    }

    public function test_a_closed_conversation_is_reopened_by_a_new_inbound_message_instead_of_forking(): void
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['channel_id' => $channel->id, 'external_contact_id' => '42']);
        $conversation = Conversation::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'status' => Conversation::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        $adapter = new TelegramAdapter('fake-token');
        $service = app(MessageIngestionService::class);

        $parsed = $adapter->parseInbound(ingestionPayload(222, chatId: 42, text: 'I am back'));

        $service->ingest($channel, $parsed);

        $this->assertSame(1, Conversation::count());

        $conversation->refresh();
        $this->assertSame(Conversation::STATUS_OPEN, $conversation->status);
        $this->assertNull($conversation->closed_at);
    }

    public function test_a_brand_new_contact_starts_a_new_ai_owned_open_conversation(): void
    {
        config(['services.ai_agent.enabled' => true]);
        Queue::fake();

        $channel = Channel::factory()->create();
        $adapter = new TelegramAdapter('fake-token');
        $service = app(MessageIngestionService::class);

        $parsed = $adapter->parseInbound(ingestionPayload(333, chatId: 77, text: 'Hello'));

        $service->ingest($channel, $parsed);

        $conversation = Conversation::first();
        $this->assertSame(Conversation::OWNER_AI, $conversation->owner_type);
        $this->assertSame(Conversation::STATUS_OPEN, $conversation->status);

        Queue::assertPushed(\App\Jobs\GenerateAiReplyJob::class);
    }

    public function test_disabling_the_ai_agent_routes_new_conversations_to_unassigned(): void
    {
        config(['services.ai_agent.enabled' => false]);
        Queue::fake();

        $channel = Channel::factory()->create();
        $adapter = new TelegramAdapter('fake-token');
        $service = app(MessageIngestionService::class);

        $parsed = $adapter->parseInbound(ingestionPayload(334, chatId: 78, text: 'Hello'));

        $service->ingest($channel, $parsed);

        $conversation = Conversation::first();
        $this->assertSame(Conversation::OWNER_UNASSIGNED, $conversation->owner_type);

        Queue::assertNotPushed(\App\Jobs\GenerateAiReplyJob::class);
    }
}
