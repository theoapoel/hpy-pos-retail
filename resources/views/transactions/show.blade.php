{{-- resources/views/transactions/show.blade.php --}}
@extends('layouts.app')
@section('title','Detail Transaksi')
@section('content')
<div class="page-header">
    <div><div class="page-title">Detail Transaksi</div><div class="page-subtitle">{{ $transaction->invoice_no }}</div></div>
    <div style="display:flex;gap:8px">
        <a href="{{ route('pos.print',$transaction) }}" target="_blank" class="btn btn-outline"><i class="fas fa-print"></i> Cetak</a>
        <a href="{{ route('transactions.index') }}" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>
</div>
<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px">
    <div>
        <div class="card" style="margin-bottom:16px">
            <div class="card-header"><div class="card-title">Item Transaksi</div></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Produk</th><th>Harga</th><th>Qty</th><th>Subtotal</th></tr></thead>
                    <tbody>
                    @foreach($transaction->items as $item)
                    <tr>
                        <td><div class="font-medium">{{ $item->product_name }}</div><div class="text-sm text-muted">SKU: {{ $item->product_sku }}</div></td>
                        <td class="money">Rp {{ number_format($item->price,0,',','.') }}</td>
                        <td class="font-bold">{{ $item->quantity }}</td>
                        <td class="money text-blue">Rp {{ number_format($item->subtotal,0,',','.') }}</td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div>
        <div class="card" style="margin-bottom:16px">
            <div class="card-header"><div class="card-title">Ringkasan</div></div>
            <div class="card-body">
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px"><span class="text-muted">Kasir</span><span>{{ $transaction->user->name }}</span></div>
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px"><span class="text-muted">Customer</span><span>{{ $transaction->customer?->name ?? 'Walk-in' }}</span></div>
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px"><span class="text-muted">Pembayaran</span><span class="badge badge-blue">{{ strtoupper($transaction->payment_method) }}</span></div>
                @if($transaction->pos_class)
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px"><span class="text-muted">POS Class</span><span class="badge badge-gray">{{ $transaction->pos_class }}</span></div>
                @endif
                <hr class="divider">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:14px"><span>Subtotal</span><span>Rp {{ number_format($transaction->subtotal,0,',','.') }}</span></div>
                @if($transaction->discount_amount > 0)
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:14px;color:var(--red)"><span>Diskon</span><span>- Rp {{ number_format($transaction->discount_amount,0,',','.') }}</span></div>
                @endif
                @if($transaction->tax_amount > 0)
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:14px"><span>Pajak</span><span>Rp {{ number_format($transaction->tax_amount,0,',','.') }}</span></div>
                @endif
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:18px;font-weight:700"><span>TOTAL</span><span class="text-blue">Rp {{ number_format($transaction->total,0,',','.') }}</span></div>
                <div style="display:flex;justify-content:space-between;font-size:14px"><span>Bayar</span><span>Rp {{ number_format($transaction->paid_amount,0,',','.') }}</span></div>
                @if($transaction->change_amount > 0)
                <div style="display:flex;justify-content:space-between;font-size:14px;color:var(--green)"><span>Kembalian</span><span>Rp {{ number_format($transaction->change_amount,0,',','.') }}</span></div>
                @endif
            </div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-title">Status HPY</div></div>
            <div class="card-body">
                <span class="badge {{ ['pending'=>'badge-yellow','synced'=>'badge-green','failed'=>'badge-red'][$transaction->erp_sync_status]??'badge-gray' }}">
                    {{ strtoupper($transaction->erp_sync_status) }}
                </span>
                @if($transaction->erp_pos_invoice)
                <div class="mt-2 text-sm"><strong>Doc:</strong> {{ $transaction->erp_pos_invoice }}</div>
                <div class="text-sm text-muted">{{ $transaction->erp_synced_at?->format('d/m/Y H:i') }}</div>
                @endif
                @if($transaction->erp_sync_error && $transaction->erp_sync_status === 'failed')
                <div style="background:#FCE8E6;border-radius:6px;padding:10px;margin-top:8px;font-size:12px;color:var(--red)">{{ Str::limit($transaction->erp_sync_error, 200) }}</div>
                <button class="btn btn-outline btn-sm mt-2" onclick="syncThis()"><i class="fas fa-sync-alt"></i> Retry Sync</button>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script>
async function syncThis() {
    const resp = await fetch('{{ route("sync.single", $transaction) }}',{method:'POST',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'}});
    const data = await resp.json();
    toast(data.success?'Berhasil sync!':'Gagal: '+(data.error||''),data.success?'success':'error');
    if(data.success) setTimeout(()=>location.reload(),1000);
}
</script>
@endpush
