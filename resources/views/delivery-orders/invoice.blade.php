<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
@php
    $isProforma = ($type ?? 'invoice') === 'proforma';
    $docTitle   = $isProforma ? 'Proforma Invoice' : 'Invoice';
    $docNo      = ($isProforma ? 'PI-' : 'INV-') . $order->order_no;
@endphp
<title>{{ $docTitle }} – {{ $order->order_no }}</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI', Arial, sans-serif; font-size:12px; color:#1a1a1a; background:#f0f0f0; }

.page { width:210mm; min-height:297mm; margin:0 auto; background:#fff; padding:16mm 16mm 20mm; position:relative; }

/* Watermark for proforma */
.watermark {
    position:absolute; top:50%; left:50%; transform:translate(-50%,-50%) rotate(-30deg);
    font-size:120px; font-weight:900; color:rgba(0,0,0,.04); letter-spacing:8px;
    white-space:nowrap; pointer-events:none; z-index:0;
}
.page > * { position:relative; z-index:1; }

/* Header */
.inv-head { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #1a73e8; padding-bottom:14px; margin-bottom:6px; }
.store-block { display:flex; gap:14px; align-items:flex-start; max-width:60%; }
.store-logo { max-height:64px; max-width:150px; object-fit:contain; }
.store-name { font-size:20px; font-weight:800; color:#1a73e8; line-height:1.2; }
.store-meta { font-size:11px; color:#555; margin-top:4px; line-height:1.5; }
.doc-block { text-align:right; }
.doc-title { font-size:30px; font-weight:800; letter-spacing:1px; color:#1a73e8; text-transform:uppercase; }
.doc-sub   { font-size:11px; color:#888; margin-top:2px; }

/* Meta grid */
.meta-grid { display:flex; justify-content:space-between; gap:24px; margin:18px 0; }
.meta-box { flex:1; }
.meta-box .lbl { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#999; font-weight:700; margin-bottom:4px; }
.meta-box .cust-name { font-size:14px; font-weight:700; }
.meta-box .val { font-size:12px; color:#333; line-height:1.5; }
.info-table { width:100%; font-size:12px; }
.info-table td { padding:2px 0; }
.info-table td:first-child { color:#999; width:120px; }
.info-table td:last-child { text-align:right; font-weight:600; }

/* Items */
table.items { width:100%; border-collapse:collapse; margin-top:8px; }
table.items thead th { background:#1a73e8; color:#fff; font-size:11px; text-transform:uppercase; letter-spacing:.4px; padding:9px 10px; text-align:left; }
table.items thead th.r { text-align:right; }
table.items thead th.c { text-align:center; }
table.items tbody td { padding:9px 10px; border-bottom:1px solid #eee; font-size:12px; vertical-align:top; }
table.items tbody td.r { text-align:right; }
table.items tbody td.c { text-align:center; }
table.items tbody tr:nth-child(even) { background:#fafafa; }
.prod-name { font-weight:600; }
.prod-sku { font-size:10px; color:#999; }

/* Totals */
.totals { display:flex; justify-content:flex-end; margin-top:14px; }
.totals table { width:300px; font-size:13px; }
.totals td { padding:5px 0; }
.totals td:last-child { text-align:right; font-weight:600; }
.totals tr.grand td { border-top:2px solid #1a1a1a; padding-top:8px; font-size:16px; font-weight:800; }
.totals tr.grand td:last-child { color:#1a73e8; }
.totals tr.paid td:last-child { color:#188038; }
.totals tr.due  td:last-child { color:#c5221f; }

.terbilang { margin-top:12px; font-size:12px; font-style:italic; color:#444; background:#f6f8fc; border-left:3px solid #1a73e8; padding:8px 12px; }

.paystatus { margin-top:14px; display:inline-block; padding:6px 16px; border-radius:6px; font-weight:800; font-size:13px; letter-spacing:.5px; }
.paystatus.lunas  { background:#e6f4ea; color:#188038; }
.paystatus.belum  { background:#fce8e6; color:#c5221f; }
.paystatus.parsial{ background:#fef7e0; color:#b06000; }

.pay-history { margin-top:16px; }
.pay-history .lbl { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#999; font-weight:700; margin-bottom:6px; }
.pay-history table { width:100%; border-collapse:collapse; font-size:11px; }
.pay-history td, .pay-history th { padding:5px 8px; border-bottom:1px solid #eee; text-align:left; }
.pay-history th { color:#999; font-weight:700; text-transform:uppercase; font-size:10px; }
.pay-history td.r { text-align:right; }

.proforma-note { margin-top:16px; font-size:11px; color:#b06000; background:#fef7e0; border:1px solid #fce8b2; border-radius:6px; padding:10px 14px; }

/* Signature */
.sign-area { display:flex; justify-content:space-between; margin-top:40px; }
.sign-box { text-align:center; width:200px; }
.sign-box .role { font-size:12px; color:#555; margin-bottom:56px; }
.sign-box .line { border-top:1px solid #333; padding-top:4px; font-size:12px; font-weight:600; }

.foot-note { margin-top:26px; padding-top:12px; border-top:1px solid #eee; font-size:11px; color:#888; text-align:center; }

@media print {
    body { background:#fff; }
    .no-print { display:none !important; }
    .page { width:auto; min-height:auto; margin:0; padding:12mm; box-shadow:none; }
    @page { size:A4; margin:0; }
}
@media screen {
    .page { box-shadow:0 2px 12px rgba(0,0,0,.15); margin:20px auto; }
}
</style>
</head>
<body>

<div class="no-print" style="text-align:center;padding:16px">
    <button onclick="window.print()" style="padding:9px 26px;font-size:14px;cursor:pointer;background:#1a73e8;color:#fff;border:none;border-radius:6px;margin-right:8px">
        🖨️ Print {{ $docTitle }}
    </button>
    <button onclick="window.close()" style="padding:9px 18px;font-size:14px;cursor:pointer;background:#fff;border:1px solid #ccc;border-radius:6px">
        Tutup
    </button>
</div>

@php
    // Terbilang (angka → kata) untuk nilai rupiah
    if (!function_exists('do_terbilang')) {
        function do_terbilang($n) {
            $n = (int) abs($n);
            $h = ['','satu','dua','tiga','empat','lima','enam','tujuh','delapan','sembilan','sepuluh','sebelas'];
            if ($n < 12)          return $h[$n];
            if ($n < 20)          return do_terbilang($n - 10) . ' belas';
            if ($n < 100)         return do_terbilang(intdiv($n,10)) . ' puluh ' . $h[$n%10];
            if ($n < 200)         return 'seratus ' . do_terbilang($n - 100);
            if ($n < 1000)        return $h[intdiv($n,100)] . ' ratus ' . do_terbilang($n%100);
            if ($n < 2000)        return 'seribu ' . do_terbilang($n - 1000);
            if ($n < 1000000)     return do_terbilang(intdiv($n,1000)) . ' ribu ' . do_terbilang($n%1000);
            if ($n < 1000000000)  return do_terbilang(intdiv($n,1000000)) . ' juta ' . do_terbilang($n%1000000);
            if ($n < 1000000000000) return do_terbilang(intdiv($n,1000000000)) . ' miliar ' . do_terbilang($n%1000000000);
            return do_terbilang(intdiv($n,1000000000000)) . ' triliun ' . do_terbilang($n%1000000000000);
        }
    }
    $terbilang = trim(preg_replace('/\s+/', ' ', do_terbilang($order->total)));
    $terbilang = $terbilang === '' ? 'nol' : $terbilang;

    $custAddr = $order->billing_address ?: $order->customer?->address;
    $rp = fn($v) => 'Rp ' . number_format($v, 0, ',', '.');
@endphp

<div class="page">
    @if($isProforma)
    <div class="watermark">PROFORMA</div>
    @endif

    {{-- Header --}}
    <div class="inv-head">
        <div class="store-block">
            @if(!empty($store['store_logo']))
            <img class="store-logo" src="{{ asset($store['store_logo']) }}" alt="Logo">
            @endif
            <div>
                <div class="store-name">{{ $store['store_name'] ?: 'Toko' }}</div>
                <div class="store-meta">
                    @if(!empty($store['store_address'])){{ $store['store_address'] }}<br>@endif
                    @if(!empty($store['store_phone']))Telp: {{ $store['store_phone'] }}@endif
                    @if(!empty($store['store_email'])) · {{ $store['store_email'] }}@endif
                </div>
            </div>
        </div>
        <div class="doc-block">
            <div class="doc-title">{{ $docTitle }}</div>
            <div class="doc-sub">{{ $docNo }}</div>
        </div>
    </div>

    {{-- Bill-to + info --}}
    <div class="meta-grid">
        <div class="meta-box">
            <div class="lbl">Ditagihkan Kepada</div>
            <div class="cust-name">{{ $order->customer?->name ?? '-' }}</div>
            <div class="val">
                @if($order->customer?->phone){{ $order->customer->phone }}<br>@endif
                @if($custAddr){!! nl2br(e($custAddr)) !!}@endif
            </div>
        </div>
        <div class="meta-box">
            <table class="info-table">
                <tr><td>No. Dokumen</td><td>{{ $docNo }}</td></tr>
                <tr><td>Tanggal</td><td>{{ ($order->order_date ?? $order->created_at)->isoFormat('D MMM Y') }}</td></tr>
                <tr><td>No. Order</td><td>{{ $order->order_no }}</td></tr>
                @if($order->delivery_date)
                <tr><td>Tgl Kirim</td><td>{{ $order->delivery_date->isoFormat('D MMM Y') }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    {{-- Items --}}
    <table class="items">
        <thead>
            <tr>
                <th class="c" style="width:36px">No</th>
                <th>Deskripsi</th>
                <th class="r" style="width:70px">Qty</th>
                <th class="r" style="width:120px">Harga</th>
                <th class="r" style="width:130px">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $i => $item)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>
                    <div class="prod-name">{{ $item->product_name }}</div>
                    @if($item->product_sku)<div class="prod-sku">{{ $item->product_sku }}</div>@endif
                </td>
                <td class="r">{{ rtrim(rtrim(number_format($item->qty, 2, ',', '.'), '0'), ',') }}</td>
                <td class="r">{{ $rp($item->price) }}</td>
                <td class="r">{{ $rp($item->subtotal) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totals --}}
    <div class="totals">
        <table>
            <tr><td>Subtotal</td><td>{{ $rp($order->subtotal ?? $order->total) }}</td></tr>
            <tr class="grand"><td>TOTAL</td><td>{{ $rp($order->total) }}</td></tr>
            @unless($isProforma)
            <tr class="paid"><td>Dibayar</td><td>{{ $rp($totalPaid) }}</td></tr>
            <tr class="due"><td>Sisa Tagihan</td><td>{{ $rp($outstanding) }}</td></tr>
            @endunless
        </table>
    </div>

    <div class="terbilang">
        <strong>Terbilang:</strong> {{ ucfirst($terbilang) }} rupiah
    </div>

    @if($isProforma)
        <div class="proforma-note">
            <strong>Catatan:</strong> Dokumen ini merupakan <em>Proforma Invoice</em> (estimasi tagihan) dan
            <strong>bukan bukti pembayaran</strong> yang sah. Invoice resmi akan diterbitkan setelah order dikonfirmasi.
        </div>
    @else
        @php
            $ps = $order->payment_status;
            $psClass = $ps === 'paid' ? 'lunas' : ($ps === 'partial' ? 'parsial' : 'belum');
            $psLabel = $ps === 'paid' ? 'LUNAS' : ($ps === 'partial' ? 'DP / SEBAGIAN' : 'BELUM LUNAS');
        @endphp
        <div class="paystatus {{ $psClass }}">{{ $psLabel }}</div>

        @if($order->payments->count())
        <div class="pay-history">
            <div class="lbl">Riwayat Pembayaran</div>
            <table>
                <thead>
                    <tr><th>Tanggal</th><th>Metode</th><th>Referensi</th><th class="r">Jumlah</th></tr>
                </thead>
                <tbody>
                    @foreach($order->payments as $pmt)
                    <tr>
                        <td>{{ optional($pmt->payment_date)->isoFormat('D MMM Y') ?? '-' }}</td>
                        <td>{{ $pmt->methodLabel() }}</td>
                        <td>{{ $pmt->reference_no ?: '-' }}</td>
                        <td class="r">{{ $rp($pmt->amount) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    @endif

    {{-- Signature --}}
    <div class="sign-area">
        <div class="sign-box">
            <div class="role">Hormat kami,</div>
            <div class="line">{{ $store['store_name'] ?: 'Penjual' }}</div>
        </div>
        <div class="sign-box">
            <div class="role">Penerima,</div>
            <div class="line">{{ $order->customer?->name ?? '(..................)' }}</div>
        </div>
    </div>

    <div class="foot-note">
        {{ $store['receipt_footer'] ?? 'Terima kasih atas kepercayaan Anda.' }}<br>
        Dicetak {{ $printAt->isoFormat('D MMM Y HH:mm') }}
    </div>
</div>

</body>
</html>
