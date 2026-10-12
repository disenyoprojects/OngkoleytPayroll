<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('pdf.partials.payslip-styles')
</head>
<body>
@foreach ($slips as $payslip)
    @include('pdf.partials.payslip-pair', ['payslip' => $payslip])
@endforeach
</body>
</html>
