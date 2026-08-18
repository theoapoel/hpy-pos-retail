@extends('layouts.app')
@section('title', 'Tukar Barang — Struk ERP HPY')

@section('content')
<div class="page-header">
    <div>
        <div class="page-title"><i class="fas fa-right-left text-blue"></i> Tukar Barang dari Struk ERP HPY</div>
        <div class="page-subtitle">Cari nomor receipt pada struk pelanggan — data diambil langsung dari ERP HPY</div>
    </div>
</div>

<div style="background:var(--surface2);border-radius:10px;padding:10px 14px;margin-bottom:16px;font-size:13px;color:var(--text2)">
    <i class="fas fa-info-circle text-blue"></i>
    Kebijakan toko: <strong>tukar barang, tidak ada uang kembali</strong>. Total barang pengganti harus
    <strong>lebih besar atau sama</strong> dengan nilai barang yang dikembalikan; pelanggan hanya membayar selisihnya.
</div>

{{-- Pencarian invoice --}}
<div class="card" style="margin-bottom:20px">
    <div class="card-body" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="text" id="invoiceNo" class="form-control" style="max-width:360px"
            placeholder="Nomor receipt, mis. POS-KA-INV-2026-14107"
            onkeydown="if(event.key==='Enter') lookupInvoice()">
        <button class="btn btn-primary" id="lookupBtn" onclick="lookupInvoice()"><i class="fas fa-search"></i> Cari di ERP HPY</button>
        <span id="invoiceInfo" style="font-size:13px;color:var(--text3)"></span>
    </div>
</div>

