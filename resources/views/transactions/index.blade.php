{{-- resources/views/transactions/index.blade.php --}}
@extends('layouts.app')
@section('title','Transaksi')

@push('styles')
<style>
    .pagination-wrap { padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 12px; }
    .pagination-info { font-size: 13px; color: var(--text3); }
    .pagination { display: flex; align-items: center; gap: 4px; list-style: none; }
    .pagination li a,
    .pagination li span {
        display: flex; align-items: center; justify-content: center;
        min-width: 36px; height: 36px; border-radius: 8px; padding: 0 10px;
        font-size: 13px; font-weight: 600; text-decoration: none;
        color: var(--text2); border: 1px solid var(--border);
        background: var(--surface); transition: all .2s; gap: 5px;
    }
    .pagination li a:hover { background: var(--blue-light); color: var(--blue); border-color: var(--blue); }
    .pagination li.active span { background: var(--blue); color: #fff; border-color: var(--blue); }
    .pagination li.disabled span { opacity: .4; cursor: not-allowed; }
</style>
@endpush

@section('content')
<div class="page-header">
    <div>
        <div class="page-title"><i class="fas fa-receipt text-blue"></i> Riwayat Transaksi</div>
        <div class="page-subtitle">Total {{ $transactions->total() }} transaksi</div>
    </div>
    <a href="{{ route('pos.index') }}" class="btn btn-primary">
        <i class="fas fa-cash-register"></i> Buka Kasir
    </a>
</div>

<div class="card">
    <div class="card-header" style="flex-wrap:wrap;gap:10px">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;flex:1">
            <input type="text" name="search" class="form-control" placeholder="Cari invoice..."
                value="{{ request('search') }}" style="max-width:200px">
            <input type="date" name="date_from" class="form-control"
                value="{{ request('date_from') }}" style="max-width:160px">
            <input type="date" name="date_to" class="form-control"
                value="{{ request('date_to') }}" style="max-width:160px">
            <select name="status" class="form-control form-select" style="max-width:150px">
                <option value="">Semua Status</option>
                <option value="completed" {{ request('status')=='completed'?'selected':'' }}>Selesai</option>
                <option value="cancelled" {{ request('status')=='cancelled'?'selected':'' }}>Dibatalkan</option>
            </select>
            <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Filter</button>
            @if(request()->hasAny(['search','date_from','date_to','status']))
            <a href="{{ route('transactions.index') }}" class="btn btn-ghost"><i class="fas fa-times"></i> Reset</a>
            @endif
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Invoice</th><th>Kasir</th><th>Customer</th><th>Total</th>
                    <th>Bayar</th><th>Status</th><th>Sync ERP</th><th>Waktu</th><th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($transactions as $tx)
            <tr>
                <td>
                    <a href="{{ route('transactions.show',$tx) }}" class="text-blue font-medium">
                        {{ $tx->invoice_no }}
                    </a>
                </td>
                <td class="text-sm">{{ $tx->user->name }}</td>
                <td class="text-sm">
                    @if($tx->customer)
                        <div style="display:flex;align-items:center;gap:6px">
                            <div style="width:26px;height:26px;border-radius:50%;background:var(--blue-light);color:var(--blue);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">
                                {{ substr($tx->customer->name, 0, 1) }}
                            </div>
                            {{ $tx->customer->name }}
                        </div>
                    @else
                        <span style="color:var(--text3);font-style:italic;font-size:12px">
                            <i class="fas fa-user" style="font-size:10px"></i> Walk-in
                        </span>
                    @endif
                </td>
                <td class="money font-bold">Rp {{ number_format($tx->total,0,',','.') }}</td>
                <td>
                    @php $payBadge=['cash'=>'badge-green','card'=>'badge-blue','transfer'=>'badge-yellow','qris'=>'badge-blue']; @endphp
                    <span class="badge {{ $payBadge[$tx->payment_method]??'badge-gray' }}">
                        {{ strtoupper($tx->payment_method) }}
                    </span>
                </td>
                <td>
                    <span class="badge {{ $tx->status==='completed'?'badge-green':'badge-red' }}">
                        {{ $tx->status==='completed'?'✓ Selesai':'✕ Batal' }}
                    </span>
                </td>
                <td>
                    @php $syncBadge=['pending'=>'badge-yellow','synced'=>'badge-green','failed'=>'badge-red'];
                         $syncLabel=['pending'=>'⏳ Pending','synced'=>'✓ Synced','failed'=>'✕ Failed']; @endphp
                    <span class="badge {{ $syncBadge[$tx->erp_sync_status]??'badge-gray' }}">
                        {{ $syncLabel[$tx->erp_sync_status]??$tx->erp_sync_status }}
                    </span>
                </td>
                <td class="text-sm text-muted">{{ $tx->created_at->format('d/m/Y H:i') }}</td>
                <td style="white-space:nowrap">
                    <a href="{{ route('transactions.show',$tx) }}" class="btn btn-ghost btn-sm" title="Detail">
                        <i class="fas fa-eye"></i>
                    </a>
                    <a href="{{ route('pos.print',$tx) }}" target="_blank" class="btn btn-ghost btn-sm" title="Cetak">
                        <i class="fas fa-print"></i>
                    </a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="9" style="text-align:center;padding:60px;color:var(--text3)">
                    <div style="font-size:40px;margin-bottom:12px">🧾</div>
                    <div style="font-size:15px;font-weight:600">Tidak ada transaksi ditemukan</div>
                    <div style="font-size:13px;margin-top:4px">Coba ubah filter pencarian</div>
                </td>
            </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Custom Pagination --}}
    @if($transactions->hasPages())
    <div class="pagination-wrap">
        <div class="pagination-info">
            Menampilkan {{ $transactions->firstItem() }}–{{ $transactions->lastItem() }}
            dari <strong>{{ $transactions->total() }}</strong> transaksi
        </div>
        <ul class="pagination">
            {{-- Prev --}}
            <li class="{{ $transactions->onFirstPage() ? 'disabled' : '' }}">
                @if($transactions->onFirstPage())
                    <span><i class="fas fa-chevron-left" style="font-size:10px"></i> Prev</span>
                @else
                    <a href="{{ $transactions->previousPageUrl() }}">
                        <i class="fas fa-chevron-left" style="font-size:10px"></i> Prev
                    </a>
                @endif
            </li>

            {{-- Page Numbers --}}
            @foreach($transactions->getUrlRange(max(1,$transactions->currentPage()-2), min($transactions->lastPage(),$transactions->currentPage()+2)) as $page => $url)
            <li class="{{ $page==$transactions->currentPage()?'active':'' }}">
                @if($page==$transactions->currentPage())
                    <span>{{ $page }}</span>
                @else
                    <a href="{{ $url }}">{{ $page }}</a>
                @endif
            </li>
            @endforeach

            {{-- Next --}}
            <li class="{{ !$transactions->hasMorePages() ? 'disabled' : '' }}">
                @if($transactions->hasMorePages())
                    <a href="{{ $transactions->nextPageUrl() }}">
                        Next <i class="fas fa-chevron-right" style="font-size:10px"></i>
                    </a>
                @else
                    <span>Next <i class="fas fa-chevron-right" style="font-size:10px"></i></span>
                @endif
            </li>
        </ul>
    </div>
    @endif
</div>
@endsection
