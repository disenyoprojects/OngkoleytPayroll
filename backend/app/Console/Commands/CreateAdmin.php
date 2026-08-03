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
        {--role=admin : "admin" (sees everything) or "branch" (one branch only)}
        {--branch= : Branch name for a branch login (required when role=branch)}
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

        $branchId = null;
        if ($role === 'branch') {
            $branchName = $this->option('branch') ?: $this->ask('Branch name');
            $branch = Branch::where('name', $branchName)->first();
            if (! $branch) {
                $this->error("No branch named \"{$branchName}\". Existing: " . Branch::orderBy('name')->pluck('name')->implode(', '));

                return self::FAILURE;
            }
            $branchId = $branch->id;
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
            'branch_id' => $branchId,
        ];

        if ($existing) {
            $existing->update($attributes);
            $this->info("Updated {$role} login: {$email}");

            return self::SUCCESS;
        }

        User::create($attributes + ['email' => $email]);
        $this->info("Created {$role} login: {$email}" . ($branchId ? " (branch: {$this->option('branch')})" : ''));

        return self::SUCCESS;
    }
}
