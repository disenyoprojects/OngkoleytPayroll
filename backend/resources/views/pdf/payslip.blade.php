<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('pdf.partials.payslip-styles')
</head>
<body>
@include('pdf.partials.payslip-pair', ['payslip' => $payslip])
</body>
</html>
