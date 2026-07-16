<?php

use App\Models\Role;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('User management')] class extends Component {
    /** @var array<int, string> */
    public array $roleAssignments = [];

    public function mount(): void
    {
        Gate::authorize('manage-library');

        $this->roleAssignments = User::query()
            ->with('roles:id,name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->roles->first()?->name ?? 'member'])
            ->all();
    }

    #[Computed]
    public function users(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()->with('roles:id,name')->orderBy('name')->get();
    }

    #[Computed]
    public function roles(): \Illuminate\Database\Eloquent\Collection
    {
        return Role::query()->orderBy('name')->get();
    }

    public function updateRole(int $userId): void
    {
        Gate::authorize('manage-library');

        $this->validate(['roleAssignments.'.$userId => ['required', 'exists:roles,name']]);

        $user = User::query()->findOrFail($userId);
        $role = Role::query()->where('name', $this->roleAssignments[$userId])->firstOrFail();
        $user->roles()->sync([$role->id]);

        unset($this->users);
        Flux::toast(variant: 'success', text: __('User role updated.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('User management')" :subheading="__('Assign access roles to application users')">
        <div class="my-6 space-y-4">
            @foreach ($this->users as $user)
                <flux:card wire:key="user-{{ $user->id }}" class="flex flex-col gap-4 p-5 sm:flex-row sm:items-end sm:justify-between">
                    <div class="min-w-0">
                        <flux:heading class="truncate">{{ $user->name }}</flux:heading>
                        <flux:text class="truncate">{{ $user->email }}</flux:text>
                    </div>

                    <div class="flex flex-col items-start gap-3">
                        <flux:select wire:model="roleAssignments.{{ $user->id }}" :label="__('Role')">
                            @foreach ($this->roles as $role)
                                <option value="{{ $role->name }}">{{ ucfirst($role->name) }}</option>
                            @endforeach
                        </flux:select>
                        <flux:button wire:click="updateRole({{ $user->id }})" type="button">{{ __('Save') }}</flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>
    </x-pages::settings.layout>
</section>
