@extends('layouts.app')
@section('title', 'Pengaturan Toko')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="fas fa-store text-blue" style="margin-right:8px;"></i>Pengaturan Toko
        </h1>
        <p class="page-subtitle">Informasi toko yang tampil di struk pembayaran</p>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

    {{-- Form Pengaturan --}}
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-edit text-blue" style="margin-right:6px;"></i>Informasi Toko</span>
            <span id="saveStatus" class="badge badge-gray" style="display:none;"></span>
        </div>
        <div class="card-body">
            <form id="storeSettingsForm">
                <div class="form-group">
                    <label class="form-label">Nama Toko <span style="color:var(--red)">*</span></label>
                    <input type="text" name="store_name" class="form-control"
                        value="{{ $settings['store_name'] }}"
                        placeholder="Contoh: HPYSync" maxlength="100"
                        oninput="updatePreview()">
                    @error('store_name')<p style="font-size:12px;color:var(--red);margin-top:4px;">{{ $message }}</p>@enderror
                </div>

                <div class="form-group">
                    <label class="form-label">Tagline / Slogan</label>
                    <input type="text" name="store_tagline" class="form-control"
                        value="{{ $settings['store_tagline'] }}"
                        placeholder="Contoh: Point of Sale System" maxlength="150"
                        oninput="updatePreview()">
                </div>

                <div class="form-group">
                    <label class="form-label">Alamat Toko</label>
                    <textarea name="store_address" class="form-control" rows="2"
                        placeholder="Jl. Contoh No. 1, Jakarta" maxlength="300"
                        oninput="updatePreview()">{{ $settings['store_address'] }}</textarea>
                </div>

                <div class="grid-2 gap-3">
                    <div class="form-group">
                        <label class="form-label">No. Telepon</label>
                        <input type="text" name="store_phone" class="form-control"
                            value="{{ $settings['store_phone'] }}"
                            placeholder="021-12345678" maxlength="30"
                            oninput="updatePreview()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="store_email" class="form-control"
                            value="{{ $settings['store_email'] }}"
                            placeholder="info@toko.com" maxlength="100"
                            oninput="updatePreview()">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Pesan Footer Struk</label>
                    <input type="text" name="receipt_footer" class="form-control"
                        value="{{ $settings['receipt_footer'] }}"
                        placeholder="Terima kasih atas kunjungan Anda!" maxlength="200"
                        oninput="updatePreview()">
                    <p style="font-size:12px;color:var(--text3);margin-top:4px;">Pesan yang tampil di bagian bawah struk</p>
                </div>

                <div class="form-group">
                    <label class="form-label">POS Class</label>
                    <input type="text" name="pos_class" class="form-control"
                        value="{{ $settings['pos_class'] }}"
                        placeholder="Contoh: Retail, Wholesale, B2B…" maxlength="100">
                    <p style="font-size:12px;color:var(--text3);margin-top:4px;">Digunakan saat sinkronisasi transaksi ke ERP HPY</p>
                </div>

                <div class="form-group">
                    <label class="form-label">Tampilan Produk di Kasir</label>
                    <div style="display:flex;gap:12px;margin-top:4px">
                        <label style="flex:1;cursor:pointer">
                            <input type="radio" name="pos_product_display" value="image"
                                {{ ($settings['pos_product_display'] ?? 'image') === 'image' ? 'checked' : '' }}
                                style="display:none" class="prod-display-radio">
                            <div class="prod-display-option" style="border:2px solid var(--border);border-radius:10px;padding:14px;text-align:center;transition:all .2s">
                                <div style="font-size:28px;margin-bottom:6px">🖼️</div>
                                <div style="font-size:13px;font-weight:600">Gambar + Teks</div>
                                <div style="font-size:11px;color:var(--text3);margin-top:2px">Tampilkan foto produk</div>
                            </div>
                        </label>
                        <label style="flex:1;cursor:pointer">
                            <input type="radio" name="pos_product_display" value="text"
                                {{ ($settings['pos_product_display'] ?? 'image') === 'text' ? 'checked' : '' }}
                                style="display:none" class="prod-display-radio">
                            <div class="prod-display-option" style="border:2px solid var(--border);border-radius:10px;padding:14px;text-align:center;transition:all .2s">
                                <div style="font-size:28px;margin-bottom:6px">📝</div>
                                <div style="font-size:13px;font-weight:600">Teks Saja</div>
                                <div style="font-size:11px;color:var(--text3);margin-top:2px">Lebih banyak produk terlihat</div>
                            </div>
                        </label>
                    </div>
                </div>

                {{-- Service Charge & PB1 --}}
                <div style="background:var(--surface2);border-radius:12px;padding:16px;margin-bottom:16px">
                    <div style="font-weight:700;font-size:13px;color:var(--text2);margin-bottom:12px;display:flex;align-items:center;gap:6px">
                        <i class="fas fa-receipt" style="color:var(--blue)"></i>
                        Biaya Tambahan Dine In
                        <span style="font-size:11px;font-weight:500;color:var(--text3)">(berlaku hanya untuk pesanan Dine In)</span>
                    </div>

                    {{-- Service Charge --}}
                    <div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--surface);border-radius:10px;margin-bottom:10px;border:1px solid var(--border)">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;flex:1">
                            <input type="checkbox" name="service_charge_enabled" value="1" id="scCheck"
                                {{ ($settings['service_charge_enabled'] ?? '0') === '1' ? 'checked' : '' }}
                                style="width:18px;height:18px;accent-color:var(--blue);cursor:pointer"
                                onchange="toggleChargeInput('scInput', this.checked)">
                            <div>
                                <div style="font-weight:700;font-size:13px">Service Charge</div>
                                <div style="font-size:11px;color:var(--text3)">Biaya pelayanan ditambahkan ke tagihan Dine In</div>
                            </div>
                        </label>
                        <div style="display:flex;align-items:center;gap:6px" id="scInput">
                            <input type="number" name="service_charge_pct" id="scPct"
                                value="{{ $settings['service_charge_pct'] ?? '0' }}"
                                min="0" max="100" step="0.1"
                                style="width:80px;padding:8px 10px;border:2px solid var(--border);border-radius:8px;font-size:15px;font-weight:800;text-align:right;font-family:'Roboto Mono',monospace;{{ ($settings['service_charge_enabled'] ?? '0') !== '1' ? 'opacity:.4;pointer-events:none' : '' }}"
                                placeholder="10">
                            <span style="font-weight:700;color:var(--text2);font-size:15px">%</span>
                        </div>
                    </div>

                    {{-- PB1 --}}
                    <div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--surface);border-radius:10px;border:1px solid var(--border)">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;flex:1">
                            <input type="checkbox" name="pb1_enabled" value="1" id="pb1Check"
                                {{ ($settings['pb1_enabled'] ?? '0') === '1' ? 'checked' : '' }}
                                style="width:18px;height:18px;accent-color:var(--blue);cursor:pointer"
                                onchange="toggleChargeInput('pb1Input', this.checked)">
                            <div>
                                <div style="font-weight:700;font-size:13px">PB1 — Pajak Daerah</div>
                                <div style="font-size:11px;color:var(--text3)">Pajak Bangunan 1 / Pajak Restoran daerah (dikenakan pada Dine In)</div>
                            </div>
                        </label>
                        <div style="display:flex;align-items:center;gap:6px" id="pb1Input">
                            <input type="number" name="pb1_pct" id="pb1Pct"
                                value="{{ $settings['pb1_pct'] ?? '0' }}"
                                min="0" max="100" step="0.1"
                                style="width:80px;padding:8px 10px;border:2px solid var(--border);border-radius:8px;font-size:15px;font-weight:800;text-align:right;font-family:'Roboto Mono',monospace;{{ ($settings['pb1_enabled'] ?? '0') !== '1' ? 'opacity:.4;pointer-events:none' : '' }}"
                                placeholder="10">
                            <span style="font-weight:700;color:var(--text2);font-size:15px">%</span>
                        </div>
                    </div>
                </div>

                {{-- Logo Struk --}}
                <div class="form-group" style="border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:16px">
                    <div style="font-weight:700;font-size:13px;color:var(--text2);margin-bottom:12px;display:flex;align-items:center;gap:6px">
                        <i class="fas fa-image" style="color:var(--blue)"></i>
                        Logo Struk
                    </div>

                    <div id="logoPreviewWrap" style="margin-bottom:12px">
                        @if($settings['store_logo'])
                        <div style="display:flex;align-items:center;gap:12px">
                            <img id="logoImg" src="{{ asset($settings['store_logo']) }}"
                                style="height:64px;max-width:160px;object-fit:contain;border:1px solid var(--border);border-radius:8px;padding:4px;background:#fff">
                            <button type="button" onclick="removeLogo()" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i> Hapus Logo
                            </button>
                        </div>
                        @else
                        <div id="noLogoMsg" style="color:var(--text3);font-size:13px;display:flex;align-items:center;gap:8px">
                            <i class="fas fa-image" style="font-size:32px;color:var(--border)"></i>
                            Belum ada logo
                        </div>
                        @endif
                    </div>

                    <div style="display:flex;align-items:center;gap:10px">
                        <label for="logoFile" class="btn btn-outline btn-sm" style="cursor:pointer;margin:0">
                            <i class="fas fa-upload"></i> Pilih Gambar
                        </label>
                        <input type="file" id="logoFile" accept="image/jpeg,image/png,image/gif,image/webp"
                            style="display:none" onchange="uploadLogo(this)">
                        <span id="logoUploadStatus" class="text-sm text-muted"></span>
                    </div>
                    <p style="font-size:11px;color:var(--text3);margin-top:8px">
                        Format: JPG, PNG, GIF, WebP &mdash; Maks. 2 MB.
                        Akan tampil di bagian atas struk digital dan cetak.
                    </p>
                </div>

                <button type="button" class="btn btn-primary" id="saveBtn" onclick="saveSettings()">
                    <i class="fas fa-save"></i> Simpan Pengaturan
                </button>
            </form>
        </div>
    </div>

    {{-- Preview Struk --}}
    <div>
        <div class="card" style="position:sticky;top:80px;">
            <div class="card-header">
                <span class="card-title"><i class="fas fa-receipt text-blue" style="margin-right:6px;"></i>Preview Struk</span>
                <span style="font-size:12px;color:var(--text3);">Tampilan thermal printer</span>
            </div>
            <div class="card-body" style="display:flex;justify-content:center;">
                <div id="receiptPreview" style="font-family:'Courier New',monospace;font-size:12px;width:260px;border:1px dashed var(--border);padding:14px;background:#fff;border-radius:6px;line-height:1.6;">
                    {{-- diisi oleh JS --}}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function toggleChargeInput(wrapperId, enabled) {
    const input = document.querySelector('#' + wrapperId + ' input[type="number"]');
    if (!input) return;
    input.style.opacity        = enabled ? '1' : '.4';
    input.style.pointerEvents  = enabled ? '' : 'none';
}

