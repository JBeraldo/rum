<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

class GrantUserRole extends Command
{
    protected $signature = 'users:grant-role {email : The user email address} {role : The role name}';

    protected $description = 'Assign a role to an existing user';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        $role = Role::query()->where('name', $this->argument('role'))->first();

        if ($user === null || $role === null) {
            $this->error($user === null ? 'User not found.' : 'Role not found.');

            return self::FAILURE;
        }

        $user->roles()->syncWithoutDetaching([$role->id]);
        $this->info("Assigned {$role->name} to {$user->email}.");

        return self::SUCCESS;
    }
}
