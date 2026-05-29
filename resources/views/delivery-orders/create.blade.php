@extends('layouts.app')
@section('title', 'Buat Delivery Order')

@push('styles')
<style>
.item-row, .ship-row { animation: fadeIn .2s ease; }
@keyframes fadeIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:none; } }
.ship-card { border:1px solid var(--border); border-radius:10px; padding:16px; margin-bottom:12px; background:var(--surface); }
.ship-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
.ship-item-row { display:grid; grid-template-columns:1fr 80px 110px 100px 28px; gap:8px; align-items:center; margin-bottom:8px; }
</style>
@endpush

@section('content')
<div class="page-header">
    <div>
        <div class="page-title"><i class="fas fa-plus text-blue"></i> Buat Delivery Order</div>
    </div>
    <a href="{{ route('delivery-orders.index') }}" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Kembali</a>
</div>

<form id="doForm" method="POST" action="{{ route('delivery-orders.store') }}">
@csrf
<div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start">

    {{-- Kiri: Detail Order --}}
    <div>
        {{-- Info Customer --}}
        <div class="card" style="margin-bottom:16px">
            <div class="card-header"><div class="card-title"><i class="fas fa-user text-blue"></i> Informasi Order</div></div>
            <div class="card-body">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Customer <span style="color:var(--red)">*</span></label>
                        <select name="customer_id" id="customerSelect" class="form-control form-select" required onchange="fillBillingAddress(this)">
                            <option value="">— Pilih Customer —</option>
                            @foreach($customers as $c)
                            <option value="{{ $c->id }}" data-address="{{ $c->address }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal Pengiriman <span style="color:var(--red)">*</span></label>
                        <input type="date" name="delivery_date" class="form-control" required
                            min="{{ now()->format('Y-m-d') }}" value="{{ old('delivery_date') }}">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Alamat Penagihan (Billing Address)</label>
                    <textarea name="billing_address" id="billingAddress" class="form-control" rows="2"
                        placeholder="Alamat penagihan customer"></textarea>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label class="form-label">Catatan Order</label>
                    <input type="text" name="notes" class="form-control" placeholder="Catatan tambahan (opsional)">
                </div>
            </div>
        </div>

        {{-- Items --}}
        <div class="card" style="margin-bottom:16px">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-box text-blue"></i> Item Order</div>
                <button type="button" onclick="addItem()" class="btn btn-outline btn-sm">
                    <i class="fas fa-plus"></i> Tambah Item
                </button>
            </div>
            <div class="card-body" style="padding:0">
                <div style="display:grid;grid-template-columns:1fr 80px 110px 100px 28px;gap:8px;padding:10px 16px;background:var(--surface2);font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px">
                    <span>Produk</span><span>Qty</span><span>Harga</span><span>Subtotal</span><span></span>
                </div>
                <div id="itemsContainer" style="padding:12px 16px"></div>
                <div style="padding:12px 16px;border-top:1px solid var(--border);text-align:right">
                    <span class="text-muted text-sm">Total Order: </span>
                    <span id="orderTotal" class="money font-bold text-blue" style="font-size:16px">Rp 0</span>
                </div>
            </div>
        </div>

        {{-- Shipments / Tujuan Pengiriman --}}
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-map-marker-alt text-blue"></i> Tujuan Pengiriman</div>
                <button type="button" onclick="addShipment()" class="btn btn-outline btn-sm">
                    <i class="fas fa-plus"></i> Tambah Tujuan
                </button>
            </div>
            <div class="card-body">
                <div id="shipmentsContainer"></div>
            </div>
        </div>
    </div>

    {{-- Kanan: Ringkasan --}}
    <div style="position:sticky;top:80px">
        <div class="card" style="margin-bottom:16px">
            <div class="card-header"><div class="card-title"><i class="fas fa-info-circle text-blue"></i> Ringkasan</div></div>
            <div class="card-body">
                <div id="summaryContent" style="color:var(--text3);font-size:13px">
                    Tambahkan item dan tujuan pengiriman terlebih dahulu.
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary w-full btn-lg" style="border-radius:10px">
            <i class="fas fa-save"></i> Simpan Delivery Order
        </button>
    </div>
</div>
</form>

{{-- Hidden: product data for JS --}}
<script id="productData" type="application/json">@json($products)</script>

@endsection

@push('scripts')
<script>
const products  = JSON.parse(document.getElementById('productData').textContent);
let itemCount   = 0;
let shipCount   = 0;

function fmt(n) { return 'Rp ' + Number(n).toLocaleString('id-ID'); }

// ── Customers ────────────────────────────────────────────────
function fillBillingAddress(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('billingAddress').value = opt.dataset.address || '';
}

