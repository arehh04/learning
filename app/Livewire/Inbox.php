<?php

namespace App\Livewire;

use App\Exceptions\ConversationAlreadyClaimedException;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ClaimService;
use App\Services\ReplyDispatchService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Inbox extends Component
{
    public ?int $selectedConversationId = null;
    public string $replyBody = '';
    public ?string $claimError = null;

    public function select(int $conversationId): void
    {
        $this->selectedConversationId = $conversationId;
        $this->claimError = null;
        $this->prefillDraftReply($conversationId);
    }

    public function claim(int $conversationId, ClaimService $claimService): void
    {
        $conversation = Conversation::findOrFail($conversationId);

        try {
            $claimService->claim($conversation, auth()->user());
            $this->selectedConversationId = $conversationId;
            $this->claimError = null;
            $this->prefillDraftReply($conversationId);
        } catch (ConversationAlreadyClaimedException $e) {
            $this->claimError = 'Someone already claimed this conversation.';
        }
    }

    private function prefillDraftReply(int $conversationId): void
    {
        $latestDraft = Message::where('conversation_id', $conversationId)
            ->where('status', Message::STATUS_DRAFT)
            ->latest('id')
            ->first();

        $this->replyBody = $latestDraft?->body ?? '';
    }

    public function sendReply(ReplyDispatchService $replyService): void
    {
        $this->validate(['replyBody' => 'required|string|min:1']);

        $conversation = Conversation::findOrFail($this->selectedConversationId);

        if ($conversation->owner_agent_id !== auth()->id()) {
            $this->claimError = 'You can only reply on conversations you own.';

            return;
        }

        $replyService->send($conversation, auth()->user(), $this->replyBody);

        $this->replyBody = '';
        $this->claimError = null;
    }

    public function render()
    {
        return view('livewire.inbox', [
            'unassigned' => Conversation::where('owner_type', Conversation::OWNER_UNASSIGNED)
                ->with('contact')
                ->latest('last_inbound_at')
                ->get(),
            'mine' => Conversation::where('owner_type', Conversation::OWNER_HUMAN)
                ->where('owner_agent_id', auth()->id())
                ->with('contact')
                ->latest('last_inbound_at')
                ->get(),
            'selected' => $this->selectedConversationId
                ? Conversation::with(['contact', 'messages' => fn ($q) => $q->orderBy('created_at')])
                    ->find($this->selectedConversationId)
                : null,
        ]);
    }
}
