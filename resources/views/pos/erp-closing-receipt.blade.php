<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tutup Kasir {{ $doc['name'] }}</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    @php($paper = receipt_paper())
    @page { size: {{ $paper['page'] }} auto; margin: 0; }
    body { font-family:'Courier New', monospace; font-size:10px; line-height:1.15; color:#000; background:#fff; padding:0; }
    .receipt { width:{{ $paper['content'] }}; margin:0 auto; }
    h1 { font-size:12px; text-align:center; margin-bottom:1px; }
    .sub { text-align:center; font-size:9px; margin-bottom:4px; }
    hr { border:none; border-top:1px dashed #000; margin:6px 0; }
    .row { display:flex; justify-content:space-between; gap:8px; }
    .row .r { text-align:right; }
    .muted { color:#555; }
    .bold { font-weight:bold; }
    table { width:100%; border-collapse:collapse; font-size:9px; }
    th, td { text-align:right; padding:0; }
    th:first-child, td:first-child { text-align:left; }
    .center { text-align:center; }
    @media print { body { padding:0; } .receipt { width:{{ $paper['content'] }}; } .no-print { display:none; } }
</style>
</head>
<body onload="window.print()">
@php($fmt = fn ($v) => number_format((float) $v, 0, ',', '.'))
@php($jam = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->isoFormat('D/M/YY HH:mm') : '—')
<div class="receipt">
    <h1>LAPORAN TUTUP KASIR</h1>
    <div class="sub">{{ $doc['pos_profile'] ?? 'POS' }}</div>

    <hr>
    <div class="row"><span>Kasir</span><span class="r">{{ $doc['user'] ?? '—' }}</span></div>
    <div class="row"><span>Closing ERP</span><span class="r">{{ $doc['name'] }}</span></div>
    <div class="row"><span>Opening ERP</span><span class="r">{{ $doc['pos_opening_entry'] ?? '—' }}</span></div>
    <div class="row"><span>Buka</span><span class="r">{{ $jam($doc['period_start_date'] ?? null) }}</span></div>
    <div class="row"><span>Tutup</span><span class="r">{{ $jam($doc['period_end_date'] ?? null) }}</span></div>
    @if((int) ($doc['docstatus'] ?? 0) === 2)
    <div class="row bold"><span>STATUS</span><span class="r">DIBATALKAN</span></div>
    @elseif((int) ($doc['docstatus'] ?? 0) === 0)
    <div class="row bold"><span>STATUS</span><span class="r">DRAFT</span></div>
    @endif

    <hr>
    <div class="row bold"><span>Penjualan</span><span class="r">Rp {{ $fmt($doc['grand_total'] ?? 0) }}</span></div>
    <div class="row muted"><span>Jml transaksi</span><span class="r">{{ count($doc['pos_transactions'] ?? []) }}</span></div>
    <div class="row muted"><span>Total qty</span><span class="r">{{ $fmt($doc['total_quantity'] ?? 0) }}</span></div>
    <div class="row muted"><span>Modal awal (kas)</span><span class="r">Rp {{ $fmt($openingCash) }}</span></div>

    <hr>
    <div class="bold center">REKONSILIASI PER METODE</div>
    <table>
        <thead>
            <tr><th>Metode</th><th>Sistem</th><th>Hitung</th><th>Selisih</th></tr>
        </thead>
        <tbody>
        @forelse($rows as $r)
            <tr>
                <td>{{ $r['mode'] }}</td>
                <td>{{ $fmt($r['expected']) }}</td>
                <td>{{ $fmt($r['counted']) }}</td>
                <td>{{ $r['difference'] < 0 ? '-' : '' }}{{ $fmt(abs($r['difference'])) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="center muted">Tidak ada data rekonsiliasi.</td></tr>
        @endforelse
        </tbody>
    </table>

    <hr>
    <div class="row bold"><span>KAS SISTEM</span><span class="r">Rp {{ $fmt($cashExpected) }}</span></div>
    <div class="row bold"><span>KAS DIHITUNG</span><span class="r">Rp {{ $fmt($cashCounted) }}</span></div>
    <div class="row bold">
        <span>SELISIH KAS</span>
        <span class="r">{{ $cashDifference < 0 ? '-' : '' }}Rp {{ $fmt(abs($cashDifference)) }}</span>
    </div>

    <hr>
    <div class="center muted">Dicetak {{ now()->isoFormat('D/M/YY HH:mm') }}</div>
    <div class="center no-print" style="margin-top:10px">
        <button onclick="window.print()">Cetak</button>
        <button onclick="window.close()">Tutup</button>
    </div>
</div>
</body>
</html>
