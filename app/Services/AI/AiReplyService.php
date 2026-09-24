<?php

namespace App\Services\AI;

use App\Exceptions\AI\AiConfidenceCheckFailedException;
use App\Exceptions\AI\AiDraftingFailedException;
use App\Jobs\SendTelegramReplyJob;
use App\Models\Conversation;
use App\Models\HandoffEvent;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class AiReplyService
{
    public function __construct(
        private readonly ClaudeAdapter $claudeAdapter,
        private readonly JevAdapter $jevAdapter,
        private readonly float $confidenceThreshold,
    ) {
    }

    public function handle(Conversation $conversation): void
    {
        // Production kill switch: if AI is disabled, hand off immediately
        // and don't process this conversation any further, even if it's
        // already ai-owned (a brand-new-conversation-only check in
        // ConversationRoutingService isn't enough to stop AI from
        // continuing to reply on conversations it already owns, or on
        // jobs already queued before the switch was flipped).
        if (! config('services.ai_agent.enabled')) {
            $this->handoff($conversation, null, 'ai_disabled');

            return;
        }

        // Cheap early exit: if a human already claimed this conversation
        // before the job even started, don't call any external APIs at all.
        $conversation->refresh();
        if ($conversation->owner_type !== Conversation::OWNER_AI) {
            return;
        }

        try {
            $draft = $this->claudeAdapter->draft($conversation);
        } catch (AiDraftingFailedException) {
            $this->handoff($conversation, null, 'ai_error');
            return;
        }

        try {
            $score = $this->jevAdapter->evaluateConfidence($conversation, $draft);
        } catch (AiConfidenceCheckFailedException) {
            $this->handoff($conversation, $draft, 'ai_error');
            return;
        }

        if ($score >= $this->confidenceThreshold) {
            $this->send($conversation, $draft);
        } else {
            $this->handoff($conversation, $draft, 'low_confidence');
        }
    }

    /**
     * Both Claude and Jev are multi-second external calls. A human can
     * claim the conversation while we're waiting on either — re-check
     * ownership under a row lock immediately before writing anything, so
     * stale AI work never overwrites a human's in-progress claim.
     */
    private function send(Conversation $conversation, string $draft): void
    {
        DB::transaction(function () use ($conversation, $draft) {
            $locked = Conversation::where('id', $conversation->id)->lockForUpdate()->first();
            if (! $locked || $locked->owner_type !== Conversation::OWNER_AI) {
                return;
            }

            $message = Message::create([
                'conversation_id' => $locked->id,
                'direction' => Message::DIRECTION_OUTBOUND,
                'sender_type' => 'ai',
                'body' => $draft,
                'status' => Message::STATUS_PENDING,
            ]);

            SendTelegramReplyJob::dispatch($message->id);
        });
    }

    private function handoff(Conversation $conversation, ?string $draft, string $reason): void
    {
        DB::transaction(function () use ($conversation, $draft, $reason) {
            $locked = Conversation::where('id', $conversation->id)->lockForUpdate()->first();
            if (! $locked || $locked->owner_type !== Conversation::OWNER_AI) {
                return;
            }

            if ($draft !== null) {
                Message::create([
                    'conversation_id' => $locked->id,
                    'direction' => Message::DIRECTION_OUTBOUND,
                    'sender_type' => 'ai',
                    'body' => $draft,
                    'status' => Message::STATUS_DRAFT,
                ]);
            }

            $fromOwnerType = $locked->owner_type;

            $locked->update(['owner_type' => Conversation::OWNER_UNASSIGNED]);

            HandoffEvent::create([
                'conversation_id' => $locked->id,
                'from_owner_type' => $fromOwnerType,
                'to_owner_type' => Conversation::OWNER_UNASSIGNED,
                'agent_id' => null,
                'reason' => $reason,
                'created_at' => now(),
            ]);
        });
    }
}