// Radio button visual untuk tampilan produk
function updateProdDisplayUI() {
    document.querySelectorAll('.prod-display-radio').forEach(radio => {
        const box = radio.nextElementSibling;
        box.style.borderColor    = radio.checked ? 'var(--blue)' : 'var(--border)';
        box.style.background     = radio.checked ? 'var(--blue-light, #E8F0FE)' : '';
        box.style.color          = radio.checked ? 'var(--blue)' : '';
    });
}
document.querySelectorAll('.prod-display-radio').forEach(r => {
    r.addEventListener('change', updateProdDisplayUI);
});

function val(name) {
    return document.querySelector(`[name="${name}"]`)?.value?.trim() ?? '';
}

let currentLogoUrl = '{{ $settings['store_logo'] ? asset($settings['store_logo']) : '' }}';

async function uploadLogo(input) {
    if (!input.files[0]) return;
    const status = document.getElementById('logoUploadStatus');
    status.textContent = 'Mengunggah...';

    const fd = new FormData();
    fd.append('logo', input.files[0]);
    fd.append('_token', document.querySelector('meta[name="csrf-token"]').content);

    try {
        const res  = await fetch('{{ route("settings.logo.upload") }}', { method: 'POST', body: fd });
        const json = await res.json();

        if (!json.success) throw new Error(json.message || 'Gagal');

        currentLogoUrl = json.url;
        document.getElementById('logoPreviewWrap').innerHTML = `
            <div style="display:flex;align-items:center;gap:12px">
                <img id="logoImg" src="${json.url}"
                    style="height:64px;max-width:160px;object-fit:contain;border:1px solid var(--border);border-radius:8px;padding:4px;background:#fff">
                <button type="button" onclick="removeLogo()" class="btn btn-danger btn-sm">
                    <i class="fas fa-trash"></i> Hapus Logo
                </button>
            </div>`;
        status.textContent = '';
        updatePreview();
        toast('Logo berhasil disimpan!', 'success');
    } catch (e) {
        status.textContent = 'Gagal: ' + e.message;
    }
    input.value = '';
}

