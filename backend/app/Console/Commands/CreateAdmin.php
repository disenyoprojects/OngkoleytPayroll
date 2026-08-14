<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {--name= : Display name for the login}
        {--email= : Login email (must be unique)}
        {--password= : Login password (min 8 chars)}
        {--role=admin : "admin" (sees everything) or "branch" (limited to the branches given)}
        {--branch=* : Branch name for a branch login; repeat it for a login covering several branches}
        {--force : Update the login if the email already exists}';

    protected $description = 'Create (or reset) an admin or branch login for the payroll system';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Display name', 'Admin');
        $email = $this->option('email') ?: $this->ask('Login email');
        $password = $this->option('password') ?: $this->secret('Password (min 8 chars)');
        $role = $this->option('role') ?: 'admin';

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'role' => $role],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
                'role' => ['required', 'in:admin,branch'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // A branch login may cover more than one site — pass --branch once per
        // branch. The first one given is the primary (the name shown in the
        // header after login).
        $branchIds = [];
        if ($role === 'branch') {
            $names = $this->option('branch') ?: [$this->ask('Branch name')];
            foreach ($names as $branchName) {
                $branch = Branch::where('name', $branchName)->first();
                if (! $branch) {
                    $this->error("No branch named \"{$branchName}\". Existing: " . Branch::orderBy('name')->pluck('name')->implode(', '));

                    return self::FAILURE;
                }
                $branchIds[] = $branch->id;
            }
            $branchIds = array_values(array_unique($branchIds));
        }

        $existing = User::where('email', $email)->first();

        if ($existing && ! $this->option('force')) {
            $this->error("A login with email {$email} already exists. Re-run with --force to reset it.");

            return self::FAILURE;
        }

        $attributes = [
            'name' => $name,
            'password' => Hash::make($password),
            'role' => $role,
            'branch_id' => $branchIds[0] ?? null,
        ];

        $scope = $branchIds ? ' (branches: ' . implode(', ', $this->option('branch')) . ')' : '';

        if ($existing) {
            $existing->update($attributes);
            $existing->branches()->sync($branchIds);
            $this->info("Updated {$role} login: {$email}{$scope}");

            return self::SUCCESS;
        }

        $user = User::create($attributes + ['email' => $email]);
        $user->branches()->sync($branchIds);
        $this->info("Created {$role} login: {$email}{$scope}");

        return self::SUCCESS;
    }
}
