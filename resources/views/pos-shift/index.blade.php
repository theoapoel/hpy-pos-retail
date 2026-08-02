@extends('layouts.app')
@section('title', 'Shift Kasir')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="fas fa-cash-register" style="color:var(--blue);margin-right:8px;font-size:22px;vertical-align:-2px;"></i>
            Shift Kasir
        </h1>
        <p class="page-subtitle">Buka/Tutup Kasir — POS Opening &amp; Closing Entry di ERP HPY</p>
    </div>
</div>

@if(!$enabled)
<div class="card" style="padding:20px;margin-bottom:20px;border-left:4px solid var(--yellow, #E37400)">
    <div style="font-weight:700;margin-bottom:4px"><i class="fas fa-power-off" style="color:#E37400"></i> Fitur Shift Kasir nonaktif</div>
    <div style="font-size:13px;color:var(--text2)">
        Aktifkan lewat toggle <strong>Buka/Tutup Kasir (Shift)</strong> di halaman
        <a href="{{ route('sync.index') }}">Sync HPY</a> → Pengaturan ERP.
    </div>
</div>
@endif

{{-- ===== Panel shift saya ===== --}}
<div class="card" style="padding:20px;margin-bottom:20px">
    @if($shift)
        @if($shift->isStale())
        <div style="background:#FEF3E2;color:#B06000;border-left:4px solid var(--yellow);border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px">
            <strong><i class="fas fa-triangle-exclamation"></i> Shift terlantar.</strong>
            Dibuka {{ $shift->opened_at->diffForHumans() }} dan belum ditutup di ERP HPY. Menutupnya sekarang akan
            merekonsiliasi <strong>seluruh POS Invoice sejak {{ $shift->opened_at->isoFormat('D MMM YYYY') }}</strong>,
            bukan hanya hari ini. Periksa dulu di ERP sebelum menutup.
        </div>
        @endif
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
            <div>
                <span class="badge badge-green">Shift Terbuka</span>
                @if(!$shift->erp_opening_entry)
                    <span class="badge badge-red" title="{{ $shift->erp_sync_error }}">Belum tercatat di ERP</span>
                @endif
                <div style="margin-top:8px;font-size:13px;color:var(--text2)">
                    Dibuka {{ $shift->opened_at->timezone(config('app.timezone'))->isoFormat('D MMM YYYY HH:mm') }}
                    &middot; Modal kas awal <strong>Rp {{ number_format($shift->opening_cash, 0, ',', '.') }}</strong>
                    @if($shift->erp_opening_entry)
                        &middot; <span style="font-family:monospace;font-size:12px">{{ $shift->erp_opening_entry }}</span>
                    @endif
                </div>
            </div>
            <button class="btn btn-danger" onclick="showCloseShift()" @disabled(!$enabled)>
                <i class="fas fa-lock"></i> Tutup Kasir
            </button>
        </div>
    @else
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
            <div>
                <span class="badge badge-yellow">Belum Buka Kasir</span>
                <div style="margin-top:8px;font-size:13px;color:var(--text2)">
                    @if($lastClosed && $lastClosed->closed_at)
                        Shift terakhir ditutup {{ $lastClosed->closed_at->isoFormat('D MMM YYYY HH:mm') }}.
                    @else
                        Belum ada shift yang pernah dibuka dengan akun ini.
                    @endif
                </div>
            </div>
            <button class="btn btn-primary" onclick="showOpenShift()" @disabled(!$enabled)>
                <i class="fas fa-unlock"></i> Buka Kasir
            </button>
        </div>
    @endif
</div>

