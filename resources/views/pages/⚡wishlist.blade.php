<?php

use App\Models\User;
use App\Models\WishlistItem;
use App\Services\LibrarySyncService;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Wishlist')] class extends Component {
    public function cancel(int $wishlistItemId, LibrarySyncService $librarySync): void
    {
        $user = User::query()->findOrFail(auth()->id());
        $item = WishlistItem::query()
            ->whereKey($wishlistItemId)
            ->whereHas('requesters', fn ($query) => $query->whereBelongsTo($user))
            ->firstOrFail();

        $librarySync->cancelWishlistItem($user, $item);
        Flux::toast(text: __('Your wishlist request was cancelled.'));
    }

    public function process(LibrarySyncService $librarySync): void
    {
        Gate::authorize('manage-library');

        $result = $librarySync->processWishlist();
        Flux::toast(variant: 'success', text: __('Processed wishlist: :requested requested, :skipped pending.', $result));
    }

    #[Computed]
    public function items(): \Illuminate\Database\Eloquent\Collection
    {
        return WishlistItem::query()
            ->with(['requesters.user', 'latestError', 'activeDownloadTransfer'])
            ->oldest()
            ->get();
    }
}; ?>

<div wire:poll.30s.visible class="flex w-full flex-1 flex-col gap-6 rounded-xl">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div>
            <flux:heading size="xl">{{ __('Wishlist') }}</flux:heading>
            <flux:subheading>{{ __('Shared requests are added when the connected source has enough space.') }}</flux:subheading>
        </div>
        @can('manage-library')
            <flux:button wire:click="process" wire:loading.attr="disabled" icon="play">{{ __('Process wishlist now') }}</flux:button>
        @endcan
    </div>

    @if ($this->items->isEmpty())
        <flux:card class="p-6"><flux:text>{{ __('No titles are waiting in the shared wishlist.') }}</flux:text></flux:card>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Title') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Requesters') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->items as $item)
                    <flux:table.row :key="$item->id">
                        <flux:table.cell variant="strong"><div>{{ $item->title }}</div><flux:text class="text-xs">{{ ucfirst($item->source) }} · {{ ucfirst($item->type) }}</flux:text></flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$item->status === 'requested' ? 'green' : 'amber'">{{ ucfirst($item->status) }}</flux:badge>
                            @if ($item->latestError?->message)
                                <div class="mt-1 flex items-start gap-1">
                                    <flux:text class="max-w-xs text-xs">{{ $item->latestError->message }}</flux:text>
                                    <flux:button x-on:click="navigator.clipboard.writeText($el.dataset.copyError)" data-copy-error="{{ $item->latestError->message }}" icon="clipboard-document" variant="subtle" size="xs" square :loading="false" tooltip="{{ __('Copy last error') }}" aria-label="{{ __('Copy last error') }}" />
                                </div>
                            @endif
                            @if ($item->activeDownloadTransfer)
                                <x-download-progress class="mt-2 max-w-xs" :transfer="$item->activeDownloadTransfer" />
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $item->requesters->pluck('user.name')->join(', ') }}</flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($item->requesters->contains('user_id', auth()->id()))
                                <flux:button wire:click="cancel({{ $item->id }})" size="sm" variant="ghost" wire:loading.attr="disabled">{{ __('Cancel') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
