<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {--name= : Display name for the admin}
        {--email= : Login email (must be unique)}
        {--password= : Login password (min 8 chars)}
        {--force : Update the password if the email already exists}';

    protected $description = 'Create (or reset the password of) an admin login for the payroll system';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Admin name', 'Admin');
        $email = $this->option('email') ?: $this->ask('Admin email');
        $password = $this->option('password') ?: $this->secret('Admin password (min 8 chars)');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        if ($existing && ! $this->option('force')) {
            $this->error("An admin with email {$email} already exists. Re-run with --force to reset its password.");

            return self::FAILURE;
        }

        if ($existing) {
            $existing->update(['name' => $name, 'password' => Hash::make($password)]);
            $this->info("Password reset for admin: {$email}");

            return self::SUCCESS;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Admin created: {$email}");

        return self::SUCCESS;
    }
}
