<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('employee_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->enum('code', [
                'HOLIDAY_PREMIUM', 'ALLOWANCE', 'BONUS', 'INCENTIVE', 'COMMISSION', 'LEAVE_CONVERSION',
            ]);
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('employee_earnings');
    }
};