async function removeLogo() {
    if (!confirm('Hapus logo struk?')) return;
    const status = document.getElementById('logoUploadStatus');

    try {
        const res  = await fetch('{{ route("settings.logo.remove") }}', {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        });
        const json = await res.json();
        if (!json.success) throw new Error('Gagal');

        currentLogoUrl = '';
        document.getElementById('logoPreviewWrap').innerHTML = `
            <div id="noLogoMsg" style="color:var(--text3);font-size:13px;display:flex;align-items:center;gap:8px">
                <i class="fas fa-image" style="font-size:32px;color:var(--border)"></i>
                Belum ada logo
            </div>`;
        status.textContent = '';
        updatePreview();
        toast('Logo dihapus', 'success');
    } catch (e) {
        toast('Gagal menghapus logo', 'error');
    }
}

function updatePreview() {
    const name    = val('store_name')     || 'HPYSync';
    const tagline = val('store_tagline')  || 'Point of Sale System';
    const address = val('store_address');
    const phone   = val('store_phone');
    const email   = val('store_email');
    const footer  = val('receipt_footer') || 'Terima kasih atas kunjungan Anda!';

    const divider = `<div style="border-top:1px dashed #000;margin:5px 0;"></div>`;

    let html = '';
    if (currentLogoUrl) {
        html += `<div style="text-align:center;margin-bottom:6px"><img src="${currentLogoUrl}" style="max-height:50px;max-width:200px;object-fit:contain"></div>`;
    }
    html += `<div style="text-align:center;font-weight:bold;font-size:15px;">${name}</div>`;
    if (tagline) html += `<div style="text-align:center;font-size:10px;">${tagline}</div>`;
    if (address) html += `<div style="text-align:center;font-size:10px;">${address.replace(/\n/g,'<br>')}</div>`;
    if (phone)   html += `<div style="text-align:center;font-size:10px;">Telp: ${phone}</div>`;
    if (email)   html += `<div style="text-align:center;font-size:10px;">${email}</div>`;

    html += divider;
    html += `<div style="display:flex;justify-content:space-between;"><span>Invoice</span><span>INV-YYYYMMDD-0001</span></div>`;
    html += `<div style="display:flex;justify-content:space-between;"><span>Tanggal</span><span>${new Date().toLocaleDateString('id-ID')}</span></div>`;
    html += `<div style="display:flex;justify-content:space-between;"><span>Kasir</span><span>Admin</span></div>`;
    html += divider;
    html += `<div>Produk Contoh</div><div style="display:flex;justify-content:space-between;padding-left:8px;"><span>1 x Rp 50.000</span><span>Rp 50.000</span></div>`;
    html += divider;
    const scEnabled  = document.getElementById('scCheck')?.checked;
    const scPct      = parseFloat(document.getElementById('scPct')?.value || 0);
    const pb1Enabled = document.getElementById('pb1Check')?.checked;
    const pb1Pct     = parseFloat(document.getElementById('pb1Pct')?.value || 0);
    const scAmt      = scEnabled  ? Math.round(50000 * scPct / 100)  : 0;
    const pb1Amt     = pb1Enabled ? Math.round((50000 + scAmt) * pb1Pct / 100) : 0;
    const grandTotal = 50000 + scAmt + pb1Amt;

    html += `<div style="display:flex;justify-content:space-between;"><span>Subtotal</span><span>Rp 50.000</span></div>`;
    if (scEnabled && scAmt > 0) html += `<div style="display:flex;justify-content:space-between;"><span>Service Charge (${scPct}%)</span><span>Rp ${scAmt.toLocaleString('id-ID')}</span></div>`;
    if (pb1Enabled && pb1Amt > 0) html += `<div style="display:flex;justify-content:space-between;"><span>PB1 (${pb1Pct}%)</span><span>Rp ${pb1Amt.toLocaleString('id-ID')}</span></div>`;
    html += divider;
    html += `<div style="display:flex;justify-content:space-between;font-weight:bold;font-size:14px;"><span>TOTAL</span><span>Rp ${grandTotal.toLocaleString('id-ID')}</span></div>`;
    html += `<div style="display:flex;justify-content:space-between;"><span>Bayar (CASH)</span><span>Rp 100.000</span></div>`;
    html += `<div style="display:flex;justify-content:space-between;"><span>Kembalian</span><span>Rp 50.000</span></div>`;
    html += divider;
    html += `<div style="text-align:center;margin-top:6px;">${footer}</div>`;

    document.getElementById('receiptPreview').innerHTML = html;
}

