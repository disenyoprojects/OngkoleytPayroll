<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (Schema::hasColumn('employees', 'pin_hash')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('pin_hash');
            });
        }
    }

    public function down(): void {
        if (! Schema::hasColumn('employees', 'pin_hash')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('pin_hash')->default('');
            });
        }
    }
};
