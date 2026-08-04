@extends('layouts.app')
@section('title', 'Audit Dokumen HPY')

@section('content')
<div class="page-header">
    <div>
        <div class="page-title"><i class="fas fa-magnifying-glass-dollar text-blue"></i> Audit Dokumen HPY</div>
        <div class="page-subtitle">Bandingkan nota kasir dengan POS Invoice di ERP, lalu terbitkan ulang yang melenceng</div>
    </div>
    <a href="{{ route('sync.index') }}" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Kembali ke Sync</a>
</div>

<div class="card" style="margin-bottom:20px">
    <div class="card-body">
        <form method="GET" action="{{ route('sync.audit') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <input type="hidden" name="run" value="1">
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:var(--text2);margin-bottom:4px">Dari Tanggal</label>
                <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control" required>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:var(--text2);margin-bottom:4px">Sampai Tanggal</label>
                <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control" required>
            </div>
            <div style="display:flex;align-items:center;gap:6px;padding-bottom:8px">
                <input type="checkbox" name="only_diff" value="1" id="onlyDiff" {{ $hanyaSelisih ? 'checked' : '' }}>
                <label for="onlyDiff" style="font-size:13px;color:var(--text2)">Tampilkan yang selisih saja</label>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Periksa</button>
        </form>
        <div style="margin-top:10px;font-size:12px;color:var(--text3)">
            Pemeriksaan memanggil ERP satu kali per nota, jadi rentang lebar butuh waktu. Maksimum {{ $maksNota }} nota sekali jalan.
        </div>
    </div>
</div>

@if($error)
    <div class="card" style="margin-bottom:20px;border-color:var(--red)">
        <div class="card-body" style="color:var(--red)"><i class="fas fa-triangle-exclamation"></i> {{ $error }}</div>
    </div>
@endif

@if($dijalankan && ! $error)
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 18px;margin-bottom:20px;font-size:13px;color:var(--text2)">
        Diperiksa <b>{{ $diperiksa }}</b> nota &middot; ditampilkan <b>{{ count($rows) }}</b>
        @if($terpotong)
            <span style="color:#E37400">&middot; dibatasi {{ $maksNota }} nota pertama, persempit rentang tanggalnya untuk melihat sisanya</span>
        @endif
    </div>
@endif

@if($dijalankan && ! $error && count($rows) === 0)
    <div class="card">
        <div class="card-body" style="text-align:center;padding:40px;color:var(--text3)">
            <i class="fas fa-circle-check" style="font-size:32px;color:var(--green);margin-bottom:10px;display:block"></i>
            Tidak ada selisih pada rentang ini.
        </div>
    </div>
@endif

