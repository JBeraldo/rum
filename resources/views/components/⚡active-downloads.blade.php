<?php

use App\Models\DownloadClient;
use App\Models\DownloadTransfer;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function client(): ?DownloadClient
    {
        $client = DownloadClient::query()->where('type', DownloadClient::QBITTORRENT)->first();

        if ($client === null || (! filled($client->api_key) && (! filled($client->username) || ! filled($client->password)))) {
            return null;
        }

        return $client;
    }

    /**
     * @return Collection<int, DownloadTransfer>
     */
    #[Computed]
    public function transfers(): Collection
    {
        if ($this->client === null) {
            return new Collection;
        }

        return $this->client->transfers()
            ->active()
            ->with(['mediaItem', 'wishlistItem'])
            ->latest('last_seen_at')
            ->limit(8)
            ->get();
    }
}; ?>

<flux:card wire:poll.30s.visible class="p-5">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading>{{ __('Active downloads') }}</flux:heading>
            <flux:subheading>{{ __('Media-linked qBittorrent transfers') }}</flux:subheading>
        </div>
        <flux:badge color="blue">{{ $this->transfers->count() }}</flux:badge>
    </div>

    @if ($this->client === null)
        <div class="mt-5 flex flex-col items-start gap-3 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50">
            <flux:text>{{ __('qBittorrent download tracking is not configured yet.') }}</flux:text>
            @can('manage-library')
                <flux:button :href="route('integrations.edit')" wire:navigate size="sm">{{ __('Configure qBittorrent') }}</flux:button>
            @endcan
        </div>
    @elseif ($this->transfers->isEmpty())
        <flux:text class="mt-5 block">{{ __('No linked downloads are currently active.') }}</flux:text>
    @else
        <div class="mt-5 divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($this->transfers as $transfer)
                @php($subject = $transfer->mediaItem ?? $transfer->wishlistItem)
                <div wire:key="active-download-{{ $transfer->id }}" class="flex items-center gap-4 py-4 first:pt-0 last:pb-0">
                    <div class="h-16 w-11 shrink-0 overflow-hidden rounded-md bg-zinc-200 dark:bg-zinc-800">
                        @if ($subject?->poster_url)
                            <img src="{{ $subject->poster_url }}" alt="" class="h-full w-full object-cover" />
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <flux:text class="block truncate font-medium">{{ $subject?->title ?? $transfer->name }}</flux:text>
                                <flux:text class="block text-xs">{{ ucfirst($transfer->source) }} · {{ $transfer->stateLabel() }}</flux:text>
                            </div>
                            <flux:text class="shrink-0 text-sm font-medium">{{ $transfer->progressPercentage() }}%</flux:text>
                        </div>
                        <x-download-progress :transfer="$transfer" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</flux:card>
