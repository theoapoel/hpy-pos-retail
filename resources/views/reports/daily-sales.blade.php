@extends('layouts.app')
@section('title', 'Daily Sales')

@section('content')
<div class="page-header">
    <div>
        <div class="page-title"><i class="fas fa-chart-line text-blue"></i> Daily Sales</div>
        <div class="page-subtitle">Penjualan harian per brand &amp; item — sumber report "Daily Sales V2" di ERP HPY</div>
    </div>
</div>

{{-- Filter --}}
<div class="card mb-4">
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1.2fr 1.2fr auto;gap:12px;align-items:flex-end">
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Dari Tanggal</label>
                <input type="date" id="dateFrom" class="form-control"
                    value="{{ now()->startOfMonth()->format('Y-m-d') }}">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Sampai Tanggal</label>
                <input type="date" id="dateTo" class="form-control"
                    value="{{ now()->format('Y-m-d') }}">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Brand</label>
                {{-- Brand & group tidak difilter di SQL report-nya; daftar ini terisi dari
                     data yang sudah diambil, jadi ganti filter tidak perlu request ulang. --}}
                <select id="brandFilter" class="form-control" onchange="onBrandChange()" disabled>
                    <option value="">Semua Brand</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Item Group</label>
                <select id="groupFilter" class="form-control" onchange="applyFilter()" disabled>
                    <option value="">Semua Group</option>
                </select>
            </div>
            <button id="btnFetch" onclick="fetchReport()" class="btn btn-primary" style="height:42px;border-radius:8px">
                <i class="fas fa-search"></i> Tampilkan
            </button>
        </div>

        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
            <button onclick="setRange('today')" class="btn btn-ghost btn-sm">Hari Ini</button>
            <button onclick="setRange('yesterday')" class="btn btn-ghost btn-sm">Kemarin</button>
            <button onclick="setRange('week')" class="btn btn-ghost btn-sm">7 Hari Terakhir</button>
            <button onclick="setRange('month')" class="btn btn-ghost btn-sm">Bulan Ini</button>
            <button onclick="setRange('last_month')" class="btn btn-ghost btn-sm">Bulan Lalu</button>
        </div>
    </div>
</div>

{{-- Loading state --}}
<div id="loadingState" style="display:none;text-align:center;padding:60px 0">
    <div style="width:48px;height:48px;border:4px solid #E8F0FE;border-top-color:#4285F4;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 16px"></div>
    <div style="color:#5F6368;font-size:15px">Mengambil data dari ERP HPY...</div>
    <div style="color:#80868B;font-size:13px;margin-top:4px">Rentang panjang bisa memakan puluhan detik</div>
</div>

{{-- Error state --}}
<div id="errorState" style="display:none">
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <span id="errorMsg">Terjadi kesalahan</span>
    </div>
</div>

{{-- Hasil --}}
<div id="resultArea" style="display:none">
    <div id="emptyState" class="card" style="display:none">
        <div class="card-body" style="text-align:center;padding:48px 0;color:#80868B">
            <i class="fas fa-inbox" style="font-size:32px;margin-bottom:12px;display:block"></i>
            Tidak ada penjualan pada rentang tanggal ini.
        </div>
    </div>

    <div id="dataWrap" style="display:none">
        {{-- KPI --}}
        <div class="stat-grid" id="kpiGrid" style="margin-bottom:20px"></div>

        {{-- Rekap harian --}}
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-calendar-day text-blue"></i> Rekap Harian</div>
                <div style="display:flex;gap:8px;align-items:center">
                    <span class="text-sm text-muted" id="rangeLabel"></span>
                    <button class="btn btn-ghost btn-sm" onclick="exportCsv('daily')"><i class="fas fa-file-csv"></i> Export CSV</button>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th style="text-align:right">Nota</th>
                            <th style="text-align:right">Qty</th>
                            <th style="text-align:right">Diskon</th>
                            <th style="text-align:right">Net Sales</th>
                            <th style="text-align:right">COGS</th>
                            <th style="text-align:right">Profit</th>
                            <th style="text-align:right">Margin</th>
                        </tr>
                    </thead>
                    <tbody id="dailyBody"></tbody>
                    <tfoot id="dailyFoot"></tfoot>
                </table>
            </div>
        </div>

        {{-- Rekap per brand --}}
        <div class="card" style="margin-top:20px">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-tags text-blue"></i> Rekap per Brand</div>
                <button class="btn btn-ghost btn-sm" onclick="exportCsv('brand')"><i class="fas fa-file-csv"></i> Export CSV</button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Brand</th>
                            <th style="text-align:right">Qty</th>
                            <th style="text-align:right">Diskon</th>
                            <th style="text-align:right">Net Sales</th>
                            <th style="text-align:right">COGS</th>
                            <th style="text-align:right">Profit</th>
                            <th style="text-align:right">Margin</th>
                            <th style="text-align:right">Kontribusi</th>
                        </tr>
                    </thead>
                    <tbody id="brandBody"></tbody>
                </table>
            </div>
        </div>

        {{-- Detail item --}}
        <div class="card" style="margin-top:20px">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-box text-blue"></i> Detail per Item</div>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="text" id="itemSearch" class="form-control" placeholder="Cari item / barcode / group..."
                        oninput="renderItems()" style="height:34px;min-width:220px;font-size:13px">
                    <button class="btn btn-ghost btn-sm" onclick="exportCsv('item')"><i class="fas fa-file-csv"></i> Export CSV</button>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Brand</th>
                            <th>Group</th>
                            <th style="text-align:right">Qty</th>
                            <th style="text-align:right">Diskon</th>
                            <th style="text-align:right">Net Sales</th>
                            <th style="text-align:right">COGS</th>
                            <th style="text-align:right">Profit</th>
                            <th style="text-align:right">Margin</th>
                        </tr>
                    </thead>
                    <tbody id="itemBody"></tbody>
                </table>
            </div>
            <div class="card-body" style="padding:10px 16px;border-top:1px solid var(--border,#DADCE0)">
                <span class="text-sm text-muted" id="itemNote"></span>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const ITEM_LIMIT = 300;   // tabel item hanya merender sebagian; totalnya tetap dari seluruh data
