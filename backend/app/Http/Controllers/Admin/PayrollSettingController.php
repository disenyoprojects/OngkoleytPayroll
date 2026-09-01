<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayrollSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollSettingController extends Controller {
    public function show() {
        return response()->json(PayrollSetting::current());
    }

    public function update(Request $request) {
        $data = $request->validate([
            'daily_basic_rate' => ['required', 'numeric', 'min:0'],
            'standard_working_days_per_month' => ['required', 'integer', 'min:1', 'max:31'],
            'overtime_multiplier' => ['required', 'numeric', 'min:1'],
            'night_diff_multiplier' => ['required', 'numeric', 'min:0'],
            'unpaid_break_hours' => ['required', 'numeric', 'min:0'],
            'minimum_overtime_minutes' => ['sometimes', 'integer', 'min:0', 'max:480'],
            // What the Generate Penalty Lates button charges per late day. It is
            // not applied to pay on its own — it writes an adjustment the office
            // can still excuse or change.
            'late_penalty_amount' => ['sometimes', 'numeric', 'min:0'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'release_date' => ['required', 'date'],
            'minimum_months' => ['required', 'integer', 'min:0', 'max:12'],
            'included_earnings' => ['required', 'array'],
            'included_earnings.*' => ['string', Rule::in(['BASIC', 'OVERTIME', 'NIGHT_DIFF', 'HOLIDAY_PREMIUM', 'ALLOWANCE', 'BONUS', 'INCENTIVE', 'COMMISSION', 'LEAVE_CONVERSION'])],
            'employment_types_included' => ['required', 'array'],
            'employment_types_included.*' => ['string', Rule::in(['regular', 'probationary', 'fixed_term', 'seasonal'])],
        ]);

        $settings = PayrollSetting::current();
        $settings->update($data);

        return response()->json($settings);
    }
}
