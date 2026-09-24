<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\HandoffEvent;
use App\Services\AI\AiReplyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $conversationId)
    {
    }

    public function handle(AiReplyService $aiReplyService): void
    {
        $conversation = Conversation::findOrFail($this->conversationId);
        $aiReplyService->handle($conversation);
    }

    /**
     * Last-resort fail-safe: HTTP error responses from Claude or Jev are
     * caught by the adapters and handed off to a human immediately, on the
     * first try — they never reach here. This only runs once all 3 tries
     * are exhausted on an uncaught exception (e.g. a connection failure, or
     * an unexpected bug), so don't leave the conversation stuck on "ai"
     * forever with nobody notified — hand it to a human.
     */
    public function failed(Throwable $exception): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (! $conversation || $conversation->owner_type !== Conversation::OWNER_AI) {
            return;
        }

        $conversation->update(['owner_type' => Conversation::OWNER_UNASSIGNED]);

        HandoffEvent::create([
            'conversation_id' => $conversation->id,
            'from_owner_type' => Conversation::OWNER_AI,
            'to_owner_type' => Conversation::OWNER_UNASSIGNED,
            'agent_id' => null,
            'reason' => 'ai_error',
            'created_at' => now(),
        ]);
    }
}