let raw = null;           // respons mentah dari server (seluruh brand)

function fmt(n)    { return 'Rp ' + Math.round(Number(n || 0)).toLocaleString('id-ID'); }
function num(n)    { return Number(n || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 }); }
function pct(a, b) { return b > 0 ? (a / b * 100).toFixed(1) + '%' : '-'; }
function esc(s)    { return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

function fmtDate(d) {
    const dt = new Date(d + 'T00:00:00');
    return dt.toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
}

function setRange(range) {
    const today = new Date();
    const pad = n => String(n).padStart(2, '0');
    const iso = d => d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate());

    let from, to;
    if (range === 'today') {
        from = to = iso(today);
    } else if (range === 'yesterday') {
        const y = new Date(today); y.setDate(y.getDate()-1);
        from = to = iso(y);
    } else if (range === 'week') {
        const w = new Date(today); w.setDate(w.getDate()-6);
        from = iso(w); to = iso(today);
    } else if (range === 'month') {
        from = iso(new Date(today.getFullYear(), today.getMonth(), 1));
        to   = iso(today);
    } else if (range === 'last_month') {
        const lm = new Date(today.getFullYear(), today.getMonth()-1, 1);
        const le = new Date(today.getFullYear(), today.getMonth(), 0);
        from = iso(lm); to = iso(le);
    }
    document.getElementById('dateFrom').value = from;
    document.getElementById('dateTo').value   = to;
}

async function fetchReport() {
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo   = document.getElementById('dateTo').value;
    if (!dateFrom || !dateTo) { alert('Pilih rentang tanggal terlebih dahulu.'); return; }

    document.getElementById('loadingState').style.display = 'block';
    document.getElementById('resultArea').style.display   = 'none';
    document.getElementById('errorState').style.display   = 'none';

    const btn = document.getElementById('btnFetch');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Mengambil...';

    try {
        const res = await fetch('{{ route("daily-sales.fetch") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ date_from: dateFrom, date_to: dateTo }),
        });
        const json = await res.json();
        if (!json.success) throw new Error(json.error || 'Gagal mengambil data dari ERP HPY.');

        raw = json;
        fillBrandOptions(json.brands || []);
        fillGroupOptions();
        applyFilter();
    } catch (e) {
        document.getElementById('errorMsg').textContent = e.message;
        document.getElementById('errorState').style.display = 'block';
    } finally {
        document.getElementById('loadingState').style.display = 'none';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-search"></i> Tampilkan';
    }
}

function fillBrandOptions(brands) {
    const sel = document.getElementById('brandFilter');
    const prev = sel.value;
    sel.innerHTML = '<option value="">Semua Brand (' + brands.length + ')</option>'
        + brands.map(b => `<option value="${esc(b)}">${esc(b)}</option>`).join('');
    sel.value = brands.includes(prev) ? prev : '';
    sel.disabled = brands.length === 0;
}

