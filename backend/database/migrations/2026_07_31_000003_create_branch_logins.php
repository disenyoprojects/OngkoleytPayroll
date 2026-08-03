<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Bootstrap a branch-scoped login for each branch on the live site.
     *
     * Production-only and create-only: never runs in tests, never overwrites a
     * password that was later changed. Plaintext is not stored — only bcrypt
     * hashes. Reset any of these later with:
     *   php artisan admin:create --role=branch --branch="Mabini" --email=... --password=... --force
     *
     * Credentials (change the password after first login):
     *   Mabini              mabini@ongkoleyt.ph        Mabini@2026
     *   Admin Office        adminoffice@ongkoleyt.ph   AdminOffice@2026
     *   Diego               diego@ongkoleyt.ph         Diego@2026
     *   Bonifacio           bonifacio@ongkoleyt.ph     Bonifacio@2026
     *   Kanto Craving/Brew  kanto@ongkoleyt.ph         Kanto@2026
     *   Bodega              bodega@ongkoleyt.ph        Bodega@2026
     */
    public function up(): void {
        if (! app()->environment('production')) {
            return;
        }

        $logins = [
            ['Mabini', 'Mabini', 'mabini@ongkoleyt.ph', '$2y$10$owFD.qTQJulymfMF8y/LX.s8GuZHiThy0AXiCY9VN5Nl5UhgVwU4y'],
            ['Admin Office', 'Admin Office', 'adminoffice@ongkoleyt.ph', '$2y$10$xaxZlVrBGn3AdyebWnfB.eg/LyI4P9cZql8UPFnGNGuelOpMgiB52'],
            ['Diego', 'Diego', 'diego@ongkoleyt.ph', '$2y$10$dr4k2dlvOmBPa2VX/YNkReaOcMqoHfdPk7gYwdhNfdrAc2IBjfLRy'],
            ['Bonifacio', 'Bonifacio', 'bonifacio@ongkoleyt.ph', '$2y$10$ztFoWoAJr0IBXzfS0J0pG.5zTd/K6IGoKMwRti.TN0/995Y7SZePa'],
            ['Kanto', 'Kanto Craving/Brew', 'kanto@ongkoleyt.ph', '$2y$10$qHH89m3qLeS2hq5Qh6TWT.j/OhCr.jcOvoYaUNsFJcDOO7ROi400K'],
            ['Bodega', 'Bodega', 'bodega@ongkoleyt.ph', '$2y$10$cgByllIU.nnkoap3XQozFeCiZHoWh4rkXXkyPFgD0lZbpT4kye0Ru'],
        ];

        foreach ($logins as [$name, $branchName, $email, $hash]) {
            if (User::where('email', $email)->exists()) {
                continue;
            }
            $branch = Branch::where('name', $branchName)->first();
            if (! $branch) {
                continue; // branch not seeded yet — skip rather than fail the deploy
            }
            User::create([
                'name' => $name . ' Branch',
                'email' => $email,
                'password' => $hash,
                'role' => 'branch',
                'branch_id' => $branch->id,
            ]);
        }
    }

    public function down(): void {
        // Login bootstrap; nothing to roll back.
    }
};
