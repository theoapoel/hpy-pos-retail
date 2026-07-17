@extends('layouts.app')
@section('title', 'Buat Slice')

@push('styles')
<style>
.item-row { animation: fadeIn .2s ease; }
@keyframes fadeIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }
.slice-grid { display:grid; grid-template-columns:1.8fr 80px 26px 1.8fr 80px 1fr 28px; gap:8px; align-items:center; margin-bottom:8px; }
.slice-arrow { text-align:center; color:var(--text3); font-size:14px; }

.product-search-wrap { position:relative; }
.product-dropdown {
    display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:20;
    background:var(--surface); border:1px solid var(--border); border-radius:8px;
    max-height:260px; overflow-y:auto; box-shadow:0 8px 24px rgba(0,0,0,.12);
}
.product-dropdown.open { display:block; }
.product-dropdown-item { padding:8px 12px; cursor:pointer; font-size:13px; }
.product-dropdown-item:hover, .product-dropdown-item.active { background:var(--surface2); }
.product-dropdown-item .pname { font-weight:500; color:var(--text); }
.product-dropdown-item .pmeta { font-size:11px; color:var(--text3); margin-top:1px; }
.product-search.invalid { border-color:var(--red); }
</style>
@endpush

@section('content')
<div class="page-header">
    <div>
        <div class="page-title"><i class="fas fa-scissors text-blue"></i> Buat Slice</div>
        <div class="page-subtitle">Konversi qty item — item sumber diissue, item hasil diterima di ERP HPY (Repack)</div>
    </div>
    <a href="{{ route('slices.index') }}" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Kembali</a>
</div>

<form id="sliceForm" method="POST" action="{{ route('slices.store') }}">
@csrf
<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">

    <div>
        {{-- Konversi --}}
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-scissors text-blue"></i> Konversi Item</div>
                <button type="button" onclick="addItem()" class="btn btn-outline btn-sm">
                    <i class="fas fa-plus"></i> Tambah Baris
                </button>
            </div>
            <div class="card-body" style="padding:0">
                <div class="slice-grid" style="padding:10px 16px;background:var(--surface2);font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px">
                    <span>Item Sumber (dipotong)</span><span>Qty</span><span></span><span>Jadi Item</span><span>Qty</span><span>Catatan</span><span></span>
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
                <div class="form-group">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="notes" class="form-control"
                        placeholder="Keterangan (opsional)" value="{{ old('notes') }}">
                </div>
                <div id="summaryContent" style="font-size:13px;color:var(--text3)">
                    Tambahkan konversi terlebih dahulu.
                </div>
            </div>
        </div>
        <div class="alert alert-info" style="margin-bottom:12px;font-size:13px">
            <i class="fas fa-info-circle"></i>
            Setelah disimpan, klik <strong>Submit ke ERP</strong> untuk membentuk Stock Entry (Repack): item sumber keluar, item hasil masuk.
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

function productField(idx, role) {
    // role = 'source' | 'target'
    return `
        <div class="product-search-wrap">
            <input type="text" class="form-control product-search ${role}-search" style="font-size:13px"
                placeholder="Ketik nama / SKU..." autocomplete="off"
                oninput="onSearch(this,${idx},'${role}')"
                onfocus="onSearch(this,${idx},'${role}')"
                onblur="setTimeout(() => closeDropdown(${idx},'${role}'), 150)">
            <input type="hidden" name="items[${idx}][${role}_product_id]" class="${role}-id" value="">
            <div class="product-dropdown" id="dropdown_${role}_${idx}"></div>
        </div>`;
}

function addItem() {
    const idx = itemCount++;
    const row = document.createElement('div');
    row.className = 'item-row slice-grid';
    row.innerHTML = `
        ${productField(idx, 'source')}
        <input type="number" name="items[${idx}][source_qty]" class="form-control source-qty" value="1"
            min="0.01" step="0.01" style="text-align:right;font-size:13px" required oninput="updateSummary()">
        <div class="slice-arrow"><i class="fas fa-arrow-right"></i></div>
        ${productField(idx, 'target')}
        <input type="number" name="items[${idx}][target_qty]" class="form-control target-qty" value="1"
            min="0.01" step="0.01" style="text-align:right;font-size:13px" required oninput="updateSummary()">
        <input type="text" name="items[${idx}][notes]" class="form-control" placeholder="Catatan..." style="font-size:13px">
        <button type="button" onclick="this.closest('.item-row').remove(); updateSummary()"
            style="border:none;background:none;cursor:pointer;color:var(--red);font-size:16px">
            <i class="fas fa-times"></i>
        </button>
    `;
    document.getElementById('itemsContainer').appendChild(row);
}

