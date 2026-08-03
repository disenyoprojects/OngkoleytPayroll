<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@php
    // Legal entity shown on the payslip (change here if the company name changes).
    $companyName = 'WANG CHOCOLATE INC.';
    $companyAddress = 'Upper Ground Floor, Olympian, Upper Mabini, Baguio City 2600';

    $from = \Carbon\Carbon::parse($payslip['period']['from']);
    $to = \Carbon\Carbon::parse($payslip['period']['to']);
    $periodText = $from->format('F j') . ' to ' . $to->format('j') . ', ' . $to->format('Y');

    $slip = $payslip['slip'];
    $earnings = $slip['earnings'];
    $deductions = $slip['deductions'];
    $rowCount = max(count($earnings), count($deductions));
@endphp
<style>
    @page { margin: 22mm 20mm; }
    body { font-family: DejaVu Sans, sans-serif; color: #1c1c1c; font-size: 11px; }
    .sheet { border: 1px solid #3a3a3a; }
    .band-name { background: #6b3410; color: #fff; text-align: center; font-size: 17px; font-weight: bold; padding: 8px 0; letter-spacing: .5px; }
    .band-addr { background: #dfe8cf; color: #222; text-align: center; font-size: 10.5px; font-style: italic; padding: 5px 0; border-bottom: 1px solid #3a3a3a; }
    .title { text-align: center; font-size: 15px; font-weight: bold; padding: 8px 0 4px; letter-spacing: 1px; }
    .meta { width: 100%; border-collapse: collapse; }
    .meta td { padding: 2px 14px; font-size: 11px; }
    .meta .lbl { font-weight: bold; }
    .cols { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
    .cols th { background: #f2f2f2; border-top: 1px solid #3a3a3a; border-bottom: 1px solid #3a3a3a; padding: 5px 10px; font-size: 11.5px; }
    .cols th.l { text-align: center; }
    .cols td { padding: 3px 10px; font-size: 11px; }
    .cols .item { color: #1f3a6b; }
    .cols .amt { text-align: right; }
    .cols .mid { border-left: 1px solid #3a3a3a; }
    .totrow td { border-top: 1px solid #3a3a3a; border-bottom: 1px solid #3a3a3a; font-weight: bold; padding: 6px 10px; }
    .net { text-align: center; padding: 16px 0 6px; font-size: 13px; font-weight: bold; }
    .net .val { color: #C00000; margin-left: 30px; }
    .confirm { font-style: italic; color: #333; font-size: 10px; padding: 26px 14px 6px; }
    .sign { width: 100%; border-collapse: collapse; padding: 0 14px; }
    .sign td { padding: 26px 14px 6px; font-size: 10px; vertical-align: bottom; }
    .sign .line { border-top: 1px solid #555; padding-top: 4px; font-weight: bold; }
</style>
</head>
<body>
<div class="sheet">
    <div class="band-name">{{ $companyName }}</div>
    <div class="band-addr">{{ $companyAddress }}</div>
    <div class="title">PAY SLIP</div>

    <table class="meta">
        <tr>
            <td class="lbl" style="width:16%">EMPLOYEE:</td>
            <td class="lbl" style="width:40%">{{ $payslip['employee']['full_name'] }}</td>
            <td class="lbl" style="width:16%; text-align:right;">PAY PERIOD:</td>
            <td style="width:28%">{{ $periodText }}</td>
        </tr>
        <tr>
            <td></td><td></td>
            <td class="lbl" style="text-align:right;">DAYS WORKED</td>
            <td>{{ number_format($slip['days_worked'], 2) }}</td>
        </tr>
    </table>

    <table class="cols">
        <colgroup><col style="width:32%"><col style="width:18%"><col style="width:32%"><col style="width:18%"></colgroup>
        <thead>
            <tr>
                <th class="l">Earnings</th><th class="l">Amount</th>
                <th class="l mid">Deductions</th><th class="l">Amount</th>
            </tr>
        </thead>
        <tbody>
            @for ($i = 0; $i < $rowCount; $i++)
                <tr>
                    <td class="item">{{ $earnings[$i]['label'] ?? '' }}</td>
                    <td class="amt">@isset($earnings[$i])₱{{ number_format($earnings[$i]['amount'], 2) }}@endisset</td>
                    <td class="item mid">{{ $deductions[$i]['label'] ?? '' }}</td>
                    <td class="amt">@isset($deductions[$i])₱{{ number_format($deductions[$i]['amount'], 2) }}@endisset</td>
                </tr>
            @endfor
            <tr class="totrow">
                <td>Gross Earnings</td>
                <td class="amt">₱{{ number_format($slip['gross_earnings'], 2) }}</td>
                <td class="mid">Total Deductions</td>
                <td class="amt">₱{{ number_format($slip['total_deductions'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="net">Net Salary Received: <span class="val">₱{{ number_format($slip['net'], 2) }}</span></div>

    <div class="confirm">I hereby confirm that the above records are true and correct.</div>

    <table class="sign">
        <tr>
            <td style="width:55%"><div class="line">Employee's Printed Name &amp; Signature</div></td>
            <td style="width:45%"><div class="line">Date</div></td>
        </tr>
    </table>
</div>
</body>
</html>
