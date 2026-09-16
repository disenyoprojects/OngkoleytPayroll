<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model {
    use HasFactory;

    /**
     * What a payslip is headed with when the branch does not say otherwise.
     * Most branches are Ongkoleyt, so they inherit this and only the ones
     * trading under another name — Kanto Cravings — carry their own.
     */
    public const DEFAULT_COMPANY_NAME = 'WANG CHOCOLATE INC.';
    public const DEFAULT_COMPANY_ADDRESS = 'Upper Ground Floor, Olympian, Upper Mabini, Baguio City 2600';

    protected $fillable = ['name', 'company_name', 'company_address'];

    public function employees() {
        return $this->hasMany(Employee::class);
    }

    /**
     * The heading for a payslip of somebody at this branch.
     *
     * @return array{name: string, address: string}
     */
    public function payslipHeading(): array {
        return [
            'name' => $this->company_name ?: self::DEFAULT_COMPANY_NAME,
            'address' => $this->company_address ?: self::DEFAULT_COMPANY_ADDRESS,
        ];
    }

    /** The heading for an employee with no branch on record. */
    public static function defaultHeading(): array {
        return ['name' => self::DEFAULT_COMPANY_NAME, 'address' => self::DEFAULT_COMPANY_ADDRESS];
    }
}
