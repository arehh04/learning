<?php

namespace Tests\Feature;

use App\Exceptions\ConversationAlreadyClaimedException;
use App\Models\Conversation;
use App\Models\HandoffEvent;
use App\Models\User;
use App\Services\ClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_agent_can_claim_an_unassigned_conversation(): void
    {
        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_UNASSIGNED]);
        $agent = User::factory()->create();

        $claimed = app(ClaimService::class)->claim($conversation, $agent);

        $this->assertSame(Conversation::OWNER_HUMAN, $claimed->owner_type);
        $this->assertSame($agent->id, $claimed->owner_agent_id);
        $this->assertSame(1, HandoffEvent::count());
    }

    public function test_a_second_claim_attempt_on_an_already_claimed_conversation_fails(): void
    {
        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_UNASSIGNED]);
        $firstAgent = User::factory()->create();
        $secondAgent = User::factory()->create();

        app(ClaimService::class)->claim($conversation, $firstAgent);

        try {
            app(ClaimService::class)->claim($conversation->fresh(), $secondAgent);
            $this->fail('Expected ConversationAlreadyClaimedException was not thrown.');
        } catch (ConversationAlreadyClaimedException $e) {
            // expected
        }

        $conversation->refresh();
        $this->assertSame($firstAgent->id, $conversation->owner_agent_id);
    }

    public function test_an_agent_can_take_over_an_ai_owned_conversation(): void
    {
        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_AI]);
        $agent = User::factory()->create();

        $takenOver = app(ClaimService::class)->takeOver($conversation, $agent);

        $this->assertSame(Conversation::OWNER_HUMAN, $takenOver->owner_type);
        $this->assertSame($agent->id, $takenOver->owner_agent_id);
        $this->assertSame(1, HandoffEvent::where('reason', 'agent_take_over')->count());
        $this->assertSame(Conversation::OWNER_AI, HandoffEvent::where('reason', 'agent_take_over')->first()->from_owner_type);
    }

    public function test_taking_over_a_conversation_that_is_not_ai_owned_fails(): void
    {
        $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_UNASSIGNED]);
        $agent = User::factory()->create();

        $this->expectException(ConversationAlreadyClaimedException::class);

        app(ClaimService::class)->takeOver($conversation, $agent);
    }
}
