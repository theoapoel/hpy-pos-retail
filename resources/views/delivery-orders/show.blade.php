@extends('layouts.app')
@section('title', 'Detail Delivery Order')

@section('content')
@php
    $statusColor = ['draft'=>'badge-gray','confirmed'=>'badge-blue','delivering'=>'badge-yellow','completed'=>'badge-green','cancelled'=>'badge-red'][$order->status] ?? 'badge-gray';
    $canEdit    = in_array($order->status, ['draft','confirmed']);
    $canConfirm = $order->status === 'draft';
    $canCancel  = !in_array($order->status, ['completed','cancelled']);
@endphp

<div class="page-header">
    <div>
        <div style="display:flex;align-items:center;gap:10px">
            <a href="{{ route('delivery-orders.index') }}" class="btn btn-ghost btn-sm">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <div class="page-title"><i class="fas fa-truck text-blue"></i> {{ $order->order_no }}</div>
                <div class="page-subtitle">{{ $order->delivery_date->isoFormat('dddd, D MMMM Y') }}</div>
            </div>
        </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
        <span class="badge {{ $statusColor }}" style="font-size:13px;padding:6px 14px">{{ strtoupper($order->status) }}</span>
        @if($canConfirm)
        <form method="POST" action="{{ route('delivery-orders.confirm', $order) }}" style="display:inline" onsubmit="return confirm('Konfirmasi order ini?')">
            @csrf
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-check"></i> Konfirmasi
            </button>
        </form>
        @endif
        @if($canCancel)
        <form method="POST" action="{{ route('delivery-orders.cancel', $order) }}" style="display:inline" onsubmit="return confirm('Batalkan order ini?')">
            @csrf
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-times"></i> Batalkan
            </button>
        </form>
        @endif
        @if($order->erp_sync_status !== 'synced' && $order->status !== 'cancelled')
        <button onclick="syncSalesOrder()" id="syncSoBtn" class="btn btn-ghost">
            <i class="fas fa-sync-alt"></i> Sync SO ERP
        </button>
        @else
            @if($order->erp_sales_order)
            <span class="badge badge-green"><i class="fas fa-check-circle"></i> {{ $order->erp_sales_order }}</span>
            @endif
        @endif
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">

    {{-- LEFT: Order Items + Shipments --}}
    <div style="display:flex;flex-direction:column;gap:20px">

        {{-- Order Items --}}
        <div class="card">
            <div class="card-header" style="padding:16px 20px;border-bottom:1px solid var(--border)">
                <h3 style="font-size:15px;font-weight:600;margin:0">Item Order</h3>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>Produk</th><th>SKU</th><th style="text-align:right">Harga</th>
                        <th style="text-align:right">Qty</th><th style="text-align:right">Subtotal</th>
                    </tr></thead>
                    <tbody>
                    @foreach($order->items as $item)
                    <tr>
                        <td style="font-weight:500">{{ $item->product_name }}</td>
                        <td class="text-muted text-xs">{{ $item->product_sku ?? '—' }}</td>
                        <td style="text-align:right" class="money">Rp {{ number_format($item->price, 0, ',', '.') }}</td>
                        <td style="text-align:right">{{ $item->qty }}</td>
                        <td style="text-align:right;font-weight:600" class="money">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                    </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                    <tr style="background:var(--surface2)">
                        <td colspan="4" style="text-align:right;font-weight:700;padding:12px 16px">Total Order</td>
                        <td style="text-align:right;font-weight:700;font-size:15px;padding:12px 16px" class="money">
                            Rp {{ number_format($order->total, 0, ',', '.') }}
                        </td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Shipments --}}
        <div>
            <h3 style="font-size:15px;font-weight:600;margin:0 0 12px">
                Tujuan Pengiriman
                <span class="badge badge-gray" style="margin-left:6px">{{ $order->shipments->count() }} tujuan</span>
            </h3>
            @foreach($order->shipments as $ship)
            @php
                $sColor = ['pending'=>'badge-gray','delivered'=>'badge-green'][$ship->status] ?? 'badge-gray';
            @endphp
            <div class="card" style="margin-bottom:16px">
                <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <div style="font-weight:600;font-size:14px">
                            <span class="badge badge-gray" style="margin-right:8px">#{{ $ship->sequence }}</span>
                            {{ $ship->recipient_name }}
                            @if($ship->recipient_phone)
                                <span class="text-muted text-xs" style="margin-left:6px"><i class="fas fa-phone"></i> {{ $ship->recipient_phone }}</span>
                            @endif
                        </div>
                        <div class="text-muted text-xs" style="margin-top:4px"><i class="fas fa-map-marker-alt"></i> {{ $ship->shipping_address }}</div>
                        @if($ship->notes)
                        <div class="text-muted text-xs" style="margin-top:2px"><i class="fas fa-sticky-note"></i> {{ $ship->notes }}</div>
                        @endif
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
                        <span class="badge {{ $sColor }}">{{ strtoupper($ship->status) }}</span>
                        @if($ship->erp_sync_status === 'synced' && $ship->erp_delivery_note)
                            <span class="badge badge-green text-xs"><i class="fas fa-check-circle"></i> {{ $ship->erp_delivery_note }}</span>
                        @elseif($ship->erp_sync_status === 'failed')
                            <span class="badge badge-red text-xs" title="{{ $ship->erp_sync_error }}">DN FAILED</span>
                        @else
                            <button onclick="syncDeliveryNote(this)"
                                data-url="{{ route('delivery-notes.sync-dn', $ship) }}"
                                class="btn btn-ghost btn-sm"
                                {{ $order->erp_sales_order ? '' : 'disabled title=Sync SO dulu' }}>
                                <i class="fas fa-sync-alt"></i> Sync DN
                            </button>
                        @endif
                        @if($ship->status !== 'delivered')
                        <button onclick="markDelivered(this)"
                            data-url="{{ route('delivery-notes.mark-delivered', $ship) }}"
                            class="btn btn-ghost btn-sm">
                            <i class="fas fa-check"></i> Terkirim
                        </button>
                        @endif
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th>Produk</th><th>SKU</th>
                            <th style="text-align:right">Harga</th>
                            <th style="text-align:right">Qty</th>
                            <th style="text-align:right">Subtotal</th>
                        </tr></thead>
                        <tbody>
                        @foreach($ship->items as $si)
                        <tr>
                            <td style="font-weight:500">{{ $si['product_name'] }}</td>
                            <td class="text-muted text-xs">{{ $si['product_sku'] ?? '—' }}</td>
                            <td style="text-align:right" class="money">Rp {{ number_format($si['price'] ?? 0, 0, ',', '.') }}</td>
                            <td style="text-align:right">{{ $si['qty'] }}</td>
                            <td style="text-align:right;font-weight:600" class="money">Rp {{ number_format($si['subtotal'] ?? 0, 0, ',', '.') }}</td>
                        </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                        <tr style="background:var(--surface2)">
                            <td colspan="4" style="text-align:right;font-weight:600;padding:10px 16px">Total Tujuan</td>
                            <td style="text-align:right;font-weight:700;padding:10px 16px" class="money">
                                Rp {{ number_format($ship->total, 0, ',', '.') }}
                            </td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
                @if($ship->delivered_at)
                <div style="padding:10px 20px;font-size:12px;color:var(--green);border-top:1px solid var(--border)">
                    <i class="fas fa-check-circle"></i> Terkirim: {{ $ship->delivered_at->isoFormat('D MMM Y HH:mm') }}
                </div>
                @endif
            </div>
            @endforeach
        </div>

    </div>

    {{-- RIGHT: Info sidebar --}}
    <div style="display:flex;flex-direction:column;gap:16px">

        {{-- Customer --}}
        <div class="card">
            <div class="card-header" style="padding:14px 16px;border-bottom:1px solid var(--border)">
                <h4 style="font-size:13px;font-weight:700;margin:0;text-transform:uppercase;color:var(--text3)">Customer</h4>
            </div>
            <div class="card-body" style="padding:14px 16px">
                <div style="font-weight:600;font-size:14px">{{ $order->customer->name }}</div>
                @if($order->customer->phone)
                <div class="text-muted text-xs" style="margin-top:4px"><i class="fas fa-phone"></i> {{ $order->customer->phone }}</div>
                @endif
            </div>
        </div>

        {{-- Billing Address --}}
        @if($order->billing_address)
        <div class="card">
            <div class="card-header" style="padding:14px 16px;border-bottom:1px solid var(--border)">
                <h4 style="font-size:13px;font-weight:700;margin:0;text-transform:uppercase;color:var(--text3)">Alamat Penagihan</h4>
            </div>
            <div class="card-body" style="padding:14px 16px;font-size:13px;line-height:1.5">
                {{ $order->billing_address }}
            </div>
        </div>
        @endif

        {{-- ERP Sync Status --}}
        <div class="card">
            <div class="card-header" style="padding:14px 16px;border-bottom:1px solid var(--border)">
                <h4 style="font-size:13px;font-weight:700;margin:0;text-transform:uppercase;color:var(--text3)">ERP Sync</h4>
            </div>
            <div class="card-body" style="padding:14px 16px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <span class="text-xs text-muted">Sales Order</span>
                    @if($order->erp_sales_order)
                        <span class="badge badge-green">{{ $order->erp_sales_order }}</span>
                    @elseif($order->erp_sync_status === 'failed')
                        <span class="badge badge-red">FAILED</span>
                    @else
                        <span class="badge badge-gray">BELUM SYNC</span>
                    @endif
                </div>
                @if($order->erp_sync_error)
                <div class="alert" style="background:#FEF3E2;color:#B45309;border-radius:6px;padding:8px 10px;font-size:12px;margin-top:6px">
                    <i class="fas fa-exclamation-triangle"></i> {{ $order->erp_sync_error }}
                </div>
                @endif
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px">
                    <span class="text-xs text-muted">Delivery Notes</span>
                    @php
                        $syncedDn = $order->shipments->where('erp_sync_status','synced')->count();
                        $totalShip = $order->shipments->count();
                    @endphp
                    <span class="badge {{ $syncedDn === $totalShip && $totalShip > 0 ? 'badge-green' : 'badge-gray' }}">
                        {{ $syncedDn }}/{{ $totalShip }} synced
                    </span>
                </div>
            </div>
        </div>

        {{-- Notes --}}
        @if($order->notes)
        <div class="card">
            <div class="card-header" style="padding:14px 16px;border-bottom:1px solid var(--border)">
                <h4 style="font-size:13px;font-weight:700;margin:0;text-transform:uppercase;color:var(--text3)">Catatan</h4>
            </div>
            <div class="card-body" style="padding:14px 16px;font-size:13px;line-height:1.5;color:var(--text2)">
                {{ $order->notes }}
            </div>
        </div>
        @endif

        {{-- Meta --}}
        <div class="card">
            <div class="card-body" style="padding:14px 16px;font-size:12px;color:var(--text3)">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span>Dibuat oleh</span>
                    <span style="font-weight:500;color:var(--text2)">{{ $order->creator?->name ?? '—' }}</span>
                </div>
                <div style="display:flex;justify-content:space-between">
                    <span>Tanggal buat</span>
                    <span style="font-weight:500;color:var(--text2)">{{ $order->created_at->isoFormat('D MMM Y HH:mm') }}</span>
                </div>
            </div>
        </div>

    </div>
