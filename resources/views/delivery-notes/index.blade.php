@extends('layouts.app')
@section('title', 'Delivery Notes')

@section('content')
<div class="page-header">
    <div>
        <div class="page-title"><i class="fas fa-map-marker-alt text-blue"></i> Delivery Notes</div>
        <div class="page-subtitle">Kelola pengiriman per tujuan</div>
    </div>
</div>

{{-- Summary counts --}}
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:20px">
    @php
        $countAll     = $counts->sum();
        $countPending  = $counts['pending']   ?? 0;
        $countDelivered = $counts['delivered'] ?? 0;
    @endphp
    <div class="card" style="cursor:pointer" onclick="filterStatus('')">
        <div class="card-body" style="padding:14px;text-align:center">
            <div style="font-size:22px;font-weight:700">{{ $countAll }}</div>
            <div style="font-size:12px;color:var(--text3)">Semua</div>
        </div>
    </div>
    <div class="card" style="cursor:pointer;border-left:3px solid var(--text3)" onclick="filterStatus('pending')">
        <div class="card-body" style="padding:14px;text-align:center">
            <div style="font-size:22px;font-weight:700">{{ $countPending }}</div>
            <div style="font-size:12px;color:var(--text3)">Pending</div>
        </div>
    </div>
    <div class="card" style="cursor:pointer;border-left:3px solid var(--green)" onclick="filterStatus('delivered')">
        <div class="card-body" style="padding:14px;text-align:center">
            <div style="font-size:22px;font-weight:700;color:var(--green)">{{ $countDelivered }}</div>
            <div style="font-size:12px;color:var(--text3)">Terkirim</div>
        </div>
    </div>
</div>

{{-- Filter --}}
<div class="card" style="margin-bottom:20px">
    <div class="card-body">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Tgl Kirim</label>
                <input type="date" name="date" class="form-control" value="{{ request('date') }}">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Status</label>
                <select name="status" class="form-control form-select" id="statusFilter">
                    <option value="">Semua</option>
                    <option value="pending"   {{ request('status')==='pending'   ? 'selected' : '' }}>Pending</option>
                    <option value="delivered" {{ request('status')==='delivered' ? 'selected' : '' }}>Delivered</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Cari</button>
            @if(request()->hasAny(['date','status']))
            <a href="{{ route('delivery-notes.index') }}" class="btn btn-ghost">Reset</a>
            @endif
        </form>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>No. Order</th>
                <th>Customer</th>
                <th>Tgl Kirim</th>
                <th>Penerima</th>
                <th>Alamat</th>
                <th style="text-align:right">Total</th>
                <th>Status</th>
                <th>ERP DN</th>
                <th></th>
            </tr></thead>
            <tbody>
            @forelse($shipments as $ship)
            @php
                $sColor = ['pending'=>'badge-gray','delivered'=>'badge-green'][$ship->status] ?? 'badge-gray';
            @endphp
            <tr id="row-{{ $ship->id }}">
                <td>
                    <a href="{{ route('delivery-orders.show', $ship->order) }}" class="text-blue font-medium">
                        {{ $ship->order->order_no }}
                    </a>
                    <div style="font-size:11px;color:var(--text3)">#{{ $ship->sequence }}</div>
                </td>
                <td>{{ $ship->order->customer->name }}</td>
                <td>{{ $ship->order->delivery_date->isoFormat('D MMM Y') }}</td>
                <td>
                    <div style="font-weight:500">{{ $ship->recipient_name }}</div>
                    @if($ship->recipient_phone)
                    <div style="font-size:11px;color:var(--text3)"><i class="fas fa-phone"></i> {{ $ship->recipient_phone }}</div>
                    @endif
                </td>
                <td style="max-width:200px;font-size:12px;color:var(--text2)">{{ Str::limit($ship->shipping_address, 60) }}</td>
                <td style="text-align:right" class="money">Rp {{ number_format($ship->total, 0, ',', '.') }}</td>
                <td>
                    <span class="badge {{ $sColor }}" id="status-{{ $ship->id }}">{{ strtoupper($ship->status) }}</span>
                    @if($ship->delivered_at)
                    <div style="font-size:11px;color:var(--text3)">{{ $ship->delivered_at->isoFormat('D/M HH:mm') }}</div>
                    @endif
                </td>
                <td>
                    @if($ship->erp_sync_status === 'synced' && $ship->erp_delivery_note)
                        <span class="badge badge-green text-xs">{{ $ship->erp_delivery_note }}</span>
                    @elseif($ship->erp_sync_status === 'failed')
                        <span class="badge badge-red text-xs" title="{{ $ship->erp_sync_error ?? '' }}">FAILED</span>
                    @else
                        <span class="text-muted text-xs">—</span>
                    @endif
                </td>
                <td>
                    <div style="display:flex;gap:6px">
                        @if($ship->status !== 'delivered')
                        <button onclick="markDelivered({{ $ship->id }}, this)"
                            class="btn btn-ghost btn-sm" title="Tandai Terkirim"
                            id="markBtn-{{ $ship->id }}">
                            <i class="fas fa-check"></i>
                        </button>
                        @endif
                        @if($ship->erp_sync_status !== 'synced')
                        <button onclick="syncDn({{ $ship->id }}, this)"
                            class="btn btn-ghost btn-sm" title="Sync Delivery Note ke ERP"
                            id="dnBtn-{{ $ship->id }}"
                            {{ $ship->order->erp_sales_order ? '' : 'disabled title=Sync SO order dulu' }}>
                            <i class="fas fa-sync-alt"></i>
                        </button>
                        @endif
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="9" style="text-align:center;padding:40px;color:#80868B">Belum ada data pengiriman</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($shipments->hasPages())
    <div style="padding:16px 20px;border-top:1px solid var(--border)">
        {{ $shipments->links() }}
    </div>
    @endif
</div>

@endsection

@push('scripts')
<script>
function filterStatus(val) {
    const form = document.querySelector('form[method="GET"]');
    document.getElementById('statusFilter').value = val;
    form.submit();
}

async function markDelivered(id, btn) {
    if (!confirm('Tandai pengiriman ini sebagai terkirim?')) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';

    try {
        const res = await api.post('/delivery-notes/' + id + '/mark-delivered');
        if (res.success) {
            document.getElementById('status-' + id).className = 'badge badge-green';
            document.getElementById('status-' + id).textContent = 'DELIVERED';
            btn.remove();
            toast('Ditandai terkirim.', 'success');
        } else {
            toast(res.error ?? 'Gagal.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i>';
        }
    } catch(e) {
        toast('Error: ' + e.message, 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i>';
    }
}

async function syncDn(id, btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';

    try {
        const res = await api.post('/delivery-notes/' + id + '/sync-dn');
        if (res.success) {
            toast('Delivery Note dibuat: ' + res.delivery_note, 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            toast('Sync gagal: ' + (res.error ?? 'Unknown error'), 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt"></i>';
        }
    } catch(e) {
        toast('Error: ' + e.message, 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt"></i>';
    }
}
</script>
@endpush
