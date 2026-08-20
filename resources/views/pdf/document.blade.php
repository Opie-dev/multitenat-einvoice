<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $typeLabel }} {{ $document->id }}</title>
<style>
    @page { margin: 24px 32px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    .header-table td { padding-bottom: 12px; }
    .doc-title { font-size: 20px; font-weight: bold; }
    .issuer-name { font-size: 14px; font-weight: bold; }
    .muted { color: #555555; }
    .right { text-align: right; }
    .section-title { font-size: 9px; font-weight: bold; text-transform: uppercase; color: #555555; padding-bottom: 4px; }
    .party-table td { padding: 8px 0; border-top: 1px solid #cccccc; border-bottom: 1px solid #cccccc; }
    .party-table .party-cell { width: 50%; padding-right: 16px; }
    .lines-table { margin-top: 12px; }
    .lines-table th { background-color: #f0f0f0; padding: 6px 4px; text-align: left; font-size: 9px; text-transform: uppercase; border-bottom: 1px solid #999999; }
    .lines-table td { padding: 6px 4px; border-bottom: 1px solid #dddddd; }
    .totals-table { width: 45%; margin-left: 55%; margin-top: 12px; }
    .totals-table td { padding: 3px 4px; }
    .totals-table .grand-total td { border-top: 1px solid #333333; font-weight: bold; font-size: 12px; padding-top: 6px; }
    .meta-table td { padding: 1px 0; }
    .meta-label { color: #555555; width: 40%; }
    .footer-table { margin-top: 24px; }
    .qr-cell { width: 120px; text-align: center; }
    .qr-cell img { width: 100px; height: 100px; }
    .qr-caption { font-size: 7px; color: #777777; word-break: break-all; }
    .cancel-reason { color: #a30000; font-size: 9px; margin-top: 4px; }
    .watermark { position: fixed; top: 320px; left: 60px; width: 500px; transform: rotate(-30deg); font-size: 88px; font-weight: bold; color: #cc0000; opacity: 0.18; z-index: 10; }
</style>
</head>
<body>

@if ($watermarkText)
    <div class="watermark">{{ $watermarkText }}</div>
@endif

<table class="header-table">
    <tr>
        <td style="width: 60%;">
            <div class="issuer-name">{{ $issuer->name }}</div>
            <div class="muted">TIN: {{ $issuer->tin }}</div>
            <div class="muted">{{ $issuer->address_line1 }}</div>
            @if ($issuer->address_line2)
                <div class="muted">{{ $issuer->address_line2 }}</div>
            @endif
            <div class="muted">{{ $issuer->postcode }} {{ $issuer->city }}, {{ $issuer->state_code }}, {{ $issuer->country_code }}</div>
            <div class="muted">{{ $issuer->email }} &middot; {{ $issuer->phone }}</div>
        </td>
        <td style="width: 40%;" class="right">
            <div class="doc-title">{{ strtoupper($typeLabel) }}</div>
            <table class="meta-table">
                <tr><td class="meta-label">Document ID</td><td class="right">{{ $document->lhdn_internal_id ?? $document->id }}</td></tr>
                <tr><td class="meta-label">Issue Date</td><td class="right">{{ $document->issue_date->toDateString() }}</td></tr>
                <tr><td class="meta-label">Status</td><td class="right">{{ strtoupper($document->status->value) }}</td></tr>
                <tr><td class="meta-label">LHDN UUID</td><td class="right">{{ $document->lhdn_uuid ?? '-' }}</td></tr>
                <tr><td class="meta-label">LHDN Long ID</td><td class="right">{{ $document->lhdn_long_id ?? '-' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="party-table">
    <tr>
        <td class="party-cell">
            <div class="section-title">Supplier</div>
            {{ $issuer->name }}<br>
            TIN: {{ $issuer->tin }}
        </td>
        <td class="party-cell">
            <div class="section-title">Buyer</div>
            {{ $document->buyer_snapshot['name'] ?? 'N/A' }}<br>
            TIN: {{ $document->buyer_snapshot['tin'] ?? 'N/A' }}
            @if (! empty($document->buyer_snapshot['address_line1']))
                <br>{{ $document->buyer_snapshot['address_line1'] }}
            @endif
        </td>
    </tr>
</table>

<table class="lines-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Description</th>
            <th class="right">Qty</th>
            <th class="right">Unit Price</th>
            <th class="right">Discount</th>
            <th class="right">Tax</th>
            <th class="right">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>{{ $line->position }}</td>
                <td>{{ $line->description }}</td>
                <td class="right">{{ $line->quantity }} {{ $line->unit_code }}</td>
                <td class="right">{{ $document->currency }} {{ $line->unit_price }}</td>
                <td class="right">{{ $document->currency }} {{ $line->discount_amount }}</td>
                <td class="right">{{ $document->currency }} {{ $line->tax_amount }}</td>
                <td class="right">{{ $document->currency }} {{ $line->total }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals-table">
    <tr><td>Subtotal</td><td class="right">{{ $document->currency }} {{ $document->subtotal }}</td></tr>
    <tr><td>Discount</td><td class="right">{{ $document->currency }} {{ $document->discount_total }}</td></tr>
    <tr><td>Total excluding tax</td><td class="right">{{ $document->currency }} {{ $document->total_excluding_tax }}</td></tr>
    <tr><td>Tax</td><td class="right">{{ $document->currency }} {{ $document->tax_total }}</td></tr>
    <tr class="grand-total"><td>Total payable</td><td class="right">{{ $document->currency }} {{ $document->total_payable }}</td></tr>
</table>

@if ($watermarkText)
    <div class="cancel-reason">
        {{ $watermarkText }}@if ($document->cancel_reason): {{ $document->cancel_reason }}@endif
    </div>
@endif

<table class="footer-table">
    <tr>
        <td></td>
        <td class="qr-cell">
            @if ($qrDataUri)
                <img src="{{ $qrDataUri }}" alt="LHDN validation QR">
                <div class="qr-caption">{{ $validationUrl }}</div>
            @endif
        </td>
    </tr>
</table>

</body>
</html>
