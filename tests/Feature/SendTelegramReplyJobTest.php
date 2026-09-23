<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramReplyJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channels\TelegramAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SendTelegramReplyJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_send_job_marks_the_message_as_sent_on_success(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
        ]);

        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['channel_id' => $channel->id, 'external_contact_id' => '555']);
        $conversation = Conversation::factory()->create(['contact_id' => $contact->id, 'channel_id' => $channel->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'status' => Message::STATUS_PENDING,
        ]);

        (new SendTelegramReplyJob($message->id))->handle(app(TelegramAdapter::class));

        $message->refresh();
        $this->assertSame(Message::STATUS_SENT, $message->status);
        $this->assertSame('99', $message->external_message_id);
    }

    public function test_the_job_marks_the_message_as_failed_when_the_failed_hook_runs(): void
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['channel_id' => $channel->id, 'external_contact_id' => '555']);
        $conversation = Conversation::factory()->create(['contact_id' => $contact->id, 'channel_id' => $channel->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'status' => Message::STATUS_PENDING,
        ]);

        (new SendTelegramReplyJob($message->id))->failed(new RuntimeException('boom'));

        $message->refresh();
        $this->assertSame(Message::STATUS_FAILED, $message->status);
    }
}