/**
 * Isi pilihan item group. Daftarnya mengikuti brand yang sedang dipilih supaya
 * tidak menawarkan group yang pasti kosong; urut dari net terbesar.
 */
function fillGroupOptions() {
    const brand = currentBrand();
    const net = new Map();
    ((raw || {}).daily || []).forEach(d => {
        if (!brand || d.brand === brand) net.set(d.group, (net.get(d.group) || 0) + Number(d.net || 0));
    });
    const groups = [...net.entries()].sort((a, b) => b[1] - a[1]).map(([g]) => g);

    const sel = document.getElementById('groupFilter');
    const prev = sel.value;
    sel.innerHTML = '<option value="">Semua Group (' + groups.length + ')</option>'
        + groups.map(g => `<option value="${esc(g)}">${esc(g)}</option>`).join('');
    sel.value = groups.includes(prev) ? prev : '';
    sel.disabled = groups.length === 0;
}

/** Ganti brand mempersempit daftar group, lalu render ulang. */
function onBrandChange() {
    fillGroupOptions();
    applyFilter();
}

/** Filter aktif ('' = semua). Dipakai semua fungsi render. */
function currentBrand() { return document.getElementById('brandFilter').value; }
function currentGroup() { return document.getElementById('groupFilter').value; }

function applyFilter() {
    if (!raw) return;
    const brand = currentBrand();
    const group = currentGroup();
    const daily = (raw.daily || []).filter(d => (!brand || d.brand === brand) && (!group || d.group === group));

    document.getElementById('resultArea').style.display = 'block';
    document.getElementById('rangeLabel').textContent =
        document.getElementById('dateFrom').value + ' — ' + document.getElementById('dateTo').value
        + (brand ? ' · ' + brand : '') + (group ? ' · ' + group : '');

    if (!daily.length) {
        document.getElementById('emptyState').style.display = 'block';
        document.getElementById('dataWrap').style.display   = 'none';
        return;
    }
    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('dataWrap').style.display   = 'block';

    renderDaily(daily);
    renderKpi(daily);
    renderBrands();
    renderItems();
}

/** Jumlahkan kolom angka dari sekumpulan baris. */
function sum(rows) {
    return rows.reduce((a, r) => ({
        qty:    a.qty    + Number(r.qty    || 0),
        disc:   a.disc   + Number(r.disc   || 0),
        net:    a.net    + Number(r.net    || 0),
        cogs:   a.cogs   + Number(r.cogs   || 0),
        profit: a.profit + Number(r.profit || 0),
    }), { qty: 0, disc: 0, net: 0, cogs: 0, profit: 0 });
}

function renderKpi(daily) {
    const t = sum(daily);
    const brand = currentBrand();
    const group = currentGroup();
    // Tanpa filter, jumlah nota dihitung unik per tanggal. Begitu difilter, satu
    // nota bisa masuk ke beberapa brand/group, jadi angkanya jadi penjumlahan
    // per kombinasi — bisa sedikit lebih besar dari jumlah nota sebenarnya.
    const notes = (brand || group)
        ? daily.reduce((a, d) => a + Number(d.invoices || 0), 0)
        : Object.entries(raw.invoices_per_date || {})
            .filter(([d]) => daily.some(x => x.date === d))
            .reduce((a, [, v]) => a + Number(v), 0);
    const avg = notes > 0 ? t.net / notes : 0;

    const cards = [
        ['Net Sales',  fmt(t.net),    'fa-money-bill-wave', '#34A853', daily.length + ' hari'],
        ['Profit',     fmt(t.profit), 'fa-arrow-trend-up',  '#4285F4', 'Margin ' + pct(t.profit, t.net)],
        ['COGS',       fmt(t.cogs),   'fa-warehouse',       '#FBBC04', 'Diskon ' + fmt(t.disc)],
        ['Qty Terjual', num(t.qty),   'fa-boxes',           '#A142F4', notes.toLocaleString('id-ID') + ' nota'],
        ['Rata-rata Nota', fmt(avg),  'fa-receipt',         '#00ACC1', [brand, group].filter(Boolean).join(' · ') || 'Semua brand'],
    ];
    document.getElementById('kpiGrid').innerHTML = cards.map(([label, value, icon, color, sub]) => `
        <div class="stat-card">
            <div class="stat-icon" style="background:${color}1A;color:${color}"><i class="fas ${icon}"></i></div>
            <div>
                <div class="stat-value money" style="color:${color}">${value}</div>
                <div class="stat-label">${label} · ${esc(sub)}</div>
            </div>
        </div>`).join('');
}

