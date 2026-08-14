<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A branch login can now cover several branches — the Mabini / Diego /
 * Bonifacio sites share one account, while Kanto and Bodega keep their own.
 * users.branch_id stays as the primary branch (it still drives the branch name
 * shown after login and the default branch for a new employee); this pivot
 * holds the full set a login may see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'user_id']);
        });

        // Existing single-branch logins carry on unchanged: seed the pivot from
        // the column they already have.
        DB::table('users')->whereNotNull('branch_id')->orderBy('id')
            ->each(function ($user) {
                DB::table('branch_user')->insertOrIgnore([
                    'branch_id' => $user->branch_id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
    }
};
