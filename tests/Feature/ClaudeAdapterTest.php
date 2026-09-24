<?php

namespace Tests\Feature;

use App\Exceptions\AI\AiDraftingFailedException;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\ClaudeAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaudeAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_returns_claude_reply_text(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Sure, we can do 50 people — what date works?']],
            ], 200),
        ]);

        $conversation = Conversation::factory()->create();
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_INBOUND,
            'body' => 'hey i want to make an order for 50 person',
        ]);

        $adapter = new ClaudeAdapter('fake-key', 'claude-haiku-4-5-20251001');

        $draft = $adapter->draft($conversation->fresh());

        $this->assertSame('Sure, we can do 50 people — what date works?', $draft);
    }

    public function test_draft_throws_when_claude_api_fails(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529),
        ]);

        $conversation = Conversation::factory()->create();
        Message::factory()->create(['conversation_id' => $conversation->id]);

        $adapter = new ClaudeAdapter('fake-key', 'claude-haiku-4-5-20251001');

        $this->expectException(AiDraftingFailedException::class);
        $adapter->draft($conversation->fresh());
    }

    public function test_draft_throws_when_claude_returns_an_empty_reply(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '   ']],
            ], 200),
        ]);

        $conversation = Conversation::factory()->create();
        Message::factory()->create(['conversation_id' => $conversation->id]);

        $adapter = new ClaudeAdapter('fake-key', 'claude-haiku-4-5-20251001');

        $this->expectException(AiDraftingFailedException::class);
        $adapter->draft($conversation->fresh());
    }

    public function test_draft_caps_history_to_the_last_20_messages(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
            ], 200),
        ]);

        $conversation = Conversation::factory()->create();
        for ($i = 0; $i < 25; $i++) {
            Message::factory()->create([
                'conversation_id' => $conversation->id,
                'body' => "message {$i}",
            ]);
        }

        $adapter = new ClaudeAdapter('fake-key', 'claude-haiku-4-5-20251001');
        $adapter->draft($conversation->fresh());

        Http::assertSent(function ($request) {
            return count($request->data()['messages']) === 20;
        });
    }
}