function onSearch(input, idx, role) {
    const row = input.closest('.item-row');
    const dropdown = document.getElementById(`dropdown_${role}_${idx}`);
    const q = input.value.trim().toLowerCase();

    // Mengetik ulang membatalkan pilihan sampai user memilih lagi
    row.querySelector(`.${role}-id`).value = '';
    input.classList.remove('invalid');

    const filtered = (q
        ? products.filter(p => p.name.toLowerCase().includes(q) || (p.sku && p.sku.toLowerCase().includes(q)))
        : products
    ).slice(0, 30);

    dropdown.innerHTML = filtered.length
        ? filtered.map(p => `
            <div class="product-dropdown-item" onmousedown="selectProduct(event,${idx},'${role}',${p.id})">
                <div class="pname">${p.name}</div>
                <div class="pmeta">${p.sku ? 'SKU: ' + p.sku + ' · ' : ''}${p.unit || 'Nos'}</div>
            </div>`).join('')
        : '<div class="product-dropdown-item" style="color:var(--text3);text-align:center">Produk tidak ditemukan</div>';

    dropdown.classList.add('open');
}

function selectProduct(e, idx, role, pid) {
    e.preventDefault();
    const product = products.find(p => p.id == pid);
    if (!product) return;

    const row = document.getElementById(`dropdown_${role}_${idx}`).closest('.item-row');
    const search = row.querySelector(`.${role}-search`);
    search.value = product.name;
    search.classList.remove('invalid');
    row.querySelector(`.${role}-id`).value = product.id;

    closeDropdown(idx, role);
    updateSummary();
}

function closeDropdown(idx, role) {
    document.getElementById(`dropdown_${role}_${idx}`)?.classList.remove('open');
}

function updateSummary() {
    const rows = document.querySelectorAll('.item-row');
    let html = '';
    let count = 0;
    rows.forEach(row => {
        const sid = row.querySelector('.source-id')?.value;
        const tid = row.querySelector('.target-id')?.value;
        if (!sid || !tid) return;
        count++;
        const sName = row.querySelector('.source-search')?.value || '—';
        const tName = row.querySelector('.target-search')?.value || '—';
        const sQty  = parseFloat(row.querySelector('.source-qty')?.value || '0').toLocaleString('id-ID');
        const tQty  = parseFloat(row.querySelector('.target-qty')?.value || '0').toLocaleString('id-ID');
        html += `<div style="font-size:13px;padding:6px 0;border-bottom:1px solid var(--border)">
            <span style="color:var(--text2)">${sQty} ${sName}</span>
            <i class="fas fa-arrow-right" style="margin:0 6px;color:var(--text3);font-size:11px"></i>
            <span class="font-medium">${tQty} ${tName}</span>
        </div>`;
    });
    document.getElementById('summaryContent').innerHTML = count
        ? `<div style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">${count} Konversi</div>` + html
        : '<span style="color:var(--text3)">Lengkapi item sumber & hasil.</span>';
}

document.getElementById('sliceForm').addEventListener('submit', function (e) {
    let firstInvalid = null;
    const rows = document.querySelectorAll('.item-row');
    if (rows.length === 0) {
        e.preventDefault();
        toast('Tambahkan minimal satu baris konversi.', 'error');
        return;
    }
    rows.forEach(row => {
        ['source', 'target'].forEach(role => {
            const search = row.querySelector(`.${role}-search`);
            const id     = row.querySelector(`.${role}-id`);
            if (!id.value) {
                search.classList.add('invalid');
                if (!firstInvalid) firstInvalid = search;
            }
        });
    });
    if (firstInvalid) {
        e.preventDefault();
        toast('Pilih item sumber & hasil yang valid dari daftar untuk semua baris.', 'error');
        firstInvalid.focus();
    }
});

addItem();
</script>
@endpush
