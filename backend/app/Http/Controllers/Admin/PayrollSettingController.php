<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayrollSetting;
use Illuminate\Http\Request;

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
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'release_date' => ['required', 'date'],
            'minimum_months' => ['required', 'integer', 'min:0', 'max:12'],
            'included_earnings' => ['required', 'array'],
            'included_earnings.*' => ['string'],
            'employment_types_included' => ['required', 'array'],
            'employment_types_included.*' => ['string'],
        ]);

        $settings = PayrollSetting::current();
        $settings->update($data);

        return response()->json($settings);
    }
}
