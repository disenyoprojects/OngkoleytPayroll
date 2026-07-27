<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('separation_type')->nullable()->after('resignation_date');
            $table->string('separation_reason')->nullable()->after('separation_type');
            $table->softDeletes();
        });
    }

    public function down(): void {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['separation_type', 'separation_reason']);
        });
    }
};
