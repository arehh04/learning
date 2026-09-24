<?php

namespace App\Services;

use App\Exceptions\ConversationAlreadyClaimedException;
use App\Models\Conversation;
use App\Models\HandoffEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClaimService
{
    public function claim(Conversation $conversation, User $agent): Conversation
    {
        return DB::transaction(function () use ($conversation, $agent) {
            $locked = Conversation::where('id', $conversation->id)->lockForUpdate()->firstOrFail();

            if ($locked->owner_type !== Conversation::OWNER_UNASSIGNED) {
                throw new ConversationAlreadyClaimedException(
                    "Conversation {$locked->id} is already owned by {$locked->owner_type}."
                );
            }

            $fromOwnerType = $locked->owner_type;

            $locked->update([
                'owner_type' => Conversation::OWNER_HUMAN,
                'owner_agent_id' => $agent->id,
            ]);

            HandoffEvent::create([
                'conversation_id' => $locked->id,
                'from_owner_type' => $fromOwnerType,
                'to_owner_type' => Conversation::OWNER_HUMAN,
                'agent_id' => $agent->id,
                'reason' => 'manual_claim',
                'created_at' => now(),
            ]);

            return $locked;
        });
    }

    public function takeOver(Conversation $conversation, User $agent): Conversation
    {
        return DB::transaction(function () use ($conversation, $agent) {
            $locked = Conversation::where('id', $conversation->id)->lockForUpdate()->firstOrFail();

            if ($locked->owner_type !== Conversation::OWNER_AI) {
                throw new ConversationAlreadyClaimedException(
                    "Conversation {$locked->id} is no longer being handled by the AI (currently owned by {$locked->owner_type})."
                );
            }

            $fromOwnerType = $locked->owner_type;

            $locked->update([
                'owner_type' => Conversation::OWNER_HUMAN,
                'owner_agent_id' => $agent->id,
            ]);

            HandoffEvent::create([
                'conversation_id' => $locked->id,
                'from_owner_type' => $fromOwnerType,
                'to_owner_type' => Conversation::OWNER_HUMAN,
                'agent_id' => $agent->id,
                'reason' => 'agent_take_over',
                'created_at' => now(),
            ]);

            return $locked;
        });
    }
}
