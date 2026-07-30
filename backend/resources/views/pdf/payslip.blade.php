<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; color: #221A13; font-size: 12px; }
    h1 { font-size: 18px; margin: 0 0 2px; }
    .muted { color: #7A6A57; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    th, td { border: 1px solid #E7DCC6; padding: 6px 8px; text-align: left; }
    th { background: #FAF6EC; }
    .right { text-align: right; }
    .totals td { border: none; padding: 3px 8px; }
    .gross { font-size: 15px; font-weight: bold; }
</style>
</head>
<body>
    <h1>Payslip</h1>
    <div class="muted">
        {{ $payslip['employee']['full_name'] }} · {{ $payslip['employee']['role'] }}
        · {{ $payslip['employee']['branch'] ?? '—' }}
    </div>
    <div class="muted">Period: {{ $payslip['period']['label'] }} ({{ $payslip['period']['from'] }} to {{ $payslip['period']['to'] }})</div>
    <div class="muted">Daily rate: ₱{{ number_format($payslip['employee']['daily_rate'], 2) }}</div>

    <table>
        <thead>
            <tr><th>Date</th><th>Shift</th><th>In</th><th>Out</th><th class="right">Hours</th><th>Type</th><th class="right">Day Pay</th></tr>
        </thead>
        <tbody>
            @forelse ($payslip['lines'] as $line)
                <tr>
                    <td>{{ $line['date'] }}</td>
                    <td>{{ $line['shift_start'] ? substr($line['shift_start'], 0, 5) : '—' }}–{{ $line['shift_end'] ? substr($line['shift_end'], 0, 5) : '—' }}</td>
                    <td>{{ $line['clock_in'] ? substr($line['clock_in'], 0, 5) : '—' }}</td>
                    <td>{{ $line['clock_out'] ? substr($line['clock_out'], 0, 5) : '—' }}</td>
                    <td class="right">{{ number_format($line['hours'], 2) }}</td>
                    <td>{{ $line['premium_label'] }}@if ($line['late']) · Late {{ $line['late_minutes'] }}m @endif</td>
                    <td class="right">₱{{ number_format($line['day_pay'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">No worked days in this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if (count($payslip['adjustments']))
        <table style="margin-top: 12px;">
            <thead><tr><th>Adjustment</th><th>Date</th><th>Status</th><th class="right">Amount</th></tr></thead>
            <tbody>
                @foreach ($payslip['adjustments'] as $adj)
                    <tr>
                        <td>{{ $adj['label'] }}</td>
                        <td>{{ $adj['date'] }}</td>
                        <td>{{ $adj['paid'] ? 'Paid (Cash on Hand)' : 'To pay' }}</td>
                        <td class="right">{{ $adj['amount'] < 0 ? '−' : '' }}₱{{ number_format(abs($adj['amount']), 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals" style="width: 300px; margin-left: auto;">
        <tr><td>Basic</td><td class="right">₱{{ number_format($payslip['totals']['basic'], 2) }}</td></tr>
        <tr><td>Overtime</td><td class="right">₱{{ number_format($payslip['totals']['ot'], 2) }}</td></tr>
        <tr><td>Night Differential</td><td class="right">₱{{ number_format($payslip['totals']['night_diff'], 2) }}</td></tr>
        <tr><td>Gross Pay</td><td class="right">₱{{ number_format($payslip['totals']['gross'], 2) }}</td></tr>
        <tr><td>Late Penalty</td><td class="right">−₱{{ number_format($payslip['totals']['late_penalty'], 2) }}</td></tr>
        <tr><td>Adjustments</td><td class="right">{{ $payslip['totals']['adjustments'] < 0 ? '−' : '' }}₱{{ number_format(abs($payslip['totals']['adjustments']), 2) }}</td></tr>
        <tr class="gross"><td>Total Salary</td><td class="right">₱{{ number_format($payslip['totals']['total_salary'], 2) }}</td></tr>
        <tr><td>Less already paid (Cash on Hand)</td><td class="right">−₱{{ number_format($payslip['totals']['paid'], 2) }}</td></tr>
        <tr class="gross"><td>Net to Release</td><td class="right">₱{{ number_format($payslip['totals']['net_to_release'], 2) }}</td></tr>
    </table>
    <p class="muted" style="margin-top: 14px;">Total salary includes adjustments and is net of late penalties. "Net to Release" excludes amounts already paid in cash. Excludes statutory deductions (SSS / PhilHealth / Pag-IBIG / tax).</p>
</body>
</html>
