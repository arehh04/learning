<div class="flex h-full gap-4">
    <div class="w-1/3 space-y-4">
        <div>
            <h2 class="font-bold">Unassigned</h2>
            @if ($claimError)
                <p class="text-red-600 text-sm">{{ $claimError }}</p>
            @endif

            <div wire:loading wire:target="select,claim" class="space-y-2">
                <x-skeleton-list-item />
                <x-skeleton-list-item />
            </div>
            <div wire:loading.remove wire:target="select,claim" class="space-y-2">
                @foreach ($unassigned as $conversation)
                    <div class="border p-2 flex justify-between items-center">
                        <span wire:click="select({{ $conversation->id }})" class="cursor-pointer hover:text-gray-900 transition-colors">
                            {{ $conversation->contact->name ?? $conversation->contact->external_contact_id }}
                        </span>
                        <button wire:click="claim({{ $conversation->id }})" class="text-sm text-blue-600 hover:text-gray-900 transition-transform active:scale-95 duration-150">Claim</button>
                    </div>
                @endforeach
            </div>
        </div>

        <div>
            <h2 class="font-bold">AI Handling</h2>
            @if ($claimError)
                <p class="text-red-600 text-sm">{{ $claimError }}</p>
            @endif

            <div wire:loading wire:target="select,takeOver" class="space-y-2">
                <x-skeleton-list-item />
                <x-skeleton-list-item />
            </div>
            <div wire:loading.remove wire:target="select,takeOver" class="space-y-2">
                @foreach ($aiHandling as $conversation)
                    <div class="border p-2 flex justify-between items-center">
                        <span wire:click="select({{ $conversation->id }})" class="cursor-pointer hover:text-gray-900 transition-colors">
                            {{ $conversation->contact->name ?? $conversation->contact->external_contact_id }}
                        </span>
                        <button wire:click="takeOver({{ $conversation->id }})" class="text-sm text-white bg-red-600 hover:bg-red-700 px-2 py-1 rounded font-semibold shadow-md hover:shadow-lg shadow-red-400/50 transition active:scale-95 duration-150">Take Over</button>
                    </div>
                @endforeach
            </div>
        </div>

        <div>
            <h2 class="font-bold">Mine</h2>

            <div wire:loading wire:target="select,claim,takeOver" class="space-y-2">
                <x-skeleton-list-item />
                <x-skeleton-list-item />
            </div>
            <div wire:loading.remove wire:target="select,claim,takeOver" class="space-y-2">
                @foreach ($mine as $conversation)
                    <div wire:click="select({{ $conversation->id }})" class="border p-2 cursor-pointer hover:text-gray-900 transition-colors">
                        {{ $conversation->contact->name ?? $conversation->contact->external_contact_id }}
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="w-2/3">
        <div wire:loading wire:target="select,claim,takeOver" class="space-y-2 mb-4">
            <x-skeleton-message align="left" />
            <x-skeleton-message align="right" />
            <x-skeleton-message align="left" />
        </div>

        <div wire:loading.remove wire:target="select,claim,takeOver">
            @if ($selected)
                @if ($claimError)
                    <p class="text-red-600 text-sm">{{ $claimError }}</p>
                @endif
                <div class="space-y-2 mb-4">
                    @foreach ($selected->messages as $message)
                        <div class="{{ $message->direction === 'outbound' ? 'text-right' : 'text-left' }}">
                            <span class="inline-block border rounded p-2 {{ $message->status === 'draft' ? 'border-dashed border-yellow-500 bg-yellow-50' : '' }}">
                                {{ $message->body }}
                                @if ($message->status === 'draft')
                                    <span class="block text-xs text-yellow-600">AI draft (not sent)</span>
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>

                @if ($selected->owner_agent_id === auth()->id())
                    <form wire:submit="sendReply" class="flex gap-2">
                        <input type="text" wire:model="replyBody" class="border flex-1 p-2" placeholder="Type a reply..." />
                        <button type="submit" wire:loading.attr="disabled" wire:target="sendReply" class="bg-blue-600 text-white px-4 py-2 transition-transform active:scale-95 duration-150 disabled:opacity-50">
                            <span wire:loading.remove wire:target="sendReply">Send</span>
                            <span wire:loading wire:target="sendReply">Sending...</span>
                        </button>
                    </form>
                    @error('replyBody') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                @endif
            @else
                <p>Select a conversation.</p>
            @endif
        </div>
    </div>
</div>
