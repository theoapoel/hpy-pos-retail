@extends('layouts.app')
@section('title', 'Buat Permintaan Finish Goods')

@push('styles')
<style>
.item-row { animation: fadeIn .2s ease; }
@keyframes fadeIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }
.item-grid { display:grid; grid-template-columns:2fr 90px 90px 1fr 28px; gap:8px; align-items:center; margin-bottom:8px; }
</style>
@endpush

@section('content')
<div class="page-header">
    <div>
        <div class="page-title"><i class="fas fa-plus text-blue"></i> Permintaan Finish Goods</div>
        <div class="page-subtitle">Ajukan permintaan produksi produk jadi untuk kebutuhan stok</div>
    </div>
    <a href="{{ route('stock-requests.index') }}" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Kembali</a>
</div>

<form id="srForm" method="POST" action="{{ route('stock-requests.store') }}">
@csrf
<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">

    <div>
        {{-- Info --}}
        <div class="card" style="margin-bottom:16px">
            <div class="card-header"><div class="card-title"><i class="fas fa-info-circle text-blue"></i> Informasi Permintaan</div></div>
            <div class="card-body">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Tanggal Dibutuhkan <span style="color:var(--red)">*</span></label>
                        <input type="date" name="needed_date" class="form-control" required
                            min="{{ now()->format('Y-m-d') }}" value="{{ old('needed_date', now()->format('Y-m-d')) }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Catatan</label>
                        <input type="text" name="notes" class="form-control"
                            placeholder="Keterangan tambahan (opsional)" value="{{ old('notes') }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- Items --}}
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-box text-blue"></i> Produk yang Diminta</div>
                <button type="button" onclick="addItem()" class="btn btn-outline btn-sm">
                    <i class="fas fa-plus"></i> Tambah Produk
                </button>
            </div>
            <div class="card-body" style="padding:0">
                <div class="item-grid" style="padding:10px 16px;background:var(--surface2);font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px">
                    <span>Produk / Finish Goods</span><span>Qty</span><span>Satuan</span><span>Catatan</span><span></span>
                </div>
                <div id="itemsContainer" style="padding:12px 16px"></div>
            </div>
        </div>
    </div>

    {{-- Ringkasan --}}
    <div style="position:sticky;top:80px">
        <div class="card" style="margin-bottom:16px">
            <div class="card-header"><div class="card-title"><i class="fas fa-info-circle text-blue"></i> Ringkasan</div></div>
            <div class="card-body">
                <div id="summaryContent" style="font-size:13px;color:var(--text3)">
                    Tambahkan produk terlebih dahulu.
                </div>
            </div>
        </div>
        <div class="alert alert-info" style="margin-bottom:12px;font-size:13px">
            <i class="fas fa-info-circle"></i>
            Setelah disimpan, klik <strong>Ajukan</strong> untuk mengirim ke dapur dan sinkronisasi ke ERP sebagai Material Request.
        </div>
        <button type="submit" class="btn btn-primary w-full btn-lg" style="border-radius:10px">
            <i class="fas fa-save"></i> Simpan sebagai Draft
        </button>
    </div>
</div>
</form>

<script id="productData" type="application/json">@json($products)</script>
@endsection

@push('scripts')
<script>
const products = JSON.parse(document.getElementById('productData').textContent);
let itemCount  = 0;

const uomOptions = ['Nos','Pcs','Box','Pack','Kg','Gram','Liter','ml','Lusin','Botol','Kaleng','Karung','Sak']
    .map(u => `<option value="${u}">${u}</option>`).join('');

function addItem(pid = '', qty = 1, uom = 'Nos', notes = '') {
    const idx  = itemCount++;
    const opts = products.map(p =>
        `<option value="${p.id}" data-name="${p.name}" data-uom="${p.unit||'Nos'}" data-sku="${p.sku||''}" ${p.id==pid?'selected':''}>${p.name}</option>`
    ).join('');

    const row = document.createElement('div');
    row.className = 'item-row item-grid';
    row.innerHTML = `
        <select name="items[${idx}][product_id]" class="form-control form-select item-product"
            style="font-size:13px" onchange="onProductChange(this,${idx})" required>
            <option value="">— Pilih Produk —</option>${opts}
        </select>
        <input type="number" name="items[${idx}][qty]" class="form-control item-qty" value="${qty}"
            min="0.01" step="0.01" style="text-align:right;font-size:13px" required oninput="updateSummary()">
        <select name="items[${idx}][uom]" class="form-control form-select item-uom" style="font-size:13px">
            ${uomOptions.replace(`>${uom}<`, ` selected>${uom}<`)}
        </select>
        <input type="text" name="items[${idx}][notes]" class="form-control" value="${notes}"
            placeholder="Catatan..." style="font-size:13px">
        <button type="button" onclick="this.closest('.item-row').remove(); updateSummary()"
            style="border:none;background:none;cursor:pointer;color:var(--red);font-size:16px">
            <i class="fas fa-times"></i>
        </button>
        <input type="hidden" name="items[${idx}][item_name]" class="item-name" value="">
    `;
    document.getElementById('itemsContainer').appendChild(row);
    if (pid) updateSummary();
}

function onProductChange(sel, idx) {
    const opt = sel.options[sel.selectedIndex];
    const row = sel.closest('.item-row');
    row.querySelector('.item-name').value = opt.text || '';
    if (opt.dataset.uom) {
        const uomSel = row.querySelector('.item-uom');
        for (let o of uomSel.options) {
            if (o.value === opt.dataset.uom) { o.selected = true; break; }
        }
    }
    updateSummary();
}

function updateSummary() {
    const rows = document.querySelectorAll('.item-row');
    if (rows.length === 0) {
        document.getElementById('summaryContent').innerHTML =
            '<span style="color:var(--text3)">Tambahkan produk terlebih dahulu.</span>';
        return;
    }
    let html = '';
    rows.forEach(row => {
        const sel  = row.querySelector('.item-product');
        const name = sel?.options[sel.selectedIndex]?.text || '—';
        const qty  = row.querySelector('.item-qty')?.value || '0';
        const uom  = row.querySelector('.item-uom')?.value || '';
        if (sel?.value) {
            html += `<div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-bottom:1px solid var(--border)">
                <span style="color:var(--text2)">${name}</span>
                <span class="font-medium">${parseFloat(qty).toLocaleString('id-ID')} ${uom}</span>
            </div>`;
        }
    });
    const filled = rows.length;
    document.getElementById('summaryContent').innerHTML =
        `<div style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">${filled} Produk</div>` +
        (html || '<span style="color:var(--text3)">Pilih produk terlebih dahulu.</span>');
}

addItem();
</script>
@endpush
