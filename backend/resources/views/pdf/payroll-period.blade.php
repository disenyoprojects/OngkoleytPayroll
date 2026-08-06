<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@php
    $groups = $isAdmin ? $register['rows']->groupBy(fn ($r) => $r['branch'] ?? 'No branch') : null;
@endphp
<style>
    @page { margin: 12mm 9mm; }
    body { font-family: DejaVu Sans, sans-serif; color: #221A13; font-size: 9.5px; }
    h1 { font-size: 15px; margin: 0 0 2px; }
    .kicker { font-size: 9px; text-transform: uppercase; letter-spacing: .08em; font-weight: bold; color: #93731C; margin-bottom: 3px; }
    .muted { color: #7A6A57; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
    th, td { border: 1px solid #E7DCC6; padding: 4px 6px; overflow: hidden; }
    th { background: #FAF6EC; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .03em; }
    td.r, th.r { text-align: right; }
    tr.branch-head td { background: #2E2118; color: #FAF6EC; font-weight: bold; font-size: 9.5px; padding: 5px 6px; }
    tr.subtotal td { background: #F3EAD3; font-weight: bold; }
    tr.total td { background: #F3EAD3; font-weight: bold; border-top: 2px solid #2E2118; border-bottom: 2px solid #2E2118; }
    .neg { color: #C1521F; }
    .pos { color: #3B7A57; }
    .sep { color: #7A6A57; font-style: italic; font-size: 8px; }
    col.staff { width: 16%; } col.days { width: 6%; }
    col.money { width: 11%; }
</style>
</head>
<body>
    <div class="kicker">{{ $isAdmin ? 'Owner copy — company-wide' : 'Manager copy — branch only' }}</div>
    <h1>{{ $isAdmin ? 'Company-Wide Payroll Register' : 'Branch Payroll Register — '.($branchName ?? 'Branch') }}</h1>
    <div class="muted">{{ $register['period']['label'] }} · {{ $register['period']['from'] }} to {{ $register['period']['to'] }}</div>

    <table>
        <colgroup>
            <col class="staff"><col class="days">
            <col class="money"><col class="money"><col class="money"><col class="money">
            <col class="money"><col class="money"><col class="money">
        </colgroup>
        <thead>
            <tr>
                <th>Staff</th><th class="r">Days</th>
                <th class="r">Basic</th><th class="r">OT</th><th class="r">Gross</th>
                <th class="r">Late</th><th class="r">Allow./Ded.</th>
                <th class="r">Total Salary</th><th class="r">Net to Release</th>
            </tr>
        </thead>
        <tbody>
            @if (count($register['rows']) === 0)
                <tr><td colspan="9" class="muted" style="text-align:center;">No payroll for this period.</td></tr>
            @elseif ($isAdmin)
                @foreach ($groups as $branchLabel => $branchRows)
                    <tr class="branch-head"><td colspan="9">{{ $branchLabel }}</td></tr>
                    @foreach ($branchRows as $row)
                        @include('pdf.payroll-period-row', ['row' => $row])
                    @endforeach
                    <tr class="subtotal">
                        <td>{{ $branchLabel }} subtotal</td>
                        <td class="r">{{ $branchRows->sum('days') }}</td>
                        <td class="r">{{ number_format($branchRows->sum('basic'), 2) }}</td>
                        <td class="r">{{ number_format($branchRows->sum('ot'), 2) }}</td>
                        <td class="r">{{ number_format($branchRows->sum('gross'), 2) }}</td>
                        <td class="r">−{{ number_format($branchRows->sum('late_penalty'), 2) }}</td>
                        <td class="r">{{ number_format($branchRows->sum('adjustments'), 2) }}</td>
                        <td class="r">{{ number_format($branchRows->sum('total_salary'), 2) }}</td>
                        <td class="r">{{ number_format($branchRows->sum('net_to_release'), 2) }}</td>
                    </tr>
                @endforeach
            @else
                @foreach ($register['rows'] as $row)
                    @include('pdf.payroll-period-row', ['row' => $row])
                @endforeach
            @endif
        </tbody>
        @if (count($register['rows']) && $isAdmin)
            <tfoot>
                <tr class="total">
                    <td>COMPANY-WIDE TOTAL</td>
                    <td class="r">{{ $register['totals']['days'] }}</td>
                    <td class="r">{{ number_format($register['totals']['basic'], 2) }}</td>
                    <td class="r">{{ number_format($register['totals']['ot'], 2) }}</td>
                    <td class="r">{{ number_format($register['totals']['gross'], 2) }}</td>
                    <td class="r">−{{ number_format($register['totals']['late_penalty'], 2) }}</td>
                    <td class="r">{{ number_format($register['totals']['adjustments'], 2) }}</td>
                    <td class="r">{{ number_format($register['totals']['total_salary'], 2) }}</td>
                    <td class="r">₱{{ number_format($register['totals']['net_to_release'], 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <p class="muted" style="margin-top: 10px;">
        "Allow./Ded." nets paid allowances and cash-advance deductions. Net to Release excludes amounts already paid in cash. Excludes statutory deductions (SSS / PhilHealth / Pag-IBIG / tax).
        @unless ($isAdmin)
            Company-wide totals across all branches are visible to the business owner only.
        @endunless
    </p>
</body>
</html>
