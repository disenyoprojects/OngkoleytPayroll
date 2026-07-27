<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('employees', function (Blueprint $table) {
            $table->time('shift_start')->default('08:00:00')->after('employment_type');
            $table->time('shift_end')->default('17:00:00')->after('shift_start');
        });
    }

    public function down(): void {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['shift_start', 'shift_end']);
        });
    }
};