function renderDaily(daily) {
    const filtered = currentBrand() || currentGroup();
    // Gabung baris tanggal×brand×group jadi satu baris per tanggal.
    const byDate = new Map();
    daily.forEach(d => {
        const cur = byDate.get(d.date) || { date: d.date, qty: 0, disc: 0, net: 0, cogs: 0, profit: 0, invoices: 0 };
        cur.qty += Number(d.qty); cur.disc += Number(d.disc); cur.net += Number(d.net);
        cur.cogs += Number(d.cogs); cur.profit += Number(d.profit);
        cur.invoices += Number(d.invoices || 0);
        byDate.set(d.date, cur);
    });
    const rows = [...byDate.values()].sort((a, b) => a.date.localeCompare(b.date));
    rows.forEach(r => { if (!filtered) r.invoices = Number((raw.invoices_per_date || {})[r.date] || r.invoices); });

    document.getElementById('dailyBody').innerHTML = rows.map(r => `
        <tr>
            <td><span class="font-medium">${fmtDate(r.date)}</span></td>
            <td style="text-align:right" class="text-muted">${num(r.invoices)}</td>
            <td style="text-align:right">${num(r.qty)}</td>
            <td style="text-align:right" class="money">${r.disc ? fmt(r.disc) : '<span class="text-muted">-</span>'}</td>
            <td style="text-align:right" class="money font-medium">${fmt(r.net)}</td>
            <td style="text-align:right" class="money text-muted">${fmt(r.cogs)}</td>
            <td style="text-align:right;color:#34A853" class="money">${fmt(r.profit)}</td>
            <td style="text-align:right">${pct(r.profit, r.net)}</td>
        </tr>`).join('');

    const t = sum(rows);
    const notes = rows.reduce((a, r) => a + Number(r.invoices || 0), 0);
    document.getElementById('dailyFoot').innerHTML = `
        <tr style="border-top:2px solid #DADCE0;font-weight:600">
            <td>Total</td>
            <td style="text-align:right">${num(notes)}</td>
            <td style="text-align:right">${num(t.qty)}</td>
            <td style="text-align:right" class="money">${fmt(t.disc)}</td>
            <td style="text-align:right" class="money text-blue">${fmt(t.net)}</td>
            <td style="text-align:right" class="money">${fmt(t.cogs)}</td>
            <td style="text-align:right" class="money">${fmt(t.profit)}</td>
            <td style="text-align:right">${pct(t.profit, t.net)}</td>
        </tr>`;
}

function renderBrands() {
    // Selalu tampilkan seluruh brand supaya porsinya tetap terlihat (tapi tetap
    // ikut filter item group); brand yang sedang difilter disorot.
    const active = currentBrand();
    const group = currentGroup();
    const byBrand = new Map();
    (raw.daily || []).filter(d => !group || d.group === group).forEach(d => {
        const cur = byBrand.get(d.brand) || { brand: d.brand, qty: 0, disc: 0, net: 0, cogs: 0, profit: 0 };
        cur.qty += Number(d.qty); cur.disc += Number(d.disc); cur.net += Number(d.net);
        cur.cogs += Number(d.cogs); cur.profit += Number(d.profit);
        byBrand.set(d.brand, cur);
    });
    const rows = [...byBrand.values()].sort((a, b) => b.net - a.net);
    const grand = rows.reduce((a, r) => a + r.net, 0);

    document.getElementById('brandBody').innerHTML = rows.map(r => `
        <tr style="${active === r.brand ? 'background:#E8F0FE' : ''}">
            <td>
                <a href="javascript:void(0)" data-brand="${esc(r.brand)}" onclick="pickBrand(this.dataset.brand)"
                   class="font-medium" style="color:#4285F4;text-decoration:none">${esc(r.brand)}</a>
            </td>
            <td style="text-align:right">${num(r.qty)}</td>
            <td style="text-align:right" class="money">${r.disc ? fmt(r.disc) : '<span class="text-muted">-</span>'}</td>
            <td style="text-align:right" class="money font-medium">${fmt(r.net)}</td>
            <td style="text-align:right" class="money text-muted">${fmt(r.cogs)}</td>
            <td style="text-align:right" class="money">${fmt(r.profit)}</td>
            <td style="text-align:right">${pct(r.profit, r.net)}</td>
            <td style="text-align:right">${pct(r.net, grand)}</td>
        </tr>`).join('');
}

