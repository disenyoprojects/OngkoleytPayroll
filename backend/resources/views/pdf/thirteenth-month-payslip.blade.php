<html>
<head>
<style>
body { font-family: sans-serif; font-size: 12px; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; }
.total { font-weight: bold; }
</style>
</head>
<body>
<h2>13th Month Pay Payslip — {{ $record->payroll_year }}</h2>
<p><strong>{{ $employee->full_name }}</strong> — {{ $employee->role }} — {{ $employee->branch->name }}</p>
<table>
<thead><tr><th>Month</th><th>Basic Pay</th><th>OT Pay</th><th>Other</th><th>Month Total</th></tr></thead>
<tbody>
@foreach ($breakdown as $row)
<tr>
<td>{{ \Carbon\Carbon::create()->month($row['month'])->format('M') }}</td>
<td>{{ $row['worked'] ? number_format($row['basic_pay'], 2) : '—' }}</td>
<td>{{ $row['worked'] ? number_format($row['ot_pay'], 2) : '—' }}</td>
<td>{{ $row['worked'] ? number_format($row['other_pay'], 2) : '—' }}</td>
<td>{{ $row['worked'] ? number_format($row['month_total_included'], 2) : '—' }}</td>
</tr>
@endforeach
</tbody>
</table>
<p>Computed Amount: {{ number_format($record->computed_amount, 2) }}</p>
<p>Manual Adjustment: {{ number_format($record->manual_adjustment, 2) }}</p>
<p class="total">13th Month Amount: {{ number_format($record->adjusted_amount, 2) }}</p>
<p>Status: {{ $record->status }}</p>
</body>
</html>
