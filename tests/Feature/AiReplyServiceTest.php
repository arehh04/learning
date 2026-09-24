<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramReplyJob;
use App\Models\Conversation;
use App\Models\HandoffEvent;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiReplyService;
use App\Services\AI\ClaudeAdapter;
use App\Services\AI\JevAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiReplyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(float $threshold = 0.7): AiReplyService
    {
        return new AiReplyService(
            new ClaudeAdapter('fake-key', 'claude-haiku-4-5-20251001'),
            new JevAdapter('fake-key'),
            $threshold,
        );
    }

    private function outboundMessages(Conversation $conversation)
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->get();
    }

    public function test_high_confidence_reply_is_sent_and_conversation_stays_ai_owned(): void
    {
        config(['services.ai_agent.enabled' => true]);
        Queue::fake();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Sure, we can do 50 people.']]], 200),
            'api.typesafe.ai/*' => Http::response(['answers' => ['confident_to_send' => ['type' => 'noul', 'noul' => 0.9]]], 200),
        ]);

        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_AI]);
        Message::factory()->create(['conversation_id' => $conversation->id]);

        $this->service()->handle($conversation);

        $conversation->refresh();
        $this->assertSame(Conversation::OWNER_AI, $conversation->owner_type);

        $message = $this->outboundMessages($conversation)->first();
        $this->assertSame(Message::STATUS_PENDING, $message->status);
        $this->assertSame('Sure, we can do 50 people.', $message->body);

        Queue::assertPushed(SendTelegramReplyJob::class);
    }

    public function test_low_confidence_hands_off_with_draft_saved_as_hint(): void
    {
        config(['services.ai_agent.enabled' => true]);
        Queue::fake();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Not totally sure about this one.']]], 200),
            'api.typesafe.ai/*' => Http::response(['answers' => ['confident_to_send' => ['type' => 'noul', 'noul' => 0.3]]], 200),
        ]);

        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_AI]);
        Message::factory()->create(['conversation_id' => $conversation->id]);

        $this->service()->handle($conversation);

        $conversation->refresh();
        $this->assertSame(Conversation::OWNER_UNASSIGNED, $conversation->owner_type);

        $message = $this->outboundMessages($conversation)->first();
        $this->assertSame(Message::STATUS_DRAFT, $message->status);
        $this->assertSame('Not totally sure about this one.', $message->body);

        $handoff = HandoffEvent::where('conversation_id', $conversation->id)->first();
        $this->assertSame('low_confidence', $handoff->reason);
        $this->assertSame(Conversation::OWNER_AI, $handoff->from_owner_type);

        Queue::assertNotPushed(SendTelegramReplyJob::class);
    }

    public function test_claude_failure_hands_off_with_no_draft_message(): void
    {
        config(['services.ai_agent.enabled' => true]);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529),
        ]);

        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_AI]);
        Message::factory()->create(['conversation_id' => $conversation->id]);

        $this->service()->handle($conversation);

        $conversation->refresh();
        $this->assertSame(Conversation::OWNER_UNASSIGNED, $conversation->owner_type);
        $this->assertSame(0, $this->outboundMessages($conversation)->count());

        $handoff = HandoffEvent::where('conversation_id', $conversation->id)->first();
        $this->assertSame('ai_error', $handoff->reason);
    }

    public function test_jev_failure_hands_off_with_draft_still_saved(): void
    {
        config(['services.ai_agent.enabled' => true]);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'a real draft']]], 200),
            'api.typesafe.ai/*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_AI]);
        Message::factory()->create(['conversation_id' => $conversation->id]);

        $this->service()->handle($conversation);

        $conversation->refresh();
        $this->assertSame(Conversation::OWNER_UNASSIGNED, $conversation->owner_type);

        $message = $this->outboundMessages($conversation)->first();
        $this->assertSame(Message::STATUS_DRAFT, $message->status);
        $this->assertSame('a real draft', $message->body);

        $handoff = HandoffEvent::where('conversation_id', $conversation->id)->first();
        $this->assertSame('ai_error', $handoff->reason);
    }

    public function test_a_conversation_no_longer_owned_by_ai_is_skipped_entirely(): void
    {
        // Exercise the ownership-check skip path specifically (not the
        // kill-switch skip path, which is covered by its own test).
        config(['services.ai_agent.enabled' => true]);
        Http::fake(); // any request at all fails this test's premise

        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_HUMAN]);

        $this->service()->handle($conversation);

        Http::assertNothingSent();
        $this->assertSame(0, $this->outboundMessages($conversation)->count());
        $this->assertSame(0, HandoffEvent::where('conversation_id', $conversation->id)->count());
    }

    public function test_a_conversation_claimed_by_a_human_mid_flight_discards_the_ai_work(): void
    {
        config(['services.ai_agent.enabled' => true]);
        Queue::fake();

        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_AI]);
        $agent = User::factory()->create();

        Http::fake(function ($request) use ($conversation, $agent) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                // Simulate a human claiming the conversation while Claude's
                // (slow, real-world) API call was in flight.
                $conversation->update(['owner_type' => Conversation::OWNER_HUMAN, 'owner_agent_id' => $agent->id]);

                return Http::response(['content' => [['type' => 'text', 'text' => 'a draft nobody will see']]], 200);
            }

            return Http::response(['answers' => ['confident_to_send' => ['type' => 'noul', 'noul' => 0.95]]], 200);
        });

        $this->service()->handle($conversation);

        $this->assertSame(0, $this->outboundMessages($conversation)->count());
        Queue::assertNotPushed(SendTelegramReplyJob::class);

        $conversation->refresh();
        $this->assertSame(Conversation::OWNER_HUMAN, $conversation->owner_type);
    }

    public function test_a_conversation_claimed_by_a_human_mid_flight_discards_the_ai_handoff(): void
    {
        config(['services.ai_agent.enabled' => true]);
        Queue::fake();

        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_AI]);
        $agent = User::factory()->create();

        Http::fake(function ($request) use ($conversation, $agent) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                // Simulate a human claiming the conversation while Claude's
                // (slow, real-world) API call was in flight.
                $conversation->update(['owner_type' => Conversation::OWNER_HUMAN, 'owner_agent_id' => $agent->id]);

                return Http::response(['content' => [['type' => 'text', 'text' => 'a draft nobody will see']]], 200);
            }

            // Low confidence so the outcome would normally be a handoff()
            // write, not a send() write.
            return Http::response(['answers' => ['confident_to_send' => ['type' => 'noul', 'noul' => 0.3]]], 200);
        });

        $this->service()->handle($conversation);

        $this->assertSame(0, $this->outboundMessages($conversation)->count());
        Queue::assertNotPushed(SendTelegramReplyJob::class);
        $this->assertSame(0, HandoffEvent::where('conversation_id', $conversation->id)->count());

        $conversation->refresh();
        $this->assertSame(Conversation::OWNER_HUMAN, $conversation->owner_type);
    }

    public function test_disabling_the_ai_agent_hands_off_an_already_ai_owned_conversation(): void
    {
        config(['services.ai_agent.enabled' => false]);

        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_AI]);
        Message::factory()->create(['conversation_id' => $conversation->id]);

        $this->service()->handle($conversation);

        $conversation->refresh();
        $this->assertSame(Conversation::OWNER_UNASSIGNED, $conversation->owner_type);
        $this->assertSame(0, $this->outboundMessages($conversation)->count());

        $handoff = HandoffEvent::where('conversation_id', $conversation->id)->first();
        $this->assertSame('ai_disabled', $handoff->reason);
        $this->assertSame(Conversation::OWNER_AI, $handoff->from_owner_type);

        Http::assertNothingSent();
    }
}