<div id="exchangePanel" style="display:none">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">
        {{-- Barang yang dikembalikan --}}
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-rotate-left" style="color:#EA4335"></i> Barang Dikembalikan</div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Barang</th><th>Harga Satuan</th><th>Sisa Bisa Retur</th><th style="width:110px">Qty Retur</th></tr></thead>
                    <tbody id="erpItemsBody"></tbody>
                </table>
            </div>
        </div>

        {{-- Barang pengganti --}}
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-cart-plus" style="color:#34A853"></i> Barang Pengganti</div>
            </div>
            <div class="card-body">
                <div class="form-group" style="position:relative">
                    <input type="text" id="productSearch" class="form-control" placeholder="Cari nama / SKU / barcode barang pengganti..." autocomplete="off">
                    <div id="searchResults" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:20;background:var(--surface);border:1px solid var(--border);border-radius:8px;max-height:280px;overflow-y:auto;box-shadow:0 8px 20px rgba(0,0,0,.12)"></div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Barang</th><th>Harga</th><th style="width:100px">Qty</th><th></th></tr></thead>
                        <tbody id="newItemsBody">
                            <tr id="emptyRow"><td colspan="4" class="text-muted text-sm" style="text-align:center;padding:16px">Belum ada barang pengganti</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Ringkasan & pembayaran --}}
    <div class="card" style="margin-top:20px">
        <div class="card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">
            <div style="font-size:14px">
                <div style="display:flex;justify-content:space-between;padding:4px 0">
                    <span>Nilai barang dikembalikan</span><strong id="sumReturn" style="color:#EA4335">Rp 0</strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:4px 0">
                    <span>Total barang pengganti</span><strong id="sumNew" style="color:#34A853">Rp 0</strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:6px 0;border-top:1px dashed var(--border);font-size:16px">
                    <span>Selisih dibayar pelanggan</span><strong id="sumDiff">Rp 0</strong>
                </div>
                <div id="diffWarning" style="display:none;margin-top:6px;color:#EA4335;font-size:13px">
                    <i class="fas fa-exclamation-triangle"></i> Total barang pengganti masih di bawah nilai retur — tambah barang.
                </div>
            </div>
            <div>
                <div class="form-group" id="paymentGroup" style="display:none">
                    <label class="form-label">Metode pembayaran selisih</label>
                    <select id="paymentMethod" class="form-control">
                        @foreach($paymentMethods as $m)
                        <option value="{{ $m['mode_of_payment'] }}">{{ $m['mode_of_payment'] }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-primary" id="submitBtn" style="width:100%;padding:12px;font-size:15px" onclick="submitExchange()" disabled>
                    <i class="fas fa-right-left"></i> Proses Tukar Barang
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const fmt = n => 'Rp ' + Math.round(n).toLocaleString('id-ID');
let currentInvoice = null;
let newItems = [];

// ── Lookup invoice di ERP HPY ──────────────────────────────
async function lookupInvoice() {
    const no = document.getElementById('invoiceNo').value.trim();
    if (!no) return;
    const btn = document.getElementById('lookupBtn');
    const info = document.getElementById('invoiceInfo');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Mencari...';
    info.textContent = '';

    try {
        const resp = await fetch('{{ route("exchange.lookup") }}?invoice=' + encodeURIComponent(no), {headers:{'Accept':'application/json'}});
        const data = await resp.json();
        if (!data.success) {
            toast(data.error || 'Invoice tidak ditemukan', 'error');
            document.getElementById('exchangePanel').style.display = 'none';
            currentInvoice = null;
            return;
        }

        currentInvoice = data.invoice;
        info.innerHTML = `<strong>${data.invoice}</strong> &middot; ${data.customer} &middot; ${data.posting_date} &middot; Total ${fmt(data.grand_total)}`
            + (data.consolidated ? ' <span class="badge badge-yellow">sudah masuk Closing Entry — retur kemungkinan ditolak ERP HPY</span>' : '');

        document.getElementById('erpItemsBody').innerHTML = data.items.map(i => `
            <tr>
                <td>
                    <div class="font-medium">${i.item_name}</div>
                    <div class="text-sm text-muted">${i.item_code}</div>
                    ${i.local_missing ? '<div class="text-sm" style="color:#E37400"><i class="fas fa-exclamation-triangle"></i> belum ada di produk lokal — jalankan Pull Produk dulu</div>' : ''}
                </td>
                <td class="money">${fmt(i.rate)}</td>
                <td>${i.remaining} / ${i.qty}</td>
                <td>${(i.remaining > 0 && !i.local_missing)
                    ? `<input type="number" class="form-control return-qty" style="width:90px" min="0" max="${i.remaining}" value="0"
                          data-item-code="${i.item_code}" data-unit="${i.rate}" oninput="recalc()">`
                    : '<span class="badge badge-gray">tidak bisa</span>'}</td>
            </tr>`).join('');

        document.getElementById('exchangePanel').style.display = '';
        recalc();
    } catch (e) {
        toast('Error: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-search"></i> Cari di ERP HPY';
    }
}

// ── Kalkulasi ──────────────────────────────────────────────
function returnTotal() {
    let t = 0;
    document.querySelectorAll('.return-qty').forEach(el => {
        const q = Math.min(parseInt(el.value || 0), parseInt(el.max));
        if (q > 0) t += q * parseFloat(el.dataset.unit);
    });
    return t;
}

function newTotal() {
    return newItems.reduce((t, i) => t + i.quantity * i.price * (1 + (parseFloat(i.tax_rate) || 0) / 100), 0);
}

function recalc() {
    const r = returnTotal(), n = newTotal(), diff = n - r;
    document.getElementById('sumReturn').textContent = fmt(r);
    document.getElementById('sumNew').textContent = fmt(n);
    document.getElementById('sumDiff').textContent = fmt(Math.max(0, diff));
    document.getElementById('diffWarning').style.display = (r > 0 && diff < 0) ? '' : 'none';
    document.getElementById('paymentGroup').style.display = diff > 0 ? '' : 'none';
    document.getElementById('submitBtn').disabled = !(currentInvoice && r > 0 && newItems.length > 0 && diff >= 0);
}

function renderNewItems() {
    const body = document.getElementById('newItemsBody');
    if (!newItems.length) {
        body.innerHTML = '<tr id="emptyRow"><td colspan="4" class="text-muted text-sm" style="text-align:center;padding:16px">Belum ada barang pengganti</td></tr>';
        recalc(); return;
    }
    body.innerHTML = newItems.map((i, idx) => `
        <tr>
            <td><div class="font-medium">${i.name}</div><div class="text-sm text-muted">${i.sku ?? ''}</div></td>
            <td class="money">${fmt(i.price)}</td>
            <td><input type="number" class="form-control" style="width:80px" min="1" value="${i.quantity}"
                onchange="newItems[${idx}].quantity = Math.max(1, parseInt(this.value||1)); renderNewItems()"></td>
            <td><button class="btn btn-ghost btn-sm" onclick="newItems.splice(${idx},1); renderNewItems()" title="Hapus"><i class="fas fa-times" style="color:#EA4335"></i></button></td>
        </tr>`).join('');
    recalc();
}

// ── Pencarian produk pengganti ─────────────────────────────
let searchTimer = null;
const searchBox = document.getElementById('productSearch');
const resultsEl = document.getElementById('searchResults');

searchBox.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const q = searchBox.value.trim();
    if (q.length < 2) { resultsEl.style.display = 'none'; return; }
    searchTimer = setTimeout(async () => {
        const resp = await fetch('{{ route("pos.search-products") }}?q=' + encodeURIComponent(q));
        const products = await resp.json();
        resultsEl.innerHTML = products.length
            ? products.map(p => `
                <div style="padding:8px 12px;cursor:pointer;display:flex;justify-content:space-between;gap:8px;border-bottom:1px solid var(--border)"
                     onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=''"
                     onclick='addProduct(${JSON.stringify({id:p.id,name:p.name,sku:p.sku,price:p.price,tax_rate:p.tax_rate})})'>
                    <span>${p.name} <span class="text-muted text-sm">${p.sku ?? ''}</span></span>
                    <span class="money">${fmt(p.price)}</span>
                </div>`).join('')
            : '<div style="padding:10px 12px;color:var(--text3);font-size:13px">Tidak ada hasil</div>';
        resultsEl.style.display = '';
    }, 250);
});
document.addEventListener('click', e => {
    if (!searchBox.contains(e.target) && !resultsEl.contains(e.target)) resultsEl.style.display = 'none';
});

