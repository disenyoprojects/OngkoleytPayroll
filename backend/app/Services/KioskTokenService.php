<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

class KioskTokenService {
    private const TTL_MINUTES = 10;

    public function issue(Employee $employee): string {
        return Crypt::encryptString(json_encode([
            'employee_id' => $employee->id,
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES)->timestamp,
        ]));
    }

    public function resolve(string $token): ?Employee {
        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload) || Carbon::now()->timestamp > ($payload['expires_at'] ?? 0)) {
            return null;
        }

        return Employee::find($payload['employee_id'] ?? null);
    }
}
