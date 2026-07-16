<?php

use App\Models\Integration;
use App\Models\User;
use App\Services\LibrarySyncService;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    public string $search = '';
    public string $source = '';

    /** @var array<int, array{source: string, external_id: string, type: string, title: string, poster_url: string|null, imdb_rating: float|null, overview: string|null, payload: array<string, mixed>}> */
    #[Locked]
    public array $lookupResults = [];

    public function searchTitles(LibrarySyncService $librarySync): void
    {
        $validated = $this->validate([
            'search' => ['required', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'in:'.Integration::RADARR.','.Integration::SONARR],
        ]);

        $this->lookupResults = [];

        $integrations = Integration::query()
            ->when($validated['source'] !== '', fn ($query) => $query->where('source', $validated['source']))
            ->get();

        if ($integrations->isEmpty()) {
            Flux::toast(variant: 'danger', text: __('No matching source integration is configured.'));

            return;
        }

        foreach ($integrations as $integration) {
            try {
                $this->lookupResults = [...$this->lookupResults, ...$librarySync->lookup($integration, $validated['search'])];
            } catch (Throwable $exception) {
                Flux::toast(variant: 'danger', text: __('Unable to search :source: :message', ['source' => ucfirst($integration->source), 'message' => $exception->getMessage()]));
            }
        }

        if ($this->lookupResults === []) {
            Flux::toast(text: __('No matching titles found.'));
        }
    }

    public function addResult(int $index, LibrarySyncService $librarySync): void
    {
        if (! isset($this->lookupResults[$index])) {
            return;
        }

        $user = User::query()->findOrFail(auth()->id());
        $item = $librarySync->addWishlistItem($user, $this->lookupResults[$index]);

        Flux::modal('add-wishlist-item')->close();
        Flux::toast(variant: 'success', text: $item->wasRecentlyCreated ? __('Added to the shared wishlist.') : __('Your request was added to the shared wishlist item.'));
    }
};
?>

<flux:modal name="add-wishlist-item" style="width: min(48rem, calc(100vw - 2rem)); max-width: calc(100vw - 2rem);">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">{{ __('Add to wishlist') }}</flux:heading>
            <flux:subheading>{{ __('Search Radarr and Sonarr, then add a title to the shared queue.') }}</flux:subheading>
        </div>

        <form wire:submit="searchTitles" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_12rem_auto] md:items-end">
            <flux:input wire:model="search" :label="__('Search titles')" :placeholder="__('Search Radarr and Sonarr')" autofocus />
            <flux:select wire:model="source" :label="__('Source')">
                <option value="">{{ __('All sources') }}</option>
                <option value="radarr">{{ __('Radarr') }}</option>
                <option value="sonarr">{{ __('Sonarr') }}</option>
            </flux:select>
            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" icon="magnifying-glass">{{ __('Search') }}</flux:button>
        </form>

        @if ($this->lookupResults !== [])
            <div class="grid max-w-full gap-3">
                @foreach ($this->lookupResults as $index => $result)
                    <div wire:key="wishlist-result-{{ $result['source'] }}-{{ $result['external_id'] }}" class="flex min-w-0 max-w-full gap-3 overflow-hidden rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                        @if ($result['poster_url'])
                            <img src="{{ $result['poster_url'] }}" alt="" class="rounded object-cover" style="width: 3.5rem; height: 5rem; flex: none;" />
                        @else
                            <div class="flex items-center justify-center rounded bg-zinc-100 text-xs text-zinc-500 dark:bg-zinc-800" style="width: 3.5rem; height: 5rem; flex: none;">{{ __('No poster') }}</div>
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium text-zinc-900 dark:text-white">{{ $result['title'] }}</div>
                            <div class="mt-1 flex flex-wrap gap-2">
                                <flux:badge size="sm">{{ ucfirst($result['type']) }}</flux:badge>
                                <flux:badge size="sm" color="zinc">{{ ucfirst($result['source']) }}</flux:badge>
                                @if ($result['imdb_rating'] !== null)
                                    <flux:badge size="sm" color="amber">{{ __('IMDb :rating', ['rating' => number_format($result['imdb_rating'], 1)]) }}</flux:badge>
                                @endif
                            </div>
                            @if ($result['overview'])
                                <flux:text class="mt-2 block text-sm">{{ \Illuminate\Support\Str::limit($result['overview'], 220) }}</flux:text>
                            @endif
                        </div>
                        <flux:button wire:click="addResult({{ $index }})" wire:loading.attr="disabled" size="sm" variant="primary" icon="arrow-down-tray" class="shrink-0 self-center">{{ __('Download') }}</flux:button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</flux:modal>
