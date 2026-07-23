<html>
<head>
<style>
body { font-family: sans-serif; font-size: 12px; }
table { width: 100%; border-collapse: collapse; }
th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
</style>
</head>
<body>
<h2>Ongkoleyt Payroll — {{ ucfirst($range) }} — {{ $date }}</h2>
<table>
<thead>
<tr><th>Staff</th><th>Role</th><th>Branch</th><th>Total Pay</th></tr>
</thead>
<tbody>
@foreach ($rows as $row)
<tr>
<td>{{ $row['employee']->short_name }}</td>
<td>{{ $row['employee']->role }}</td>
<td>{{ $row['employee']->branch->name }}</td>
<td>{{ number_format($row['pay']['total'], 2) }}</td>
</tr>
@endforeach
</tbody>
</table>
<p><strong>Total: {{ number_format($total, 2) }}</strong></p>
</body>
</html>
