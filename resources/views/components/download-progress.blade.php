@props(['transfer', 'compact' => false])

<div {{ $attributes->class($compact ? 'space-y-1' : 'space-y-2') }}>
    @if ($compact)
        <div class="flex items-center justify-between gap-2 text-[0.7rem] font-medium">
            <span>{{ __('Downloading') }}</span>
            <span>{{ $transfer->progressPercentage() }}%</span>
        </div>
    @endif

    <flux:progress :value="$transfer->progressPercentage()" max="100" color="blue" />

    @unless ($compact)
        <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
            <span>{{ $transfer->progressPercentage() }}%</span>
            <span>{{ \Illuminate\Support\Number::fileSize($transfer->download_speed) }}/s</span>
            @if ($transfer->formattedEta())
                <span>{{ __('ETA :eta', ['eta' => $transfer->formattedEta()]) }}</span>
            @endif
            <span>{{ __(':size remaining', ['size' => \Illuminate\Support\Number::fileSize($transfer->amount_left_bytes)]) }}</span>
        </div>
    @endunless
</div>