@foreach($rows as $row)
    @php $trx = $row['trx']; @endphp
    <div class="card" style="margin-bottom:16px" id="card-{{ $trx->id }}">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
            <div>
                <div class="card-title">{{ $trx->invoice_no }}</div>
                <div style="font-size:12px;color:var(--text3)">
                    {{ $trx->created_at->format('d/m/Y H:i') }} &middot; ERP:
                    <span style="font-family:monospace" id="docname-{{ $trx->id }}">{{ $trx->erp_pos_invoice }}</span>
                    @if($row['docstatus'] === 2)
                        <span class="badge" style="background:var(--red-light);color:var(--red)">dibatalkan</span>
                    @endif
                    @if($row['consolidated'])
                        <span class="badge" style="background:var(--yellow-light,#FEF7E0);color:#E37400">consolidated</span>
                    @endif
                </div>
            </div>
            <div style="text-align:right">
                @if($row['error'])
                    <span style="color:var(--red);font-size:13px"><i class="fas fa-triangle-exclamation"></i> {{ $row['error'] }}</span>
                @else
                    <div style="font-size:12px;color:var(--text3)">Total lokal / ERP</div>
                    <div style="font-size:15px;font-weight:700">
                        Rp {{ number_format($row['total_lokal'], 0, ',', '.') }}
                        <span style="color:var(--text3)">/</span>
                        <span style="color:{{ abs($row['selisih_total']) > 0.5 ? 'var(--red)' : 'var(--text)' }}">
                            Rp {{ number_format($row['total_erp'], 0, ',', '.') }}
                        </span>
                    </div>
                    @if(abs($row['selisih_total']) > 0.5)
                        <div style="font-size:12px;color:var(--red)">
                            selisih Rp {{ number_format($row['selisih_total'], 0, ',', '.') }}
                        </div>
                    @endif
                @endif
            </div>
        </div>

        @if(! $row['error'])
        <div class="card-body" style="padding:0">
            <table class="table" style="margin:0">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style="text-align:right">Qty</th>
                        <th style="text-align:right">Diskon</th>
                        <th style="text-align:right">Harga Lokal</th>
                        <th style="text-align:right">Harga ERP</th>
                        <th style="text-align:right">Jumlah Lokal</th>
                        <th style="text-align:right">Jumlah ERP</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($row['items'] as $item)
                    <tr style="{{ $item['beda'] ? 'background:var(--red-light)' : '' }}">
                        <td>
                            {{ $item['nama'] }}
                            <div style="font-size:11px;color:var(--text3);font-family:monospace">{{ $item['kode'] }}</div>
                        </td>
                        <td style="text-align:right">{{ rtrim(rtrim(number_format($item['qty'], 2, ',', '.'), '0'), ',') }}</td>
                        <td style="text-align:right;color:{{ $item['diskon'] > 0 ? 'var(--red)' : 'var(--text3)' }}">
                            {{ $item['diskon'] > 0 ? '- '.number_format($item['diskon'], 0, ',', '.') : '—' }}
                        </td>
                        <td style="text-align:right">{{ number_format($item['rate_lokal'], 0, ',', '.') }}</td>
                        <td style="text-align:right">
                            {{ $item['rate_erp'] === null ? 'tidak ada' : number_format($item['rate_erp'], 0, ',', '.') }}
                        </td>
                        <td style="text-align:right">{{ number_format($item['amount_lokal'], 0, ',', '.') }}</td>
                        <td style="text-align:right;font-weight:{{ $item['beda'] ? '700' : '400' }};color:{{ $item['beda'] ? 'var(--red)' : 'inherit' }}">
                            {{ $item['amount_erp'] === null ? 'tidak ada' : number_format($item['amount_erp'], 0, ',', '.') }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        @if($row['selisih'] && auth()->user()->role === 'admin')
        <div class="card-body" style="border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
            <div style="font-size:12px;color:var(--text3)">
                Membatalkan POS Invoice lama di ERP lalu menerbitkan ulang dari data kasir.
                Data lokal tidak berubah selain nomor invoice ERP-nya.
            </div>
            <button class="btn btn-primary" onclick="resync({{ $trx->id }})" id="btn-{{ $trx->id }}"
                    {{ $row['consolidated'] ? 'disabled' : '' }}>
                <i class="fas fa-rotate"></i> Batalkan &amp; Terbitkan Ulang
            </button>
        </div>
        @endif
    </div>
@endforeach

<script>
async function resync(id) {
    const btn = document.getElementById('btn-' + id);
    if (! confirm('Batalkan POS Invoice lama di ERP dan terbitkan ulang?\n\nStok dan jurnal dokumen lama ikut ter-reverse di ERP.')) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';

    try {
        const res = await fetch('{{ url('sync/audit') }}/' + id + '/resync', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
        });
        const data = await res.json();

        if (data.success) {
            document.getElementById('docname-' + id).textContent = data.invoice_baru;
            btn.className = 'btn btn-success';
            btn.innerHTML = '<i class="fas fa-check"></i> Terbit ulang: ' + data.invoice_baru;
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-rotate"></i> Batalkan &amp; Terbitkan Ulang';
            alert('Gagal: ' + data.error);
        }
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-rotate"></i> Batalkan &amp; Terbitkan Ulang';
        alert('Gagal menghubungi server: ' + e.message);
    }
}
</script>
@endsection
