<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Create a fresh admin login on the live site.
     *
     * Guarded to production so it never runs in tests, and create-only so it
     * never overwrites a password that was later changed. The plaintext is not
     * stored here — only an irreversible bcrypt hash. Reset later any time with:
     *   php artisan admin:create --email=admin@ongkoleyt.ph --password=NEW --force
     */
    public function up(): void {
        if (! app()->environment('production')) {
            return;
        }

        $email = 'admin@ongkoleyt.ph';
        if (User::where('email', $email)->exists()) {
            return;
        }

        User::create([
            'name' => 'Ongkoleyt Admin',
            'email' => $email,
            'password' => '$2y$10$toVjtPTFhiSyfzFd3toA7uHWAyNM86HsrhYYjVwOlo1NAM3Eire1e',
        ]);
    }

    public function down(): void {
        // Login bootstrap; nothing to roll back.
    }
};