async function saveSettings() {
    const btn    = document.getElementById('saveBtn');
    const status = document.getElementById('saveStatus');
    const form   = document.getElementById('storeSettingsForm');
    const data   = {};

    new FormData(form).forEach((v, k) => data[k] = v);

    btn.innerHTML = '<span class="spinner"></span> Menyimpan...';
    btn.disabled  = true;
    status.style.display = 'none';

    try {
        const res = await api.post('{{ route("settings.save") }}', data);

        if (res.success) {
            status.className     = 'badge badge-green';
            status.textContent   = '✓ Tersimpan';
            status.style.display = '';
            toast('Pengaturan berhasil disimpan!', 'success');
            setTimeout(() => status.style.display = 'none', 3000);
        } else {
            toast('Gagal menyimpan: ' + (res.message || 'Error'), 'error');
        }
    } catch(e) {
        toast('Error: ' + e.message, 'error');
    }

    btn.innerHTML = '<i class="fas fa-save"></i> Simpan Pengaturan';
    btn.disabled  = false;
}

// Wire charge inputs to preview
['scCheck','pb1Check','scPct','pb1Pct'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', updatePreview);
    if (el) el.addEventListener('input', updatePreview);
});

// Init preview on load
updatePreview();
updateProdDisplayUI();
</script>
@endpush