function addProduct(p) {
    const existing = newItems.find(i => i.product_id === p.id);
    if (existing) existing.quantity++;
    else newItems.push({product_id: p.id, name: p.name, sku: p.sku, price: parseFloat(p.price), tax_rate: p.tax_rate, quantity: 1});
    searchBox.value = '';
    resultsEl.style.display = 'none';
    renderNewItems();
}

// ── Submit ─────────────────────────────────────────────────
async function submitExchange() {
    const btn = document.getElementById('submitBtn');
    const returns = [...document.querySelectorAll('.return-qty')]
        .map(el => ({item_code: el.dataset.itemCode, quantity: parseInt(el.value || 0)}))
        .filter(r => r.quantity > 0);

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Memproses (menunggu ERP HPY)...';

    try {
        const resp = await fetch('{{ route("exchange.store-erp") }}', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'},
            body: JSON.stringify({
                erp_invoice: currentInvoice,
                returns,
                new_items: newItems.map(i => ({product_id: i.product_id, quantity: i.quantity})),
                payment_method: document.getElementById('paymentMethod')?.value ?? '',
            })
        });
        const data = await resp.json();

        if (!data.success) {
            toast(data.error || 'Gagal memproses tukar barang', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-right-left"></i> Proses Tukar Barang';
            return;
        }

        if (data.warning) toast(data.warning, 'warning');
        toast(`Tukar barang selesai: ${data.return_invoice} + ${data.sale_invoice}`, 'success');

        window.open(data.print_url, '_blank');
        setTimeout(() => location.reload(), 1500);
    } catch (e) {
        toast('Error: ' + e.message, 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-right-left"></i> Proses Tukar Barang';
    }
}
</script>
@endpush
