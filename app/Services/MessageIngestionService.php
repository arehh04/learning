<?php

namespace App\Services;

use App\Jobs\GenerateAiReplyJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Support\ParsedInboundMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MessageIngestionService
{
    public function __construct(
        private readonly ConversationRoutingService $routingService,
    ) {
    }

    public function ingest(Channel $channel, ParsedInboundMessage $parsed): ?Message
    {
        $conversation = null;

        $message = DB::transaction(function () use ($channel, $parsed, &$conversation) {
            if (! $this->recordWebhookEvent($channel, $parsed)) {
                return null; // duplicate delivery — already processed
            }

            $contact = $this->findOrCreateContact($channel, $parsed);
            $conversation = $this->findOrCreateConversation($channel, $contact);

            return Message::create([
                'conversation_id' => $conversation->id,
                'direction' => Message::DIRECTION_INBOUND,
                'sender_type' => 'contact',
                'body' => $parsed->body,
                'external_message_id' => $parsed->externalEventId,
                'status' => Message::STATUS_RECEIVED,
                'raw_payload' => $parsed->rawPayload,
            ]);
        });

        if ($message !== null && $conversation->owner_type === Conversation::OWNER_AI) {
            GenerateAiReplyJob::dispatch($conversation->id);
        }

        return $message;
    }

    /**
     * Runs in its own nested transaction (a Postgres SAVEPOINT, since
     * we're already inside the outer transaction from ingest()). This
     * matters: without the savepoint, catching a unique-violation here
     * would leave the outer transaction in Postgres's aborted state and
     * every later statement in ingest() would fail too.
     */
    private function recordWebhookEvent(Channel $channel, ParsedInboundMessage $parsed): bool
    {
        try {
            DB::transaction(function () use ($channel, $parsed) {
                WebhookEvent::create([
                    'channel_id' => $channel->id,
                    'external_event_id' => $parsed->externalEventId,
                    'payload' => $parsed->rawPayload,
                    'processed_at' => now(),
                ]);
            });

            return true;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }

            throw $e;
        }
    }

    private function findOrCreateContact(Channel $channel, ParsedInboundMessage $parsed): Contact
    {
        $existing = Contact::where('channel_id', $channel->id)
            ->where('external_contact_id', $parsed->externalContactId)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($channel, $parsed) {
                return Contact::create([
                    'channel_id' => $channel->id,
                    'external_contact_id' => $parsed->externalContactId,
                    'name' => $parsed->contactName,
                ]);
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Lost a create race to a concurrent request for the same contact.
            return Contact::where('channel_id', $channel->id)
                ->where('external_contact_id', $parsed->externalContactId)
                ->firstOrFail();
        }
    }

    private function findOrCreateConversation(Channel $channel, Contact $contact): Conversation
    {
        $conversation = $contact->conversations()
            ->where('channel_id', $channel->id)
            ->latest('id')
            ->first();

        if (! $conversation) {
            return Conversation::create([
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'status' => Conversation::STATUS_OPEN,
                'owner_type' => $this->routingService->decideInitialOwner(),
                'last_inbound_at' => now(),
                'opened_at' => now(),
            ]);
        }

        if ($conversation->status === Conversation::STATUS_CLOSED) {
            $conversation->update([
                'status' => Conversation::STATUS_OPEN,
                'last_inbound_at' => now(),
                'closed_at' => null,
            ]);

            return $conversation;
        }

        $conversation->update(['last_inbound_at' => now()]);

        return $conversation;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505';
    }
}
