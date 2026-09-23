<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_contacts_enforce_a_unique_external_id_per_channel(): void
    {
        $channel = Channel::factory()->create();

        Contact::factory()->create(['channel_id' => $channel->id, 'external_contact_id' => '12345']);

        $this->expectException(QueryException::class);

        Contact::factory()->create(['channel_id' => $channel->id, 'external_contact_id' => '12345']);
    }

    public function test_webhook_events_enforce_a_unique_external_event_id_per_channel(): void
    {
        $channel = Channel::factory()->create();

        WebhookEvent::create([
            'channel_id' => $channel->id,
            'external_event_id' => 'evt-1',
            'payload' => ['foo' => 'bar'],
        ]);

        $this->expectException(QueryException::class);

        WebhookEvent::create([
            'channel_id' => $channel->id,
            'external_event_id' => 'evt-1',
            'payload' => ['foo' => 'baz'],
        ]);
    }
}
