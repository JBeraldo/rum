<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('Rum')])
    </head>
    <body class="min-h-screen bg-zinc-950 text-white antialiased">
        <div class="pointer-events-none fixed inset-0 overflow-hidden" aria-hidden="true">
            <div class="absolute left-1/2 top-0 h-[40rem] w-[40rem] -translate-x-1/2 rounded-full bg-violet-500/15 blur-3xl"></div>
            <div class="absolute -right-32 top-80 h-96 w-96 rounded-full bg-cyan-400/10 blur-3xl"></div>
        </div>

        <main class="relative mx-auto flex min-h-screen w-full max-w-6xl flex-col px-6 py-6 sm:px-10">
            <header class="flex items-center justify-between gap-4">
                <x-app-logo href="{{ route('home') }}" />

                <nav class="flex items-center gap-2 sm:gap-3" aria-label="{{ __('Authentication') }}">
                    @auth
                        <flux:button :href="route('dashboard')" wire:navigate>{{ __('Open dashboard') }}</flux:button>
                    @else
                        <flux:button :href="route('login')" variant="ghost" wire:navigate>{{ __('Log in') }}</flux:button>
                        @if (Route::has('register'))
                            <flux:button :href="route('register')" wire:navigate>{{ __('Get started') }}</flux:button>
                        @endif
                    @endauth
                </nav>
            </header>

            <section class="grid flex-1 items-center gap-12 py-16 lg:grid-cols-[1fr_1.05fr] lg:py-24">
                <div class="max-w-xl">
                    <flux:badge color="violet" rounded icon="heart">{{ __('Your shared media space') }}</flux:badge>
                    <h1 class="mt-6 text-5xl font-semibold tracking-tight sm:text-6xl">
                        {{ __('A better home for what everyone wants to watch.') }}
                    </h1>
                    <p class="mt-6 max-w-lg text-lg leading-8 text-zinc-400">
                        {{ __('Rum brings movies, series, and shared requests into one calm library for your whole household.') }}
                    </p>

                    @guest
                        <div class="mt-8 flex flex-wrap gap-3">
                            <flux:button :href="route('register')" icon="arrow-right" wire:navigate>{{ __('Create your space') }}</flux:button>
                            <flux:button :href="route('login')" variant="ghost" wire:navigate>{{ __('I already have an account') }}</flux:button>
                        </div>
                    @endguest

                    <div class="mt-10 flex flex-wrap gap-x-6 gap-y-3 text-sm text-zinc-400">
                        <span class="flex items-center gap-2"><span class="size-2 rounded-full bg-emerald-400"></span>{{ __('Radarr ready') }}</span>
                        <span class="flex items-center gap-2"><span class="size-2 rounded-full bg-sky-400"></span>{{ __('Sonarr ready') }}</span>
                        <span class="flex items-center gap-2"><span class="size-2 rounded-full bg-violet-400"></span>{{ __('Shared wishlist') }}</span>
                    </div>
                </div>

                <div class="relative mx-auto w-full max-w-xl">
                    <div class="absolute -inset-4 rounded-[2rem] bg-linear-to-br from-violet-500/20 via-transparent to-cyan-400/20 blur-2xl"></div>
                    <div class="relative overflow-hidden rounded-3xl border border-white/10 bg-zinc-900/90 p-3 shadow-2xl shadow-black/40 backdrop-blur">
                        <div class="flex items-center gap-2 rounded-2xl border border-white/8 bg-white/5 px-4 py-3">
                            <div class="flex gap-1.5"><span class="size-2.5 rounded-full bg-rose-400/80"></span><span class="size-2.5 rounded-full bg-amber-300/80"></span><span class="size-2.5 rounded-full bg-emerald-400/80"></span></div>
                            <div class="mx-auto rounded-full bg-black/20 px-4 py-1 text-xs text-zinc-400">{{ __('Your library') }}</div>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-[9rem_1fr]">
                            <div class="rounded-2xl border border-white/8 bg-black/20 p-3">
                                <div class="text-xs font-medium uppercase tracking-widest text-zinc-500">{{ __('Rum') }}</div>
                                <div class="mt-5 space-y-3 text-sm text-zinc-400">
                                    <div class="rounded-lg bg-white/10 px-3 py-2 text-white">{{ __('Library') }}</div>
                                    <div class="px-3">{{ __('Wishlist') }}</div>
                                    <div class="px-3">{{ __('Activity') }}</div>
                                </div>
                                <div class="mt-10 rounded-xl border border-violet-400/20 bg-violet-500/10 p-3">
                                    <div class="text-xs text-violet-200">{{ __('Shared queue') }}</div>
                                    <div class="mt-1 text-2xl font-semibold text-white">12</div>
                                    <div class="text-xs text-zinc-400">{{ __('titles waiting') }}</div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-white/8 bg-black/15 p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <div class="font-medium">{{ __('Continue browsing') }}</div>
                                        <div class="mt-1 text-sm text-zinc-400">{{ __('Picked for your shared queue') }}</div>
                                    </div>
                                    <flux:badge color="emerald" size="sm" rounded>{{ __('Synced') }}</flux:badge>
                                </div>
                                <div class="mt-5 grid grid-cols-3 gap-3">
                                    <div class="aspect-[2/3] rounded-xl bg-linear-to-b from-amber-200 via-rose-400 to-violet-900 p-3"><div class="text-xs font-semibold text-black/70">{{ __('DRIFT') }}</div></div>
                                    <div class="aspect-[2/3] rounded-xl bg-linear-to-b from-sky-300 via-cyan-500 to-blue-950 p-3"><div class="text-xs font-semibold text-white/80">{{ __('ORBIT') }}</div></div>
                                    <div class="aspect-[2/3] rounded-xl bg-linear-to-b from-emerald-200 via-teal-500 to-zinc-950 p-3"><div class="text-xs font-semibold text-black/70">{{ __('ECHO') }}</div></div>
                                </div>
                                <div class="mt-4 flex items-center justify-between rounded-xl border border-white/8 bg-white/5 px-3 py-2.5">
                                    <div class="flex min-w-0 items-center gap-2"><span class="size-2 rounded-full bg-violet-400"></span><span class="truncate text-sm text-zinc-300">{{ __('3 friends requested a new series') }}</span></div>
                                    <flux:icon name="arrow-down-tray" class="size-4 shrink-0 text-zinc-400" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 pb-10 md:grid-cols-3">
                <flux:card class="border-white/10 bg-white/5 p-5">
                    <flux:icon name="book-open" class="size-5 text-sky-300" />
                    <flux:heading class="mt-4">{{ __('One library') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Browse movies and series together without jumping between apps.') }}</flux:text>
                </flux:card>
                <flux:card class="border-white/10 bg-white/5 p-5">
                    <flux:icon name="heart" class="size-5 text-violet-300" />
                    <flux:heading class="mt-4">{{ __('One shared wishlist') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Everyone can request a title; Rum keeps one shared queue.') }}</flux:text>
                </flux:card>
                <flux:card class="border-white/10 bg-white/5 p-5">
                    <flux:icon name="arrow-down-tray" class="size-5 text-emerald-300" />
                    <flux:heading class="mt-4">{{ __('Space-aware downloads') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Requests wait safely until there is room to download them.') }}</flux:text>
                </flux:card>
            </section>
        </main>

        @fluxScripts
    </body>
</html>
