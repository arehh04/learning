<?php

namespace Tests\Feature;

use App\Livewire\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_agent_can_load_the_inbox_page(): void
    {
        $agent = User::factory()->create();

        $this->actingAs($agent)->get('/inbox')->assertOk();
    }

    public function test_an_agent_can_claim_an_unassigned_conversation_from_the_inbox(): void
    {
        $agent = User::factory()->create();
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['channel_id' => $channel->id]);
        $conversation = Conversation::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'owner_type' => Conversation::OWNER_UNASSIGNED,
        ]);

        Livewire::actingAs($agent)
            ->test(Inbox::class)
            ->call('claim', $conversation->id)
            ->assertSet('claimError', null);

        $conversation->refresh();
        $this->assertSame(Conversation::OWNER_HUMAN, $conversation->owner_type);
        $this->assertSame($agent->id, $conversation->owner_agent_id);
    }

    public function test_a_second_agent_cannot_claim_a_conversation_already_claimed_by_another_agent(): void
    {
        $firstAgent = User::factory()->create();
        $secondAgent = User::factory()->create();
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['channel_id' => $channel->id]);
        $conversation = Conversation::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'owner_type' => Conversation::OWNER_HUMAN,
            'owner_agent_id' => $firstAgent->id,
        ]);

        Livewire::actingAs($secondAgent)
            ->test(Inbox::class)
            ->call('claim', $conversation->id)
            ->assertSet('claimError', 'Someone already claimed this conversation.');
    }

    public function test_an_agent_can_send_a_reply_on_a_conversation_they_own(): void
    {
        Queue::fake();

        $agent = User::factory()->create();
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['channel_id' => $channel->id]);
        $conversation = Conversation::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'owner_type' => Conversation::OWNER_HUMAN,
            'owner_agent_id' => $agent->id,
        ]);

        Livewire::actingAs($agent)
            ->test(Inbox::class)
            ->call('select', $conversation->id)
            ->set('replyBody', 'Thanks for reaching out!')
            ->call('sendReply');

        $this->assertSame(
            1,
            Message::where('conversation_id', $conversation->id)
                ->where('direction', Message::DIRECTION_OUTBOUND)
                ->count()
        );
    }

    public function test_an_agent_cannot_send_a_reply_on_a_conversation_they_do_not_own(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $otherAgent = User::factory()->create();
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['channel_id' => $channel->id]);
        $conversation = Conversation::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'owner_type' => Conversation::OWNER_HUMAN,
            'owner_agent_id' => $owner->id,
        ]);

        Livewire::actingAs($otherAgent)
            ->test(Inbox::class)
            ->call('select', $conversation->id)
            ->set('replyBody', 'I should not be able to send this.')
            ->call('sendReply')
            ->assertSet('claimError', 'You can only reply on conversations you own.');

        $this->assertSame(
            0,
            Message::where('conversation_id', $conversation->id)
                ->where('direction', Message::DIRECTION_OUTBOUND)
                ->count()
        );
    }

    public function test_selecting_a_conversation_with_a_draft_prefills_the_reply_box(): void
    {
        $agent = User::factory()->create();
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['channel_id' => $channel->id]);
        $conversation = Conversation::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'owner_type' => Conversation::OWNER_UNASSIGNED,
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'sender_type' => 'ai',
            'status' => Message::STATUS_DRAFT,
            'body' => 'Here is a suggested reply from the AI.',
        ]);

        Livewire::actingAs($agent)
            ->test(Inbox::class)
            ->call('select', $conversation->id)
            ->assertSet('replyBody', 'Here is a suggested reply from the AI.');
    }

    public function test_selecting_a_conversation_without_a_draft_leaves_the_reply_box_blank(): void
    {
        $agent = User::factory()->create();
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['channel_id' => $channel->id]);
        $conversation = Conversation::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'owner_type' => Conversation::OWNER_UNASSIGNED,
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_INBOUND,
        ]);

        Livewire::actingAs($agent)
            ->test(Inbox::class)
            ->call('select', $conversation->id)
            ->assertSet('replyBody', '');
    }

    public function test_selecting_a_conversation_with_multiple_drafts_prefills_the_most_recent_one(): void
    {
        $agent = User::factory()->create();
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['channel_id' => $channel->id]);
        $conversation = Conversation::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'owner_type' => Conversation::OWNER_UNASSIGNED,
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'sender_type' => 'ai',
            'status' => Message::STATUS_DRAFT,
            'body' => 'first rejected draft',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'sender_type' => 'ai',
            'status' => Message::STATUS_DRAFT,
            'body' => 'second, more recent rejected draft',
        ]);

        Livewire::actingAs($agent)
            ->test(Inbox::class)
            ->call('select', $conversation->id)
            ->assertSet('replyBody', 'second, more recent rejected draft');
    }

    public function test_selecting_a_conversation_with_a_draft_superseded_by_a_real_reply_leaves_the_reply_box_blank(): void
    {
        $agent = User::factory()->create();
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['channel_id' => $channel->id]);
        $conversation = Conversation::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'owner_type' => Conversation::OWNER_UNASSIGNED,
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'sender_type' => 'ai',
            'status' => Message::STATUS_DRAFT,
            'body' => 'stale AI draft that was rejected',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'sender_type' => 'agent',
            'status' => Message::STATUS_PENDING,
            'body' => 'the real reply the agent actually sent',
        ]);

        Livewire::actingAs($agent)
            ->test(Inbox::class)
            ->call('select', $conversation->id)
            ->assertSet('replyBody', '');
    }

    public function test_claiming_a_conversation_with_a_draft_prefills_the_reply_box(): void
    {
        $agent = User::factory()->create();
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['channel_id' => $channel->id]);
        $conversation = Conversation::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'owner_type' => Conversation::OWNER_UNASSIGNED,
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'sender_type' => 'ai',
            'status' => Message::STATUS_DRAFT,
            'body' => 'AI suggested reply',
        ]);

        Livewire::actingAs($agent)
            ->test(Inbox::class)
            ->call('claim', $conversation->id)
            ->assertSet('replyBody', 'AI suggested reply');
    }
}
