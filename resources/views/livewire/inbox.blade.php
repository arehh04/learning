<div class="flex h-full gap-4">
    <div class="w-1/3 space-y-4">
        <div>
            <h2 class="font-bold">Unassigned</h2>
            @if ($claimError)
                <p class="text-red-600 text-sm">{{ $claimError }}</p>
            @endif
            @foreach ($unassigned as $conversation)
                <div class="border p-2 flex justify-between items-center">
                    <span wire:click="select({{ $conversation->id }})" class="cursor-pointer">
                        {{ $conversation->contact->name ?? $conversation->contact->external_contact_id }}
                    </span>
                    <button wire:click="claim({{ $conversation->id }})" class="text-sm text-blue-600">Claim</button>
                </div>
            @endforeach
        </div>

        <div>
            <h2 class="font-bold">Mine</h2>
            @foreach ($mine as $conversation)
                <div wire:click="select({{ $conversation->id }})" class="border p-2 cursor-pointer">
                    {{ $conversation->contact->name ?? $conversation->contact->external_contact_id }}
                </div>
            @endforeach
        </div>
    </div>

    <div class="w-2/3">
        @if ($selected)
            <div class="space-y-2 mb-4">
                @foreach ($selected->messages as $message)
                    <div class="{{ $message->direction === 'outbound' ? 'text-right' : 'text-left' }}">
                        <span class="inline-block border rounded p-2">{{ $message->body }}</span>
                    </div>
                @endforeach
            </div>

            @if ($selected->owner_agent_id === auth()->id())
                <form wire:submit="sendReply" class="flex gap-2">
                    <input type="text" wire:model="replyBody" class="border flex-1 p-2" placeholder="Type a reply..." />
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2">Send</button>
                </form>
                @error('replyBody') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            @endif
        @else
            <p>Select a conversation.</p>
        @endif
    </div>
</div>
