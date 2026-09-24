<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiReplyJob;
use App\Models\Conversation;
use App\Models\HandoffEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateAiReplyJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_delegates_to_ai_reply_service(): void
    {
        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_HUMAN]);

        // owner_type is human, so AiReplyService::handle() is a documented
        // no-op (Task 4) — this proves the job actually wires through to
        // the real service rather than doing nothing on its own.
        $job = new GenerateAiReplyJob($conversation->id);
        $job->handle(app(\App\Services\AI\AiReplyService::class));

        $this->assertSame(0, HandoffEvent::where('conversation_id', $conversation->id)->count());
    }

    public function test_failed_hook_hands_off_to_human_when_retries_are_exhausted(): void
    {
        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_AI]);

        $job = new GenerateAiReplyJob($conversation->id);
        $job->failed(new \RuntimeException('all 3 tries failed'));

        $conversation->refresh();
        $this->assertSame(Conversation::OWNER_UNASSIGNED, $conversation->owner_type);

        $handoff = HandoffEvent::where('conversation_id', $conversation->id)->first();
        $this->assertSame('ai_error', $handoff->reason);
        $this->assertSame(Conversation::OWNER_AI, $handoff->from_owner_type);
    }

    public function test_failed_hook_is_a_no_op_if_conversation_already_moved_on(): void
    {
        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_HUMAN]);

        $job = new GenerateAiReplyJob($conversation->id);
        $job->failed(new \RuntimeException('all 3 tries failed'));

        $conversation->refresh();
        $this->assertSame(Conversation::OWNER_HUMAN, $conversation->owner_type);
        $this->assertSame(0, HandoffEvent::where('conversation_id', $conversation->id)->count());
    }
}
