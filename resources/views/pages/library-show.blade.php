<?php

use App\Models\MediaItem;
use App\Models\DownloadTransfer;
use Illuminate\Support\Arr;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Library details')] class extends Component {
    public MediaItem $mediaItem;

    #[Computed]
    public function activeDownloadTransfer(): ?DownloadTransfer
    {
        return $this->mediaItem->downloadTransfers()->active()->latest('last_seen_at')->first();
    }

    #[Computed]
    public function properties(): array
    {
        $metadata = $this->mediaItem->source_metadata ?? [];
        $statistics = Arr::get($metadata, 'statistics', []);
        $genres = Arr::wrap($metadata['genres'] ?? []);
        $rating = Arr::get($metadata, 'ratings.value')
            ?? Arr::get($metadata, 'ratings.imdb.value')
            ?? Arr::get($metadata, 'ratings.tmdb.value');

        return collect([
            ['label' => __('Status'), 'value' => $metadata['status'] ?? null],
            ['label' => __('Genres'), 'value' => $genres === [] ? null : implode(', ', $genres)],
            ['label' => __('Runtime'), 'value' => isset($metadata['runtime']) ? __(':minutes min', ['minutes' => $metadata['runtime']]) : null],
            ['label' => __('Certification'), 'value' => $metadata['certification'] ?? null],
            ['label' => __('Rating'), 'value' => $rating],
            ['label' => __('Studio'), 'value' => $metadata['studio'] ?? $metadata['network'] ?? null],
            ['label' => __('Original language'), 'value' => Arr::get($metadata, 'originalLanguage.name')],
            ['label' => __('Path'), 'value' => $metadata['path'] ?? null],
            ['label' => __('Quality profile'), 'value' => $metadata['qualityProfileId'] ?? null],
            ['label' => __('Minimum availability'), 'value' => $metadata['minimumAvailability'] ?? null],
            ['label' => __('Episode count'), 'value' => $statistics['episodeCount'] ?? null],
            ['label' => __('Episode files'), 'value' => $statistics['episodeFileCount'] ?? null],
            ['label' => __('TMDB ID'), 'value' => $metadata['tmdbId'] ?? null],
            ['label' => __('TVDB ID'), 'value' => $metadata['tvdbId'] ?? null],
            ['label' => __('IMDB ID'), 'value' => $metadata['imdbId'] ?? null],
        ])->filter(fn (array $property): bool => filled($property['value']))->values()->all();
    }
}; ?>

<div wire:poll.30s.visible class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6" style="padding: 1rem 0">
    <div>
        <flux:button :href="route('library')" wire:navigate variant="ghost" icon="arrow-left">{{ __('Back to library') }}</flux:button>
    </div>

    <div class="grid gap-8" style="grid-template-columns: minmax(12rem, 18rem) minmax(0, 1fr); column-gap: 3rem">
        <div class="aspect-[2/3] overflow-hidden rounded-xl bg-zinc-200 shadow-sm dark:bg-zinc-800">
            @if ($mediaItem->poster_url)
                <img src="{{ $mediaItem->poster_url }}" alt="{{ $mediaItem->title }}" class="h-full w-full object-cover" />
            @else
                <div class="flex h-full items-center justify-center px-4 text-center text-sm text-zinc-600 dark:text-zinc-300">{{ __('No poster available') }}</div>
            @endif
        </div>

        <div class="flex flex-col gap-6">
            <div class="space-y-3">
                <flux:heading size="xl">{{ $mediaItem->title }}</flux:heading>
                <div class="flex flex-wrap gap-2">
                    @if ($mediaItem->year)
                        <flux:badge>{{ $mediaItem->year }}</flux:badge>
                    @endif
                    <flux:badge>{{ ucfirst($mediaItem->type) }}</flux:badge>
                    <flux:badge color="zinc">{{ ucfirst($mediaItem->source) }}</flux:badge>
                    <flux:badge :color="$mediaItem->is_available ? 'green' : 'amber'">{{ $mediaItem->is_available ? __('Available') : __('Missing') }}</flux:badge>
                    @if ($mediaItem->is_monitored)
                        <flux:badge color="blue">{{ __('Monitored') }}</flux:badge>
                    @endif
                </div>
            </div>

            @if ($this->activeDownloadTransfer)
                <flux:card class="space-y-3 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <flux:heading>{{ __('Downloading') }}</flux:heading>
                            <flux:subheading>{{ $this->activeDownloadTransfer->stateLabel() }}</flux:subheading>
                        </div>
                        <flux:badge color="blue">{{ $this->activeDownloadTransfer->progressPercentage() }}%</flux:badge>
                    </div>
                    <x-download-progress :transfer="$this->activeDownloadTransfer" />
                </flux:card>
            @endif

            @if ($mediaItem->overview)
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('Overview') }}</flux:heading>
                    <flux:text>{{ $mediaItem->overview }}</flux:text>
                </div>
            @endif

            @if ($this->properties !== [])
                <div class="space-y-3">
                    <flux:heading size="lg">{{ __('Details') }}</flux:heading>
                    <dl class="grid gap-x-6 gap-y-4" style="grid-template-columns: repeat(2, minmax(0, 1fr))">
                        @foreach ($this->properties as $property)
                            <div class="flex items-baseline gap-3">
                                <dt class="shrink-0 text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ $property['label'] }}</dt>
                                <dd class="min-w-0 break-words text-sm text-zinc-900 dark:text-white">{{ $property['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </div>
    </div>
</div>