// ── Order Items ──────────────────────────────────────────────
function addItem(name = '', sku = '', price = 0, qty = 1, pid = '') {
    const idx = itemCount++;
    const opts = products.map(p =>
        `<option value="${p.id}" data-sku="${p.sku||''}" data-price="${p.price||0}" ${p.id==pid?'selected':''}>${p.name}</option>`
    ).join('');

    const row = document.createElement('div');
    row.className = 'item-row';
    row.style = 'display:grid;grid-template-columns:1fr 80px 110px 100px 28px;gap:8px;margin-bottom:8px;align-items:center';
    row.innerHTML = `
        <select name="items[${idx}][product_id]" class="form-control form-select item-product" onchange="onProductChange(this,${idx})" style="font-size:13px">
            <option value="">— Pilih Produk —</option>${opts}
        </select>
        <input type="number" name="items[${idx}][qty]" class="form-control item-qty" value="${qty}"
            min="0.01" step="0.01" style="text-align:right;font-size:13px" oninput="recalcItem(${idx})">
        <input type="number" name="items[${idx}][price]" class="form-control item-price" value="${price}"
            min="0" step="1" style="text-align:right;font-size:13px" oninput="recalcItem(${idx})">
        <input type="text" class="form-control item-subtotal" readonly style="text-align:right;font-size:13px;background:var(--surface2)">
        <button type="button" onclick="this.closest('.item-row').remove(); recalcAll()" style="border:none;background:none;cursor:pointer;color:var(--red);font-size:16px">
            <i class="fas fa-times"></i>
        </button>
        <input type="hidden" name="items[${idx}][product_name]" class="item-name" value="${name}">
        <input type="hidden" name="items[${idx}][product_sku]" class="item-sku" value="${sku}">
    `;
    document.getElementById('itemsContainer').appendChild(row);
    recalcItem(idx);
}

function onProductChange(sel, idx) {
    const opt  = sel.options[sel.selectedIndex];
    const row  = sel.closest('.item-row');
    row.querySelector('.item-price').value = opt.dataset.price || 0;
    row.querySelector('.item-name').value  = opt.text;
    row.querySelector('.item-sku').value   = opt.dataset.sku || '';
    recalcItem(idx);
}

function recalcItem(idx) {
    const rows = document.querySelectorAll('.item-row');
    rows.forEach(row => {
        const price    = parseFloat(row.querySelector('.item-price')?.value || 0);
        const qty      = parseFloat(row.querySelector('.item-qty')?.value || 0);
        const sub      = price * qty;
        const subField = row.querySelector('.item-subtotal');
        if (subField) subField.value = fmt(sub);
    });
    recalcAll();
}

function recalcAll() {
    let total = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const price = parseFloat(row.querySelector('.item-price')?.value || 0);
        const qty   = parseFloat(row.querySelector('.item-qty')?.value || 0);
        total += price * qty;
    });
    document.getElementById('orderTotal').textContent = fmt(total);
    updateSummary();
    refreshShipmentItemOptions();
}

// ── Shipments ────────────────────────────────────────────────
function addShipment() {
    const idx = shipCount++;
    const card = document.createElement('div');
    card.className = 'ship-card';
    card.id = 'ship-' + idx;
    card.innerHTML = `
        <div class="ship-card-header">
            <div class="font-medium" style="font-size:14px"><i class="fas fa-map-marker-alt text-blue"></i> Tujuan ${idx + 1}</div>
            <button type="button" onclick="document.getElementById('ship-${idx}').remove(); updateSummary()" class="btn btn-ghost btn-sm" style="color:var(--red)">
                <i class="fas fa-trash"></i>
            </button>
        </div>
        <div class="grid-2" style="margin-bottom:10px">
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label" style="font-size:12px">Nama Penerima *</label>
                <input type="text" name="shipments[${idx}][recipient_name]" class="form-control" required style="font-size:13px" placeholder="Nama penerima">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label" style="font-size:12px">No. HP</label>
                <input type="text" name="shipments[${idx}][recipient_phone]" class="form-control" style="font-size:13px" placeholder="08xx-xxxx-xxxx">
            </div>
        </div>
        <div class="form-group" style="margin-bottom:10px">
            <label class="form-label" style="font-size:12px">Alamat Pengiriman *</label>
            <textarea name="shipments[${idx}][shipping_address]" class="form-control" rows="2" required style="font-size:13px" placeholder="Alamat lengkap tujuan pengiriman"></textarea>
        </div>
        <div class="form-group" style="margin-bottom:10px">
            <label class="form-label" style="font-size:12px">Catatan Pengiriman</label>
            <input type="text" name="shipments[${idx}][notes]" class="form-control" style="font-size:13px" placeholder="Catatan untuk kurir (opsional)">
        </div>

        <div style="background:var(--surface2);border-radius:8px;padding:12px">
            <div style="font-size:12px;font-weight:700;color:var(--text3);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">
                Item untuk tujuan ini
            </div>
            <div class="ship-item-row" style="font-size:11px;font-weight:700;color:var(--text3);margin-bottom:4px">
                <span>Produk</span><span>Qty</span><span>Harga</span><span>Subtotal</span><span></span>
            </div>
            <div id="ship-items-${idx}"></div>
            <button type="button" onclick="addShipItem(${idx})" class="btn btn-ghost btn-sm" style="margin-top:4px">
                <i class="fas fa-plus"></i> Tambah Item
            </button>
        </div>`;
    document.getElementById('shipmentsContainer').appendChild(card);
    updateSummary();
}