{{-- ===== Riwayat (admin/manager) ===== --}}
@if($isManager)
<div class="stat-grid" style="margin-bottom:20px">
    <div class="stat-card">
        <div>
            <div style="font-size:12px;color:var(--text3)">Jumlah Shift</div>
            <div style="font-size:22px;font-weight:800">{{ $summary['count'] }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div>
            <div style="font-size:12px;color:var(--text3)">Total Penjualan</div>
            <div style="font-size:22px;font-weight:800">Rp {{ number_format($summary['sales'], 0, ',', '.') }}</div>
        </div>
    </div>
    <div class="stat-card">
        <div>
            <div style="font-size:12px;color:var(--text3)">Total Selisih Kas</div>
            <div style="font-size:22px;font-weight:800;color:{{ $summary['difference'] < 0 ? 'var(--red)' : 'var(--text)' }}">
                {{ $summary['difference'] < 0 ? '-' : '' }}Rp {{ number_format(abs($summary['difference']), 0, ',', '.') }}
            </div>
        </div>
    </div>
</div>

<div class="card">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:16px 20px;border-bottom:1px solid var(--border)">
        <div class="form-group" style="margin:0">
            <label class="form-label">Dari</label>
            <input type="date" name="from" class="form-control" value="{{ request('from', now()->subDays(29)->format('Y-m-d')) }}">
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">Sampai</label>
            <input type="date" name="to" class="form-control" value="{{ request('to', now()->format('Y-m-d')) }}">
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">Kasir</label>
            <select name="user_id" class="form-control">
                <option value="">Semua kasir</option>
                @foreach($cashiers as $c)
                    <option value="{{ $c->id }}" @selected(request('user_id') == $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="">Semua</option>
                <option value="open" @selected(request('status') === 'open')>Terbuka</option>
                <option value="closed" @selected(request('status') === 'closed')>Ditutup</option>
            </select>
        </div>
        <button class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
        <a href="{{ route('pos-shift.index') }}" class="btn btn-ghost">Reset</a>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Buka</th>
                    <th>Tutup</th>
                    <th>Kasir</th>
                    <th>Status</th>
                    <th style="text-align:right">Modal Awal</th>
                    <th style="text-align:right">Penjualan</th>
                    <th style="text-align:right">Kas Sistem</th>
                    <th style="text-align:right">Kas Hitung</th>
                    <th style="text-align:right">Selisih</th>
                    <th>Entry ERP</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($shifts as $s)
                <tr>
                    <td style="font-weight:500;white-space:nowrap">{{ $s->opened_at->isoFormat('D/M/YY HH:mm') }}</td>
                    <td style="white-space:nowrap;color:var(--text2)">{{ optional($s->closed_at)->isoFormat('D/M/YY HH:mm') ?? '—' }}</td>
                    <td style="font-size:13px">{{ $s->user?->name ?? '—' }}</td>
                    <td>
                        @if($s->status === 'open')
                            <span class="badge badge-yellow">Terbuka</span>
                        @else
                            <span class="badge badge-green">Ditutup</span>
                        @endif
                    </td>
                    <td style="text-align:right">{{ number_format($s->opening_cash, 0, ',', '.') }}</td>
                    <td style="text-align:right">{{ number_format($s->total_sales, 0, ',', '.') }}</td>
                    <td style="text-align:right;color:var(--text2)">{{ number_format($s->expected_cash, 0, ',', '.') }}</td>
                    <td style="text-align:right;color:var(--text2)">{{ number_format($s->counted_cash, 0, ',', '.') }}</td>
                    <td style="text-align:right;color:{{ $s->cash_difference < 0 ? 'var(--red)' : ($s->cash_difference > 0 ? 'var(--blue)' : 'var(--text3)') }}">
                        {{ $s->cash_difference < 0 ? '-' : '' }}{{ number_format(abs($s->cash_difference), 0, ',', '.') }}
                    </td>
                    <td style="font-size:11px;font-family:monospace;color:var(--text2)">
                        @if($s->erp_opening_entry)<div>{{ $s->erp_opening_entry }}</div>@endif
                        @if($s->erp_closing_entry)<div>{{ $s->erp_closing_entry }}</div>@endif
                        @if(!$s->erp_opening_entry && !$s->erp_closing_entry)<span style="color:var(--text3)">—</span>@endif
                    </td>
                    <td>
                        @if($s->status === 'closed')
                        <a href="{{ route('pos-shift.receipt', $s) }}" target="_blank" class="btn btn-ghost" style="font-size:13px">
                            <i class="fas fa-print"></i> Struk
                        </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" style="text-align:center;padding:48px;color:var(--text2)">Belum ada shift pada rentang ini.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($shifts->hasPages())
    <div style="padding:16px 20px;border-top:1px solid var(--border)">{{ $shifts->links() }}</div>
    @endif
</div>

{{-- ===== Daftar POS Opening & Closing Entry langsung dari ERP ===== --}}
<div class="card" style="margin-top:20px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:16px 20px;border-bottom:1px solid var(--border)">
        <div>
            <div style="font-weight:700;font-size:15px">
                <i class="fas fa-server" style="color:var(--blue)"></i> POS Opening &amp; Closing Entry di ERP HPY
            </div>
            <div style="font-size:12px;color:var(--text3);margin-top:2px">
                Ditarik langsung dari ERP — termasuk shift yang dibuat di luar aplikasi ini. Mengikuti filter tanggal &amp; kasir di atas.
            </div>
        </div>
        <button class="btn btn-ghost" id="btnLoadErpEntries" onclick="loadErpEntries()">
            <i class="fas fa-cloud-download-alt"></i> Muat dari ERP
        </button>
    </div>
    <div id="erpEntriesBody" style="padding:20px;color:var(--text3);font-size:13px">
        Klik <strong>Muat dari ERP</strong> untuk menarik daftarnya.
    </div>
</div>
@endif

{{-- ===== Modal: Buka Kasir ===== --}}
<div class="modal-overlay" id="openShiftModal">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-cash-register" style="color:var(--blue)"></i> Buka Kasir</div>
            <button onclick="hideModal('openShiftModal')" style="background:none;border:none;cursor:pointer;font-size:22px;color:var(--text3)">&times;</button>
        </div>
        <div class="modal-body">
            <p style="color:var(--text2);font-size:13px;margin-bottom:14px">Masukkan modal kas awal (uang di laci) untuk memulai shift.</p>
            <label class="form-label">Modal Kas Awal (Rp)</label>
            <input type="number" id="openingCashInput" class="form-control" value="0" min="0" step="1000" style="font-size:20px;text-align:right">
            <p style="font-size:11px;color:var(--text3);margin-top:10px">
                <i class="fas fa-wifi"></i> Tanpa internet kasir tetap bisa dibuka; pencatatan ke ERP HPY disusulkan otomatis saat online.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="hideModal('openShiftModal')">Batal</button>
            <button class="btn btn-primary" id="btnDoOpenShift" onclick="doOpenShift()"><i class="fas fa-unlock"></i> Buka Kasir</button>
        </div>
    </div>
</div>

{{-- ===== Modal: Tutup Kasir ===== --}}
<div class="modal-overlay" id="closeShiftModal">
    <div class="modal" style="max-width:560px">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-cash-register" style="color:var(--red)"></i> Tutup Kasir</div>
            <button onclick="hideModal('closeShiftModal')" style="background:none;border:none;cursor:pointer;font-size:22px;color:var(--text3)">&times;</button>
        </div>
        <div class="modal-body" id="closeShiftBody" style="max-height:60vh;overflow-y:auto">
            <div style="text-align:center;color:var(--text3);padding:20px">Memuat rekonsiliasi…</div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="hideModal('closeShiftModal')">Batal</button>
            <button class="btn btn-primary" id="btnDoCloseShift" onclick="doCloseShift()" disabled>
                <i class="fas fa-lock"></i> Tutup &amp; Cetak
            </button>
        </div>
    </div>
</div>

<script>
// ============================================================
// SHIFT KASIR — halaman menu (POS Opening/Closing Entry)
// Endpoint sama dengan modal di halaman POS (resources/views/pos/_shift.blade.php).
// ============================================================
function hideModal(id) { document.getElementById(id).classList.remove('show'); }
function nf(n) { return parseFloat(n||0).toLocaleString('id-ID', {maximumFractionDigits:0}); }
function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
function tglJam(s) { return s ? esc(String(s).slice(0, 16).replace('T', ' ')) : '—'; }

@if($isManager)
// ── Daftar POS Opening/Closing Entry dari ERP ────────────────────────
async function loadErpEntries() {
    const btn = document.getElementById('btnLoadErpEntries');
    const body = document.getElementById('erpEntriesBody');
    const q = new URLSearchParams(window.location.search);
    const params = new URLSearchParams();
    ['from','to','user_id'].forEach(k => { if (q.get(k)) params.set(k, q.get(k)); });

    btn.disabled = true;
    body.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menarik data dari ERP HPY…';
    try {
        const r = await fetch('{{ route("pos-shift.erp-entries") }}?' + params.toString(),
            { headers: {'Accept':'application/json','X-CSRF-TOKEN':csrf} });
        const d = await r.json();
        if (!d.success) {
            body.innerHTML = `<div style="color:var(--red)"><i class="fas fa-exclamation-circle"></i> ${esc(d.error)}</div>`;
        } else {
            renderErpEntries(d);
        }
    } catch(e) {
        body.innerHTML = '<div style="color:var(--red)">Gagal terhubung ke server.</div>';
    }
    btn.disabled = false;
}

function statusBadge(row) {
    if (Number(row.docstatus) === 2) return '<span class="badge badge-red">Dibatalkan</span>';
    if (Number(row.docstatus) === 0) return '<span class="badge badge-yellow">Draft</span>';
    if (row.status === 'Open') return '<span class="badge badge-yellow">Open</span>';
    return `<span class="badge badge-green">${esc(row.status || 'Submitted')}</span>`;
}

function renderErpEntries(d) {
    const opening = d.opening.map(o => `
        <tr>
            <td style="font-family:monospace;font-size:12px">${esc(o.name)}</td>
            <td style="font-size:13px">${esc(o.user)}</td>
            <td style="white-space:nowrap">${tglJam(o.period_start_date)}</td>
            <td>${statusBadge(o)}</td>
            <td>${o.has_closing
                ? '<span class="badge badge-green">Sudah ditutup</span>'
                : (Number(o.docstatus) === 1 && o.status === 'Open'
                    ? '<span class="badge badge-yellow">Belum ditutup</span>'
                    : '<span style="color:var(--text3)">—</span>')}</td>
        </tr>`).join('');

    const closing = d.closing.map(c => `
        <tr>
            <td style="font-family:monospace;font-size:12px">${esc(c.name)}</td>
            <td style="font-size:13px">${esc(c.user)}</td>
            <td style="white-space:nowrap">${tglJam(c.period_start_date)}</td>
            <td style="white-space:nowrap">${tglJam(c.period_end_date)}</td>
            <td style="text-align:right">${nf(c.grand_total)}</td>
            <td style="text-align:right;color:var(--text2)">${nf(c.total_quantity)}</td>
            <td style="font-family:monospace;font-size:11px;color:var(--text2)">${esc(c.pos_opening_entry) || '—'}</td>
            <td>${statusBadge(c)}</td>
        </tr>`).join('');

    const kosong = (n) => `<tr><td colspan="${n}" style="text-align:center;padding:24px;color:var(--text3)">Tidak ada data pada rentang ini.</td></tr>`;

    document.getElementById('erpEntriesBody').innerHTML = `
        <div style="font-size:12px;color:var(--text3);margin-bottom:12px">
            Rentang ${esc(d.from)} s/d ${esc(d.to)} — ${d.opening.length} Opening, ${d.closing.length} Closing.
        </div>

        <div style="font-weight:700;font-size:13px;margin-bottom:6px">POS Opening Entry</div>
        <div class="table-wrap" style="margin-bottom:20px">
            <table>
                <thead><tr>
                    <th>Nama</th><th>Kasir</th><th>Mulai</th><th>Status</th><th>Penutupan</th>
                </tr></thead>
                <tbody>${opening || kosong(5)}</tbody>
            </table>
        </div>

        <div style="font-weight:700;font-size:13px;margin-bottom:6px">POS Closing Entry</div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>Nama</th><th>Kasir</th><th>Mulai</th><th>Selesai</th>
                    <th style="text-align:right">Grand Total</th><th style="text-align:right">Qty</th>
                    <th>Opening</th><th>Status</th>
                </tr></thead>
                <tbody>${closing || kosong(8)}</tbody>
            </table>
        </div>`;
}
@endif

function showOpenShift() {
    document.getElementById('openShiftModal').classList.add('show');
    setTimeout(() => document.getElementById('openingCashInput').select(), 100);
}

async function doOpenShift() {
    const btn = document.getElementById('btnDoOpenShift');
    const cash = parseFloat(document.getElementById('openingCashInput').value || '0');
    if (isNaN(cash) || cash < 0) { toast('Modal kas tidak valid.', 'error'); return; }
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Membuka…';
    try {
        const r = await fetch('{{ route("pos-shift.open") }}', {
            method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
            body: JSON.stringify({ opening_cash: cash })
        });
        const d = await r.json();
        if (d.success) { toast(d.message || 'Kasir dibuka.', d.offline ? 'warning' : 'success'); setTimeout(() => location.reload(), 700); return; }
        toast(d.error || 'Gagal buka kasir.', 'error');
    } catch(e) { toast('Gagal terhubung ke server.', 'error'); }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-unlock"></i> Buka Kasir';
}

async function showCloseShift() {
    document.getElementById('closeShiftModal').classList.add('show');
    const body = document.getElementById('closeShiftBody');
    document.getElementById('btnDoCloseShift').disabled = true;
    body.innerHTML = '<div style="text-align:center;color:var(--text3);padding:20px"><i class="fas fa-spinner fa-spin"></i> Memuat rekonsiliasi…</div>';
    try {
        const r = await fetch('{{ route("pos-shift.reconcile") }}', { headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf} });
        const d = await r.json();
        if (!d.success) { body.innerHTML = `<div style="color:var(--red);padding:12px">${d.error||'Gagal memuat.'}</div>`; return; }
        renderCloseBody(d);
        document.getElementById('btnDoCloseShift').disabled = false;
    } catch(e) { body.innerHTML = '<div style="color:var(--red);padding:12px">Gagal terhubung ke server.</div>'; }
}

function renderCloseBody(d) {
    const t = d.totals;
    const rows = d.modes.map(m => `
        <tr>
            <td style="padding:6px 4px">${m.mode_of_payment}${m.is_cash?' <span style="color:var(--text3);font-size:11px">(kas)</span>':''}</td>
            <td style="text-align:right;padding:6px 4px;color:var(--text2)">${nf(m.expected_amount)}</td>
            <td style="padding:6px 4px">
                <input type="number" class="form-control close-count" data-mode="${m.mode_of_payment}" data-expected="${m.expected_amount}"
                    value="${m.is_cash ? '' : m.expected_amount}" ${m.is_cash?'placeholder="hitung fisik…"':''}
                    min="0" step="1000" style="text-align:right;font-size:13px;padding:6px" oninput="updateCloseDiff()">
            </td>
            <td style="text-align:right;padding:6px 4px" class="close-diff" data-mode="${m.mode_of_payment}">—</td>
        </tr>`).join('');
    document.getElementById('closeShiftBody').innerHTML = `
        <div style="display:flex;gap:12px;margin-bottom:12px">
            <div style="flex:1;background:var(--surface2);border-radius:8px;padding:10px">
                <div style="font-size:11px;color:var(--text3)">Transaksi</div>
                <div style="font-size:18px;font-weight:800">${t.invoice_count}</div>
            </div>
            <div style="flex:2;background:var(--surface2);border-radius:8px;padding:10px">
                <div style="font-size:11px;color:var(--text3)">Total Penjualan</div>
                <div style="font-size:18px;font-weight:800">Rp ${nf(t.grand_total)}</div>
            </div>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead><tr style="border-bottom:1px solid var(--border);font-size:11px;color:var(--text3);text-transform:uppercase">
                <th style="text-align:left;padding:6px 4px">Metode</th>
                <th style="text-align:right;padding:6px 4px">Sistem</th>
                <th style="text-align:left;padding:6px 4px">Hitung Fisik</th>
                <th style="text-align:right;padding:6px 4px">Selisih</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>
        <p style="font-size:11px;color:var(--text3);margin-top:10px"><i class="fas fa-info-circle"></i> Metode non-tunai biarkan sesuai sistem. Untuk <b>kas</b>, isi jumlah uang fisik yang dihitung.</p>`;
    updateCloseDiff();
}

function updateCloseDiff() {
    document.querySelectorAll('.close-count').forEach(inp => {
        const exp = parseFloat(inp.dataset.expected || '0');
        const val = inp.value === '' ? exp : parseFloat(inp.value || '0');
        const diff = val - exp;
        const cell = document.querySelector(`.close-diff[data-mode="${CSS.escape(inp.dataset.mode)}"]`);
        if (cell) {
            cell.textContent = (diff < 0 ? '-' : '') + nf(Math.abs(diff));
            cell.style.color = diff === 0 ? 'var(--text3)' : (diff < 0 ? 'var(--red)' : 'var(--blue)');
        }
    });
}

async function doCloseShift() {
    const btn = document.getElementById('btnDoCloseShift');
    const counted = {};
    document.querySelectorAll('.close-count').forEach(inp => {
        const exp = parseFloat(inp.dataset.expected || '0');
        counted[inp.dataset.mode] = inp.value === '' ? exp : parseFloat(inp.value || '0');
    });
    if (!confirm('Tutup kasir sekarang? Transaksi akan dikonsolidasi di ERP dan shift ditutup.')) return;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menutup…';
    try {
        const r = await fetch('{{ route("pos-shift.close") }}', {
            method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
            body: JSON.stringify({ counted })
        });
        const d = await r.json();
        if (d.success) {
            toast('Kasir ditutup.');
            if (d.print_url) window.open(d.print_url, '_blank');
            setTimeout(() => location.reload(), 900);
            return;
        }
        toast(d.error || 'Gagal tutup kasir.', 'error');
    } catch(e) { toast('Gagal terhubung ke server.', 'error'); }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-lock"></i> Tutup & Cetak';
}
</script>
@endsection
