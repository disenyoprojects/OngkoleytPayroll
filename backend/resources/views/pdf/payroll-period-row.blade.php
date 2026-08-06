<tr>
    <td>{{ $row['name'] }}@if ($row['separated'])<span class="sep"> (sep.)</span>@endif</td>
    <td class="r">{{ $row['days'] }}</td>
    <td class="r">{{ number_format($row['basic'], 2) }}</td>
    <td class="r">{{ $row['ot'] ? number_format($row['ot'], 2) : '—' }}</td>
    <td class="r">{{ number_format($row['gross'], 2) }}</td>
    <td class="r neg">{{ $row['late_penalty'] ? '−'.number_format($row['late_penalty'], 2) : '—' }}</td>
    <td class="r {{ $row['adjustments'] < 0 ? 'neg' : ($row['adjustments'] > 0 ? 'pos' : '') }}">{{ $row['adjustments'] ? ($row['adjustments'] < 0 ? '−' : '+').number_format(abs($row['adjustments']), 2) : '—' }}</td>
    <td class="r">{{ number_format($row['total_salary'], 2) }}</td>
    <td class="r">{{ number_format($row['net_to_release'], 2) }}</td>
</tr>
