<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramReplyJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ReplyDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReplyDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_a_reply_creates_a_pending_outbound_message_and_dispatches_the_send_job(): void
    {
        Queue::fake();

        $conversation = Conversation::factory()->create();
        $agent = User::factory()->create();

        $message = app(ReplyDispatchService::class)->send($conversation, $agent, 'On it!');

        $this->assertSame(Message::STATUS_PENDING, $message->status);
        $this->assertSame(Message::DIRECTION_OUTBOUND, $message->direction);
        $this->assertSame($agent->id, $message->sender_id);

        Queue::assertPushed(SendTelegramReplyJob::class);
    }
}
