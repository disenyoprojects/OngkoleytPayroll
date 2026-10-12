{{-- One employee's payslip printed twice on one page — the copy handed to the
     employee and the copy the office keeps signed — so nobody has to print it
     a second time. The heading is the employee's own branch's business: a
     bulk print spans branches, and Kanto Cravings staff are not headed as
     Ongkoleyt. --}}
@php
    $from = \Carbon\Carbon::parse($payslip['period']['from']);
    $to = \Carbon\Carbon::parse($payslip['period']['to']);
    $periodText = $from->format('F j') . ' to ' . $to->format('j') . ', ' . $to->format('Y');

    $slip = $payslip['slip'];
    $earnings = $slip['earnings'];
    $deductions = $slip['deductions'];
    $rowCount = max(count($earnings), count($deductions));
@endphp
<div class="pair">
@foreach (["EMPLOYEE'S COPY", "EMPLOYER'S COPY"] as $copy)
    @if (! $loop->first)
        <div class="cut"></div>
    @endif
    <div class="sheet">
        <div class="band-name">{{ $payslip['company']['name'] }}</div>
        <div class="band-addr">{{ $payslip['company']['address'] }}</div>
        <div class="copy">{{ $copy }}</div>
        <div class="title">PAY SLIP</div>

        <table class="meta">
            <tr>
                <td class="lbl" style="width:16%">EMPLOYEE:</td>
                <td class="lbl" style="width:34%">{{ $payslip['employee']['full_name'] }}</td>
                <td class="lbl" style="width:22%; text-align:right;">PAY PERIOD:</td>
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
@endforeach
</div>
