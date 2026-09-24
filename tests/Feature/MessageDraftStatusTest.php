<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageDraftStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_message_can_be_created_with_the_draft_status(): void
    {
        $conversation = Conversation::factory()->create();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'sender_type' => 'ai',
            'body' => 'draft reply',
            'status' => Message::STATUS_DRAFT,
        ]);

        $this->assertSame(Message::STATUS_DRAFT, $message->fresh()->status);
    }

    public function test_existing_message_statuses_still_work(): void
    {
        $conversation = Conversation::factory()->create();

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'status' => Message::STATUS_SENT,
        ]);

        $this->assertSame(Message::STATUS_SENT, $message->fresh()->status);
    }
}
