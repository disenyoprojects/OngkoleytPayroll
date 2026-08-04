<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Reliable (re)creation of the branch-scoped logins.
     *
     * The first attempt skipped when a branch row didn't yet exist, and a
     * one-time migration never retries — so this one can't skip: it ensures the
     * branch exists (firstOrCreate) and force-sets each login (updateOrCreate).
     *
     * Guarded on the real roster (EMP-0001) instead of APP_ENV so it also runs
     * where APP_ENV isn't exactly "production", but stays a no-op in tests.
     */
    public function up(): void {
        if (! Employee::withTrashed()->where('employee_code', 'EMP-0001')->exists()) {
            return; // fresh/test database — no roster, nothing to bootstrap
        }

        $logins = [
            ['Mabini',       'Mabini',              'mabini@ongkoleyt.ph',      '$2y$10$owFD.qTQJulymfMF8y/LX.s8GuZHiThy0AXiCY9VN5Nl5UhgVwU4y'],
            ['Admin Office', 'Admin Office',        'adminoffice@ongkoleyt.ph', '$2y$10$xaxZlVrBGn3AdyebWnfB.eg/LyI4P9cZql8UPFnGNGuelOpMgiB52'],
            ['Diego',        'Diego',               'diego@ongkoleyt.ph',       '$2y$10$dr4k2dlvOmBPa2VX/YNkReaOcMqoHfdPk7gYwdhNfdrAc2IBjfLRy'],
            ['Bonifacio',    'Bonifacio',           'bonifacio@ongkoleyt.ph',   '$2y$10$ztFoWoAJr0IBXzfS0J0pG.5zTd/K6IGoKMwRti.TN0/995Y7SZePa'],
            ['Kanto',        'Kanto Craving/Brew',  'kanto@ongkoleyt.ph',       '$2y$10$qHH89m3qLeS2hq5Qh6TWT.j/OhCr.jcOvoYaUNsFJcDOO7ROi400K'],
            ['Bodega',       'Bodega',              'bodega@ongkoleyt.ph',      '$2y$10$cgByllIU.nnkoap3XQozFeCiZHoWh4rkXXkyPFgD0lZbpT4kye0Ru'],
        ];

        foreach ($logins as [$name, $branchName, $email, $hash]) {
            $branch = Branch::firstOrCreate(['name' => $branchName]);
            User::updateOrCreate(
                ['email' => $email],
                ['name' => $name . ' Branch', 'password' => $hash, 'role' => 'branch', 'branch_id' => $branch->id],
            );
        }
    }

    public function down(): void {
        // Login bootstrap; nothing to roll back.
    }
};
