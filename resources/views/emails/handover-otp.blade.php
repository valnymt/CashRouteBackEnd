<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CashRoute Handover Verification</title>
</head>
<body>
    <h2>CashRoute Handover Verification</h2>

    <p>Your verification code for the
        {{ ucfirst($stage) }} handover is:
    </p>

    <h1>{{ $code }}</h1>

    <p>This code expires in {{ $expiresInMinutes }} minutes.</p>

    <p>If you did not request this code, please contact your bank.</p>
</body>
</html>
