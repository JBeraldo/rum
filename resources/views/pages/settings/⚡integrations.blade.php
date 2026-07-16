<?php

use App\Models\DownloadClient;
use App\Models\DiscordWebhook;
use App\Models\Integration;
use App\Services\DiscordWebhookService;
use App\Services\DownloadSyncService;
use App\Services\LibrarySyncService;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Integration settings')] class extends Component {
    public string $radarrBaseUrl = '';
    public string $radarrApiKey = '';
    public string $radarrQualityProfileId = '';
    /** @var array<int, array{id: int, name: string}> */
    public array $radarrQualityProfiles = [];
    public string $sonarrBaseUrl = '';
    public string $sonarrApiKey = '';
    public string $sonarrQualityProfileId = '';
    /** @var array<int, array{id: int, name: string}> */
    public array $sonarrQualityProfiles = [];
    public string $qbittorrentBaseUrl = '';
    public string $qbittorrentUsername = '';
    public string $qbittorrentPassword = '';
    public string $qbittorrentApiKey = '';
    public string $discordWebhookUrl = '';
    /** @var array<int, string> */
    public array $discordEvents = [];
    public bool $hasDiscordWebhook = false;
    /** @var array<string, array{last_tested_at: string|null, last_synced_at: string|null, last_error: string|null, configured?: bool, has_api_key?: bool}> */
    public array $integrationStatuses = [];

    public function mount(): void
    {
        Gate::authorize('manage-library');

        $this->radarrBaseUrl = Integration::query()->where('source', Integration::RADARR)->value('base_url') ?? '';
        $this->radarrQualityProfileId = (string) (Integration::query()->where('source', Integration::RADARR)->value('default_quality_profile_id') ?? '');
        $this->sonarrBaseUrl = Integration::query()->where('source', Integration::SONARR)->value('base_url') ?? '';
        $this->sonarrQualityProfileId = (string) (Integration::query()->where('source', Integration::SONARR)->value('default_quality_profile_id') ?? '');
        $downloadClient = DownloadClient::query()->where('type', DownloadClient::QBITTORRENT)->first();
        $this->qbittorrentBaseUrl = $downloadClient?->base_url ?? '';
        $this->qbittorrentUsername = $downloadClient?->username ?? '';
        $discordWebhook = DiscordWebhook::query()->first();
        $this->discordEvents = $discordWebhook?->events ?? array_keys(DiscordWebhookService::EVENTS);
        $this->hasDiscordWebhook = $discordWebhook !== null;
        $this->refreshIntegrationStatuses();
    }

    public function saveRadarr(LibrarySyncService $librarySync): void
    {
        $this->save(Integration::RADARR, 'radarrBaseUrl', 'radarrApiKey', 'radarrQualityProfileId', $librarySync);
    }

    public function saveSonarr(LibrarySyncService $librarySync): void
    {
        $this->save(Integration::SONARR, 'sonarrBaseUrl', 'sonarrApiKey', 'sonarrQualityProfileId', $librarySync);
    }

    public function saveQbittorrent(DownloadSyncService $downloadSync): void
    {
        Gate::authorize('manage-library');

        $this->validate([
            'qbittorrentBaseUrl' => ['required', 'url', 'max:255'],
            'qbittorrentUsername' => ['nullable', 'string', 'max:255'],
            'qbittorrentPassword' => ['nullable', 'string', 'max:255'],
            'qbittorrentApiKey' => ['nullable', 'string', 'regex:/^qbt_[A-Za-z0-9]{28}$/'],
        ], [
            'qbittorrentApiKey.regex' => __('The API key must start with qbt_ followed by 28 letters or numbers.'),
        ]);

        $existing = DownloadClient::query()->where('type', DownloadClient::QBITTORRENT)->first();
        $password = $this->qbittorrentPassword !== '' ? $this->qbittorrentPassword : $existing?->password;
        $apiKey = $this->qbittorrentApiKey !== '' ? $this->qbittorrentApiKey : $existing?->api_key;
        $hasApiKey = filled($apiKey);
        $hasPasswordLogin = filled($this->qbittorrentUsername) && filled($password);

        if (! $hasApiKey && ! $hasPasswordLogin) {
            $this->addError('qbittorrentApiKey', __('Enter an API key or provide both a username and password.'));

            return;
        }

        $candidate = new DownloadClient([
            'type' => DownloadClient::QBITTORRENT,
            'base_url' => rtrim($this->qbittorrentBaseUrl, '/'),
            'username' => trim($this->qbittorrentUsername),
            'password' => $password ?? '',
            'api_key' => $apiKey,
        ]);

        try {
            $downloadSync->test($candidate);
        } catch (Throwable $exception) {
            $message = $downloadSync->exceptionMessage($exception);

            if ($existing !== null) {
                $existing->forceFill(['last_error' => $message])->save();
                $this->refreshIntegrationStatuses();
            }

            $errorProperty = $hasApiKey ? 'qbittorrentApiKey' : 'qbittorrentPassword';
            $this->addError($errorProperty, __('Unable to connect: :message', ['message' => $message]));

            return;
        }

        DownloadClient::query()->updateOrCreate(
            ['type' => DownloadClient::QBITTORRENT],
            [
                'base_url' => $candidate->base_url,
                'username' => $candidate->username,
                'password' => $password ?? '',
                'api_key' => $apiKey,
                'last_tested_at' => now(),
                'last_error' => null,
            ],
        );

        $this->qbittorrentPassword = '';
        $this->qbittorrentApiKey = '';
        $this->refreshIntegrationStatuses();
        Flux::toast(variant: 'success', text: __('qBittorrent connection verified and saved.'));
    }

    public function syncDownloads(DownloadSyncService $downloadSync): void
    {
        Gate::authorize('manage-library');

        $client = DownloadClient::query()->where('type', DownloadClient::QBITTORRENT)->first();

        if ($client === null) {
            Flux::toast(variant: 'danger', text: __('Save the qBittorrent connection before syncing it.'));

            return;
        }

        $result = $downloadSync->sync($client);
        $this->refreshIntegrationStatuses();

        if (! $result['successful']) {
            Flux::toast(variant: 'danger', text: $client->fresh()->last_error ?: __('The download sync failed.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Tracked :tracked downloads; :active active.', $result));
    }

    public function saveDiscordWebhook(DiscordWebhookService $discordWebhookService): void
    {
        Gate::authorize('manage-library');

        $existing = DiscordWebhook::query()->first();

        $this->validate([
            'discordWebhookUrl' => [$existing === null ? 'required' : 'nullable', 'url', 'max:2048'],
            'discordEvents' => ['required', 'array', 'min:1'],
            'discordEvents.*' => ['required', 'string', Rule::in(array_keys(DiscordWebhookService::EVENTS))],
        ]);

        $webhookUrl = $this->discordWebhookUrl !== '' ? trim($this->discordWebhookUrl) : $existing?->webhook_url;

        if ($webhookUrl === null || ! DiscordWebhookService::isDiscordWebhookUrl($webhookUrl)) {
            $this->addError('discordWebhookUrl', __('Enter a valid Discord webhook URL.'));

            return;
        }

        $candidate = new DiscordWebhook([
            'webhook_url' => $webhookUrl,
            'events' => $this->discordEvents,
        ]);

        try {
            $discordWebhookService->test($candidate);
        } catch (Throwable $exception) {
            $this->addError('discordWebhookUrl', __('Unable to connect: :message', ['message' => $exception->getMessage()]));

            return;
        }

        $webhook = $existing ?? new DiscordWebhook;
        $webhook->fill([
            'webhook_url' => $webhookUrl,
            'events' => $this->discordEvents,
        ])->save();

        $this->discordWebhookUrl = '';
        $this->hasDiscordWebhook = true;
        Flux::toast(variant: 'success', text: __('Discord webhook verified and saved.'));
    }

    public function loadQualityProfiles(string $source, LibrarySyncService $librarySync): void
    {
        Gate::authorize('manage-library');

        $integration = Integration::query()->where('source', $source)->first();

        if ($integration === null) {
            Flux::toast(variant: 'danger', text: __('Save a connection before loading quality profiles.'));

            return;
        }

        try {
            $profiles = $librarySync->qualityProfiles($integration);
        } catch (Throwable $exception) {
            Flux::toast(variant: 'danger', text: __('Unable to load quality profiles: :message', ['message' => $exception->getMessage()]));

            return;
        }

        $profilesProperty = $source === Integration::RADARR ? 'radarrQualityProfiles' : 'sonarrQualityProfiles';
        $profileIdProperty = $source === Integration::RADARR ? 'radarrQualityProfileId' : 'sonarrQualityProfileId';
        $this->{$profilesProperty} = $profiles;

        if ($this->{$profileIdProperty} === '' && $profiles !== []) {
            $this->{$profileIdProperty} = (string) $profiles[0]['id'];
        }
    }

    public function sync(string $source, LibrarySyncService $librarySync): void
    {
        Gate::authorize('manage-library');

        $integration = Integration::query()->where('source', $source)->first();

        if ($integration === null) {
            Flux::toast(variant: 'danger', text: __('Save a connection before syncing it.'));

            return;
        }

        if ($librarySync->sync($integration)) {
            $this->refreshIntegrationStatuses();
            Flux::toast(variant: 'success', text: __('Library synchronized.'));

            return;
        }

        $this->refreshIntegrationStatuses();
        Flux::toast(variant: 'danger', text: $integration->fresh()->last_error ?: __('The sync failed.'));
    }

    private function save(string $source, string $baseUrlProperty, string $apiKeyProperty, string $qualityProfileIdProperty, LibrarySyncService $librarySync): void
    {
        Gate::authorize('manage-library');

        $this->validate([
            $baseUrlProperty => ['required', 'url', 'max:255'],
            $apiKeyProperty => ['nullable', 'string', 'max:255'],
            $qualityProfileIdProperty => ['nullable', 'integer', 'min:1'],
        ]);

        $existing = Integration::query()->where('source', $source)->first();
        $apiKey = $this->{$apiKeyProperty} !== '' ? $this->{$apiKeyProperty} : $existing?->api_key;

        if ($apiKey === null || $apiKey === '') {
            $this->addError($apiKeyProperty, __('An API key is required.'));

            return;
        }

        $candidate = new Integration([
            'source' => $source,
            'base_url' => rtrim($this->{$baseUrlProperty}, '/'),
            'api_key' => $apiKey,
        ]);

        try {
            $librarySync->test($candidate);
        } catch (Throwable $exception) {
            if ($existing !== null) {
                $existing->forceFill(['last_error' => Str::limit($exception->getMessage(), 1000)])->save();
                $this->refreshIntegrationStatuses();
            }

            $this->addError($apiKeyProperty, __('Unable to connect: :message', ['message' => $exception->getMessage()]));

            return;
        }

        Integration::query()->updateOrCreate(
            ['source' => $source],
            [
                'base_url' => $candidate->base_url,
                'api_key' => $apiKey,
                'default_quality_profile_id' => $this->{$qualityProfileIdProperty} === '' ? null : (int) $this->{$qualityProfileIdProperty},
                'last_tested_at' => now(),
                'last_error' => null,
            ],
        );

        $this->{$apiKeyProperty} = '';
        $this->refreshIntegrationStatuses();
        Flux::toast(variant: 'success', text: __('Connection verified and saved.'));
    }

    private function refreshIntegrationStatuses(): void
    {
        $this->integrationStatuses = Integration::query()
            ->get(['source', 'last_tested_at', 'last_synced_at', 'last_error'])
            ->mapWithKeys(fn (Integration $integration): array => [$integration->source => [
                'last_tested_at' => $integration->last_tested_at?->diffForHumans(),
                'last_synced_at' => $integration->last_synced_at?->diffForHumans(),
                'last_error' => $integration->last_error,
            ]])
            ->all();

        $downloadClient = DownloadClient::query()->where('type', DownloadClient::QBITTORRENT)->first();

        if ($downloadClient !== null) {
            $this->integrationStatuses[DownloadClient::QBITTORRENT] = [
                'last_tested_at' => $downloadClient->last_tested_at?->diffForHumans(),
                'last_synced_at' => $downloadClient->last_synced_at?->diffForHumans(),
                'last_error' => $downloadClient->last_error,
                'configured' => filled($downloadClient->api_key) || (filled($downloadClient->username) && filled($downloadClient->password)),
                'has_api_key' => filled($downloadClient->api_key),
            ];
        }
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Integrations')" :subheading="__('Connect the services that manage your media library')">
        <div class="my-6 space-y-6">
            @foreach ([['radarr', 'Radarr', 'Movies'], ['sonarr', 'Sonarr', 'Series']] as [$key, $name, $mediaType])
                @php($integration = $this->integrationStatuses[$key] ?? null)
                <flux:card wire:key="integration-{{ $key }}" class="space-y-4 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <flux:heading>{{ $name }}</flux:heading>
                            <flux:subheading>{{ __('Managed :type', ['type' => strtolower($mediaType)]) }}</flux:subheading>
                        </div>
                        @if ($integration['last_synced_at'] ?? false)
                            <flux:text class="text-xs">{{ __('Synced :time', ['time' => $integration['last_synced_at']]) }}</flux:text>
                        @elseif ($integration['last_tested_at'] ?? false)
                            <flux:text class="text-xs">{{ __('Connection verified :time', ['time' => $integration['last_tested_at']]) }}</flux:text>
                        @endif
                    </div>

                    <form wire:submit="save{{ $name }}" class="space-y-4">
                        <flux:input wire:model="{{ $key }}BaseUrl" :label="__('Base URL')" placeholder="http://localhost:7878" />
                        <flux:input wire:model="{{ $key }}ApiKey" type="password" :label="$integration ? __('New API key (leave blank to keep the current key)') : __('API key')" autocomplete="new-password" />

                        @if ($this->{$key.'QualityProfiles'} !== [])
                            <flux:select wire:model="{{ $key }}QualityProfileId" :label="__('Default quality profile')">
                                <option value="">{{ __('No default profile') }}</option>
                                @foreach ($this->{$key.'QualityProfiles'} as $profile)
                                    <option value="{{ $profile['id'] }}">{{ $profile['name'] }}</option>
                                @endforeach
                            </flux:select>
                        @endif

                        @if ($integration['last_error'] ?? false)
                            <flux:callout variant="danger" icon="exclamation-triangle">{{ $integration['last_error'] }}</flux:callout>
                        @endif

                        <div class="flex gap-3">
                            <flux:button variant="primary" type="submit">{{ __('Save connection') }}</flux:button>
                            @if ($integration)
                                <flux:button wire:click="loadQualityProfiles('{{ $key }}')" type="button" wire:loading.attr="disabled">{{ __('Load quality profiles') }}</flux:button>
                                <flux:button wire:click="sync('{{ $key }}')" type="button" wire:loading.attr="disabled">{{ __('Sync now') }}</flux:button>
                            @endif
                        </div>
                    </form>
                </flux:card>
            @endforeach

            @php($downloadClient = $this->integrationStatuses[\App\Models\DownloadClient::QBITTORRENT] ?? null)
            <flux:card class="space-y-4 p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading>{{ __('qBittorrent') }}</flux:heading>
                        <flux:subheading>{{ __('Read-only progress tracking for Radarr and Sonarr downloads') }}</flux:subheading>
                    </div>
                    @if ($downloadClient['last_synced_at'] ?? false)
                        <flux:text class="text-xs">{{ __('Synced :time', ['time' => $downloadClient['last_synced_at']]) }}</flux:text>
                    @elseif ($downloadClient['last_tested_at'] ?? false)
                        <flux:text class="text-xs">{{ __('Connection verified :time', ['time' => $downloadClient['last_tested_at']]) }}</flux:text>
                    @endif
                </div>

                <form wire:submit="saveQbittorrent" class="space-y-4">
                    <flux:input wire:model="qbittorrentBaseUrl" :label="__('Base URL')" placeholder="http://localhost:8080" />
                    <flux:input wire:model="qbittorrentApiKey" type="password" :label="($downloadClient['has_api_key'] ?? false) ? __('New API key (leave blank to keep the current key)') : __('API key (qBittorrent 5.2+)')" placeholder="qbt_..." autocomplete="new-password" />
                    <flux:text class="text-xs">{{ __('API key authentication is preferred. Username and password remain available as a fallback.') }}</flux:text>
                    <flux:input wire:model="qbittorrentUsername" :label="__('Username')" autocomplete="username" />
                    <flux:input wire:model="qbittorrentPassword" type="password" :label="$downloadClient ? __('New password (leave blank to keep the current password)') : __('Password')" autocomplete="new-password" />

                    @if ($downloadClient['last_error'] ?? false)
                        <flux:callout variant="danger" icon="exclamation-triangle">
                            <div class="flex items-start justify-between gap-3">
                                <span class="whitespace-pre-wrap break-words">{{ $downloadClient['last_error'] }}</span>
                                <flux:button x-on:click="navigator.clipboard.writeText($el.dataset.copyError)" data-copy-error="{{ $downloadClient['last_error'] }}" icon="clipboard-document" variant="subtle" size="xs" square :loading="false" tooltip="{{ __('Copy last error') }}" aria-label="{{ __('Copy last error') }}" />
                            </div>
                        </flux:callout>
                    @endif

                    <div class="flex flex-wrap gap-3">
                        <flux:button variant="primary" type="submit" wire:loading.attr="disabled">{{ __('Save connection') }}</flux:button>
                        @if ($downloadClient['configured'] ?? false)
                            <flux:button wire:click="syncDownloads" type="button" wire:loading.attr="disabled">{{ __('Sync downloads now') }}</flux:button>
                        @endif
                    </div>
                </form>
            </flux:card>

            <flux:card class="space-y-4 p-5">
                <div>
                    <flux:heading>{{ __('Discord') }}</flux:heading>
                    <flux:subheading>{{ __('Send selected activity events to a Discord channel') }}</flux:subheading>
                </div>

                <form wire:submit="saveDiscordWebhook" class="space-y-4">
                    <flux:input wire:model="discordWebhookUrl" type="password" :label="$hasDiscordWebhook ? __('New webhook URL (leave blank to keep the current URL)') : __('Webhook URL')" placeholder="https://discord.com/api/webhooks/..." autocomplete="new-password" />
                    <flux:error name="discordWebhookUrl" />

                    <flux:checkbox.group wire:model="discordEvents" :label="__('Events to send')" :description="__('Only the selected activity events will be posted to Discord.')">
                        @foreach (\App\Services\DiscordWebhookService::EVENTS as $event => $label)
                            <flux:checkbox value="{{ $event }}" :label="__($label)" />
                        @endforeach
                    </flux:checkbox.group>
                    <flux:error name="discordEvents" />

                    <flux:button variant="primary" type="submit" wire:loading.attr="disabled">{{ __('Save Discord webhook') }}</flux:button>
                </form>
            </flux:card>
        </div>
    </x-pages::settings.layout>
</section>
