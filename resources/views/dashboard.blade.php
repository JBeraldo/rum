<x-layouts::app :title="__('Dashboard')">
    <div class="flex w-full flex-1 flex-col gap-6 rounded-xl">
        <div>
            <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
            <flux:subheading>{{ __('Your media library and shared request queue at a glance.') }}</flux:subheading>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <flux:card class="space-y-1 p-5">
                <flux:text>{{ __('Library titles') }}</flux:text>
                <flux:heading size="xl">{{ number_format($libraryStats['total']) }}</flux:heading>
                <flux:text class="text-sm">{{ __('Movies and series') }}</flux:text>
            </flux:card>
            <flux:card class="space-y-1 p-5">
                <flux:text>{{ __('Available now') }}</flux:text>
                <flux:heading size="xl">{{ number_format($libraryStats['available']) }}</flux:heading>
                <flux:text class="text-sm">{{ __(':count missing', ['count' => number_format($libraryStats['unavailable'])]) }}</flux:text>
            </flux:card>
            <flux:card class="space-y-1 p-5">
                <flux:text>{{ __('Movies') }}</flux:text>
                <flux:heading size="xl">{{ number_format($libraryStats['movies']) }}</flux:heading>
                <flux:text class="text-sm">{{ __('In your library') }}</flux:text>
            </flux:card>
            <flux:card class="space-y-1 p-5">
                <flux:text>{{ __('Series') }}</flux:text>
                <flux:heading size="xl">{{ number_format($libraryStats['series']) }}</flux:heading>
                <flux:text class="text-sm">{{ __('In your library') }}</flux:text>
            </flux:card>
        </div>

        <livewire:active-downloads />

        <div class="grid gap-4 lg:grid-cols-3">
            <flux:card class="space-y-4 p-5 lg:col-span-1">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <flux:heading>{{ __('Shared wishlist') }}</flux:heading>
                        <flux:subheading>{{ __('Current request queue') }}</flux:subheading>
                    </div>
                    <flux:badge color="zinc">{{ number_format($wishlistStats['total']) }}</flux:badge>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <flux:text class="text-sm">{{ __('Pending') }}</flux:text>
                        <flux:heading size="lg">{{ number_format($wishlistStats['pending']) }}</flux:heading>
                    </div>
                    <div>
                        <flux:text class="text-sm">{{ __('Requested') }}</flux:text>
                        <flux:heading size="lg">{{ number_format($wishlistStats['requested']) }}</flux:heading>
                    </div>
                </div>
                <flux:text class="text-sm">{{ trans_choice(':count integration configured|:count integrations configured', $integrationCount) }}</flux:text>
            </flux:card>

            <flux:card class="p-5 lg:col-span-2">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <flux:heading>{{ __('Recent activity') }}</flux:heading>
                        <flux:subheading>{{ __('Latest source and queue events') }}</flux:subheading>
                    </div>
                    <flux:badge color="zinc">{{ $recentLogs->count() }}</flux:badge>
                </div>

                @if ($recentLogs->isEmpty())
                    <flux:text class="mt-6">{{ __('No activity has been recorded yet.') }}</flux:text>
                @else
                    <div class="mt-5 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($recentLogs as $log)
                            <div class="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:badge size="sm" color="zinc">{{ \Illuminate\Support\Str::headline($log->event) }}</flux:badge>
                                        @if ($log->subject?->title)
                                            <flux:text class="font-medium">{{ $log->subject->title }}</flux:text>
                                        @endif
                                    </div>
                                    @if ($log->message)
                                        <flux:text class="mt-1 block text-sm">{{ \Illuminate\Support\Str::limit($log->message, 180) }}</flux:text>
                                    @endif
                                </div>
                                <flux:text class="shrink-0 text-xs">{{ $log->created_at?->diffForHumans() }}</flux:text>
                            </div>
                        @endforeach
                    </div>
                @endif
            </flux:card>
        </div>
    </div>
</x-layouts::app>
