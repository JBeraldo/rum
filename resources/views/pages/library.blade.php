<?php

use App\Models\Integration;
use App\Models\MediaItem;
use App\Services\LibrarySyncService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Library')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $type = '';
    public string $availability = '';
    public ?int $pendingDeletionId = null;
    public string $deleteConfirmation = '';
    public bool $showDeleteModal = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingAvailability(): void
    {
        $this->resetPage();
    }

    public function requestDeletion(int $mediaItemId): void
    {
        Gate::authorize('manage-library');

        $this->pendingDeletionId = MediaItem::query()->findOrFail($mediaItemId)->id;
        $this->deleteConfirmation = '';
        $this->showDeleteModal = true;
    }

    public function delete(LibrarySyncService $librarySync): void
    {
        Gate::authorize('manage-library');

        $item = MediaItem::query()->findOrFail($this->pendingDeletionId);

        $this->validate([
            'deleteConfirmation' => ['required', 'string', Rule::in([$item->title])],
        ], [
            'deleteConfirmation.in' => __('Enter the title exactly as shown.'),
        ]);

        try {
            $librarySync->delete($item);
        } catch (\Throwable $exception) {
            Flux::toast(variant: 'danger', text: __('Unable to delete the item: :message', ['message' => $exception->getMessage()]));

            return;
        }

        Flux::toast(variant: 'success', text: __('The item and its files were deleted from :source.', ['source' => ucfirst($item->source)]));
        $this->redirect(route('library'), navigate: true);
    }

    #[Computed]
    public function items(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return MediaItem::query()
            ->with('activeDownloadTransfer')
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->whereLike('title', '%'.$this->search.'%')
                        ->orWhereLike('sort_title', '%'.$this->search.'%');
                });
            })
            ->ofType($this->type)
            ->when($this->availability === 'available', fn (Builder $query) => $query->where('is_available', true))
            ->when($this->availability === 'missing', fn (Builder $query) => $query->where('is_available', false))
            ->orderBy('sort_title')
            ->paginate(12);
    }

    #[Computed]
    public function hasIntegrations(): bool
    {
        return Integration::query()->exists();
    }
}; ?>

<div wire:poll.30s.visible class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <flux:heading size="xl">{{ __('Library') }}</flux:heading>
                <flux:subheading>{{ __('Movies and series managed by your connected services.') }}</flux:subheading>
            </div>
        </div>

        <div class="grid gap-3" style="grid-template-columns: repeat(3, minmax(0, 1fr))">
            <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" :placeholder="__('Search titles')" />
            <flux:select wire:model.live="type" :label="__('Type')">
                <option value="">{{ __('All types') }}</option>
                <option value="movie">{{ __('Movies') }}</option>
                <option value="series">{{ __('Series') }}</option>
            </flux:select>
            <flux:select wire:model.live="availability" :label="__('Availability')">
                <option value="">{{ __('All items') }}</option>
                <option value="available">{{ __('Available') }}</option>
                <option value="missing">{{ __('Missing') }}</option>
            </flux:select>
        </div>

        @if ($this->items->isEmpty())
            <flux:card class="flex flex-col items-start gap-3 p-8">
                <flux:heading>{{ $this->hasIntegrations ? __('No matching titles') : __('Your library is not connected yet') }}</flux:heading>
                <flux:text>
                    {{ $this->hasIntegrations ? __('Try changing your filters, or ask an administrator to sync the library.') : __('An administrator can connect Radarr or Sonarr in Settings, then run the first sync.') }}
                </flux:text>
            </flux:card>
        @else
            <div class="flex flex-wrap gap-4" style="justify-content: flex-start">
                @foreach ($this->items as $item)
                    <div wire:key="media-item-{{ $item->id }}" class="shrink-0" style="width: 10rem" data-test="library-poster-{{ $item->id }}">
                        <div class="group relative aspect-[2/3] overflow-hidden rounded-xl bg-zinc-200 shadow-sm ring-1 ring-zinc-950/5 transition hover:scale-[1.02] hover:shadow-lg dark:bg-zinc-800 dark:ring-white/10">
                            <a href="{{ route('library.show', $item) }}" wire:navigate class="block h-full w-full" aria-label="{{ __('View details for :title', ['title' => $item->title]) }}">
                                @if ($item->poster_url)
                                    <img src="{{ $item->poster_url }}" alt="{{ $item->title }}" class="h-full w-full object-cover" />
                                @else
                                    <div class="flex h-full w-full items-center justify-center px-4 text-center text-sm text-zinc-600 dark:text-zinc-300">
                                        {{ __('No poster available') }}
                                    </div>
                                @endif
                            </a>
                            @if ($item->activeDownloadTransfer)
                                <div class="absolute inset-x-0 bottom-0 bg-zinc-950/85 p-2 text-white backdrop-blur-sm">
                                    <x-download-progress :transfer="$item->activeDownloadTransfer" compact />
                                </div>
                            @endif
                        </div>

                        <div class="mt-2 flex items-center gap-2" style="width: 100%">
                            <span class="h-2 w-2 shrink-0 rounded-full {{ $item->is_available ? 'bg-green-500' : 'bg-amber-500' }}" title="{{ $item->is_available ? __('Available') : __('Missing') }}" aria-label="{{ $item->is_available ? __('Available') : __('Missing') }}"></span>
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-zinc-900 dark:text-white" title="{{ $item->title }}">{{ $item->title }}</span>
                            @can('manage-library')
                                <flux:dropdown align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" aria-label="{{ __('Options for :title', ['title' => $item->title]) }}" style="flex-shrink: 0" />
                                    <flux:menu>
                                        <flux:menu.item variant="danger" icon="trash" wire:click="requestDeletion({{ $item->id }})">
                                            {{ __('Delete from :source', ['source' => ucfirst($item->source)]) }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>

            <flux:pagination :paginator="$this->items" />
        @endif

        <flux:modal wire:model.self="showDeleteModal" class="min-w-[22rem]">
            <form wire:submit="delete" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete this title and its files?') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('This permanently removes the title and its media files from the connected service. Enter the title exactly as shown to continue.') }}
                    </flux:text>
                </div>

                <flux:field>
                    <flux:label>{{ __('Title confirmation') }}</flux:label>
                    <flux:input wire:model="deleteConfirmation" autocomplete="off" />
                    <flux:error name="deleteConfirmation" />
                </flux:field>

                <div class="flex gap-3">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" type="submit" wire:loading.attr="disabled">{{ __('Delete files') }}</flux:button>
                </div>
            </form>
        </flux:modal>
</div>