function getOrderItems() {
    const rows = [];
    document.querySelectorAll('.item-row').forEach(row => {
        const name = row.querySelector('.item-name')?.value;
        const sku  = row.querySelector('.item-sku')?.value;
        const price = parseFloat(row.querySelector('.item-price')?.value || 0);
        if (name) rows.push({ name, sku, price });
    });
    return rows;
}

function addShipItem(shipIdx, selectedName = '', qty = 1, price = 0) {
    const container = document.getElementById('ship-items-' + shipIdx);
    const items     = getOrderItems();
    const siIdx     = container.querySelectorAll('.ship-item-row-data').length;

    const opts = items.map(it =>
        `<option value="${it.name}" data-price="${it.price}" data-sku="${it.sku}" ${it.name===selectedName?'selected':''}>${it.name}</option>`
    ).join('');

    const row = document.createElement('div');
    row.className = 'ship-item-row ship-item-row-data';
    row.innerHTML = `
        <select name="shipments[${shipIdx}][items][${siIdx}][product_name]" class="form-control form-select" style="font-size:12px" onchange="onShipItemChange(this)">
            <option value="">— Pilih —</option>${opts}
        </select>
        <input type="number" name="shipments[${shipIdx}][items][${siIdx}][qty]" value="${qty}" min="0" step="0.01"
            class="form-control" style="font-size:12px;text-align:right" oninput="recalcShipItem(this)">
        <input type="number" name="shipments[${shipIdx}][items][${siIdx}][price]" value="${price}" min="0" step="1"
            class="form-control ship-item-price" style="font-size:12px;text-align:right" oninput="recalcShipItem(this)">
        <input type="text" class="form-control" readonly style="font-size:12px;text-align:right;background:var(--surface)">
        <button type="button" onclick="this.closest('.ship-item-row-data').remove()" style="border:none;background:none;cursor:pointer;color:var(--red)">
            <i class="fas fa-times" style="font-size:12px"></i>
        </button>
        <input type="hidden" name="shipments[${shipIdx}][items][${siIdx}][product_sku]" class="ship-item-sku" value="">
    `;
    container.appendChild(row);
    recalcShipItem(row.querySelector('input[type=number]'));
}

function onShipItemChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const row = sel.closest('.ship-item-row-data');
    row.querySelector('.ship-item-price').value = opt.dataset.price || 0;
    row.querySelector('.ship-item-sku').value   = opt.dataset.sku || '';
    recalcShipItem(row.querySelector('input[type=number]'));
}

function recalcShipItem(input) {
    const row  = input.closest('.ship-item-row-data');
    if (!row) return;
    const qty  = parseFloat(row.querySelectorAll('input[type=number]')[0]?.value || 0);
    const price = parseFloat(row.querySelectorAll('input[type=number]')[1]?.value || 0);
    const sub  = row.querySelectorAll('input[type=text]')[0];
    if (sub) sub.value = fmt(qty * price);
}

function refreshShipmentItemOptions() {
    // Refresh product options in existing shipment item rows if needed
}

function updateSummary() {
    const ships = document.querySelectorAll('.ship-card').length;
    const items = document.querySelectorAll('.item-row').length;
    const total = document.getElementById('orderTotal').textContent;

    document.getElementById('summaryContent').innerHTML = `
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px">
            <span class="text-muted">Total Item</span><span class="font-medium">${items} jenis</span>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px">
            <span class="text-muted">Tujuan Kirim</span><span class="font-medium">${ships} alamat</span>
        </div>
        <div style="display:flex;justify-content:space-between;padding-top:8px;border-top:2px solid var(--border);font-size:16px">
            <span class="font-medium">Total Order</span>
            <span class="money font-bold text-blue">${total}</span>
        </div>`;
}

// Init: add 1 item & 1 shipment
addItem();
addShipment();
</script>
@endpush