</div>

@endsection

@push('scripts')
<script>
async function syncSalesOrder() {
    const btn = document.getElementById('syncSoBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Sync...';

    try {
        const res = await api.post('{{ route("delivery-orders.sync-so", $order) }}');
        if (res.success) {
            toast('Sales Order berhasil dibuat: ' + res.sales_order, 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            toast('Sync gagal: ' + (res.error ?? 'Unknown error'), 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt"></i> Sync SO ERP';
        }
    } catch(e) {
        toast('Error: ' + e.message, 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Sync SO ERP';
    }
}

async function syncDeliveryNote(btn) {
    const url = btn.dataset.url;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';

    try {
        const res = await api.post(url);
        if (res.success) {
            toast('Delivery Note dibuat: ' + res.delivery_note, 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            toast('Sync DN gagal: ' + (res.error ?? 'Unknown error'), 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt"></i> Sync DN';
        }
    } catch(e) {
        toast('Error: ' + e.message, 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Sync DN';
    }
}

async function markDelivered(btn) {
    if (!confirm('Tandai pengiriman ini sebagai terkirim?')) return;
    const url = btn.dataset.url;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';

    try {
        const res = await api.post(url);
        if (res.success) {
            toast('Ditandai terkirim.', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            toast(res.error ?? 'Gagal.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Terkirim';
        }
    } catch(e) {
        toast('Error: ' + e.message, 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Terkirim';
    }
}
</script>
@endpush