/** Klik nama brand di tabel = pasang filternya (klik lagi untuk melepas). */
function pickBrand(brand) {
    const sel = document.getElementById('brandFilter');
    sel.value = sel.value === brand ? '' : brand;
    onBrandChange();
}

function filteredItems() {
    const brand = currentBrand();
    const group = currentGroup();
    const q = (document.getElementById('itemSearch').value || '').toLowerCase().trim();
    return (raw.items || []).filter(it =>
        (!brand || it.brand === brand) &&
        (!group || it.group === group) &&
        (!q || (it.item_name + ' ' + it.barcode + ' ' + it.group + ' ' + it.supplier).toLowerCase().includes(q))
    );
}

function renderItems() {
    const items = filteredItems();
    const shown = items.slice(0, ITEM_LIMIT);

    document.getElementById('itemBody').innerHTML = shown.map(it => `
        <tr>
            <td>
                <div class="font-medium">${esc(it.item_name)}</div>
                <div class="text-sm text-muted" style="font-family:monospace">${esc(it.barcode)} · ${esc(it.supplier)}</div>
            </td>
            <td class="text-sm">${esc(it.brand)}</td>
            <td class="text-sm text-muted">${esc(it.group)}</td>
            <td style="text-align:right">${num(it.qty)}</td>
            <td style="text-align:right" class="money">${it.disc ? fmt(it.disc) : '<span class="text-muted">-</span>'}</td>
            <td style="text-align:right" class="money font-medium">${fmt(it.net)}</td>
            <td style="text-align:right" class="money text-muted">${fmt(it.cogs)}</td>
            <td style="text-align:right" class="money">${fmt(it.profit)}</td>
            <td style="text-align:right">${pct(it.profit, it.net)}</td>
        </tr>`).join('') ||
        '<tr><td colspan="9" style="text-align:center;padding:16px" class="text-muted">Tidak ada item yang cocok</td></tr>';

    const t = sum(items);
    document.getElementById('itemNote').textContent =
        `Menampilkan ${shown.length.toLocaleString('id-ID')} dari ${items.length.toLocaleString('id-ID')} item`
        + ` · total qty ${num(t.qty)} · net ${fmt(t.net)}`
        + (items.length > shown.length ? ' (urut dari net terbesar — pakai pencarian atau export CSV untuk sisanya)' : '');
}

function exportCsv(kind) {
    if (!raw) return;
    const sep = ';';
    const q = v => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const brand = currentBrand();
    const group = currentGroup();
    const lines = [];
    let name = kind;

    if (kind === 'daily') {
        lines.push(['Tanggal', 'Brand', 'Group', 'Nota', 'Qty', 'Diskon', 'Net Sales', 'COGS', 'Profit'].map(q).join(sep));
        (raw.daily || []).filter(d => (!brand || d.brand === brand) && (!group || d.group === group))
            .forEach(d => lines.push([d.date, d.brand, d.group, d.invoices, d.qty, d.disc, d.net, d.cogs, d.profit].map(q).join(sep)));
    } else if (kind === 'brand') {
        const byBrand = new Map();
        (raw.daily || []).filter(d => !group || d.group === group).forEach(d => {
            const c = byBrand.get(d.brand) || { qty: 0, disc: 0, net: 0, cogs: 0, profit: 0 };
            c.qty += Number(d.qty); c.disc += Number(d.disc); c.net += Number(d.net);
            c.cogs += Number(d.cogs); c.profit += Number(d.profit);
            byBrand.set(d.brand, c);
        });
        lines.push(['Brand', 'Qty', 'Diskon', 'Net Sales', 'COGS', 'Profit'].map(q).join(sep));
        [...byBrand.entries()].sort((a, b) => b[1].net - a[1].net)
            .forEach(([b, c]) => lines.push([b, c.qty, c.disc, c.net, c.cogs, c.profit].map(q).join(sep)));
    } else {
        lines.push(['Barcode', 'Item', 'Brand', 'Group', 'Supplier', 'Qty', 'Diskon', 'Net Sales', 'COGS', 'Profit'].map(q).join(sep));
        filteredItems().forEach(it => lines.push(
            [it.barcode, it.item_name, it.brand, it.group, it.supplier, it.qty, it.disc, it.net, it.cogs, it.profit].map(q).join(sep)));
        name = 'item';
    }

    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const tag = [brand, group].filter(Boolean).map(s => '-' + s.replace(/\s+/g, '_')).join('');
    a.download = `daily-sales-${name}${tag}`
        + `_${document.getElementById('dateFrom').value}_${document.getElementById('dateTo').value}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}
</script>
@endpush
