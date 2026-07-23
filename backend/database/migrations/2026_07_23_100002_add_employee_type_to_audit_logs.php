<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE audit_logs MODIFY COLUMN type ENUM('attendance','13th_month','employee') NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_type_check');
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_type_check CHECK (type::text = ANY (ARRAY['attendance','13th_month','employee']::text[]))");
        }
        // sqlite and others: enum is emulated as text; no constraint change needed.
    }

    public function down(): void {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE audit_logs MODIFY COLUMN type ENUM('attendance','13th_month') NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_type_check');
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_type_check CHECK (type::text = ANY (ARRAY['attendance','13th_month']::text[]))");
        }
    }
};
