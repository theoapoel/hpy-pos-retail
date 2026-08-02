@extends('layouts.app')
@section('title', 'Pengaturan Toko')

@section('content')
<style>
    .ig-chip { display:inline-flex; align-items:center; padding:6px 12px; border:2px solid var(--border); border-radius:20px; font-size:12px; font-weight:600; cursor:pointer; user-select:none; transition:all .15s; }
    .ig-chip:hover { border-color:var(--blue); }
    .ig-chip.on { background:var(--blue); border-color:var(--blue); color:#fff; }
    .ig-bulk { border:none; background:none; padding:0; margin-left:2px; font-size:11px; font-weight:700; color:var(--blue); cursor:pointer; font-family:inherit; text-decoration:underline; }
    .ig-bulk:hover { opacity:.75; }
</style>
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
                    <label class="form-label">Nama Toko</label>
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
                    <label class="form-label">📏 Ukuran Kertas Struk</label>
                    <select name="receipt_paper_size" class="form-control" style="max-width:260px">
                        <option value="58" @selected(($settings['receipt_paper_size'] ?? '58') !== '80')>58 mm (32 karakter)</option>
                        <option value="80" @selected(($settings['receipt_paper_size'] ?? '58') === '80')>80 mm (48 karakter)</option>
                    </select>
                    <p style="font-size:12px;color:var(--text3);margin-top:4px;">
                        Berlaku untuk struk transaksi, struk dapur, struk tutup kasir, dan cetak langsung ESC/POS.
                        Pastikan sesuai roll kertas yang terpasang — salah pilih membuat baris terpotong atau terlalu sempit.
                    </p>
                </div>

                <div class="form-group">
                    <label class="form-label">🧾 Thermal Printer (ESC/POS)</label>
                    <p style="font-size:12px;color:var(--text3);margin:2px 0 8px;">
                        Untuk fitur "Print Thermal" langsung ke printer termal.
                        Server ini terdeteksi sebagai <strong>{{ $settings['os_family'] }}</strong> —
                        field yang aktif ditandai di bawah.
                    </p>

                    <div style="display:flex;gap:12px;flex-wrap:wrap">
                        <div style="flex:1;min-width:200px">
                            <label class="form-label" style="font-size:12px">
                                Device Path (Linux){{ $settings['os_family'] !== 'Windows' ? ' • aktif' : '' }}
                            </label>
                            <input type="text" name="thermal_printer_device" class="form-control"
                                value="{{ $settings['thermal_printer_device'] }}"
                                placeholder="/dev/usb/lp1" maxlength="255">
                        </div>
                        <div style="flex:1;min-width:200px">
                            <label class="form-label" style="font-size:12px">
                                Nama Printer (Windows){{ $settings['os_family'] === 'Windows' ? ' • aktif' : '' }}
                            </label>
                            <input type="text" name="thermal_printer_name" class="form-control"
                                value="{{ $settings['thermal_printer_name'] }}"
                                placeholder="EPPOS58" maxlength="255">
                        </div>
                    </div>
                    <p style="font-size:12px;color:var(--text3);margin-top:4px;">
                        Windows: gunakan nama <em>shared printer</em> (mis. <code>EPPOS58</code>).
                        Linux: path device USB printer (mis. <code>/dev/usb/lp1</code>).
                    </p>
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

                <div class="form-group">
                    <label class="form-label">Cakupan Laporan Transaksi</label>
                    <div style="display:flex;gap:12px;margin-top:4px">
                        <label style="flex:1;cursor:pointer">
                            <input type="radio" name="report_scope" value="all"
                                {{ ($settings['report_scope'] ?: 'all') === 'all' ? 'checked' : '' }}
                                style="display:none" class="report-scope-radio">
                            <div class="report-scope-option" style="border:2px solid var(--border);border-radius:10px;padding:14px;text-align:center;transition:all .2s">
                                <div style="font-size:28px;margin-bottom:6px">🏪</div>
                                <div style="font-size:13px;font-weight:600">Semua Transaksi</div>
                                <div style="font-size:11px;color:var(--text3);margin-top:2px">Satu laporan toko, tanpa membedakan kasir/shift</div>
                            </div>
                        </label>
                        <label style="flex:1;cursor:pointer">
                            <input type="radio" name="report_scope" value="user"
                                {{ ($settings['report_scope'] ?: 'all') === 'user' ? 'checked' : '' }}
                                style="display:none" class="report-scope-radio">
                            <div class="report-scope-option" style="border:2px solid var(--border);border-radius:10px;padding:14px;text-align:center;transition:all .2s">
                                <div style="font-size:28px;margin-bottom:6px">👤</div>
                                <div style="font-size:13px;font-weight:600">Per Kasir</div>
                                <div style="font-size:11px;color:var(--text3);margin-top:2px">Kasir hanya melihat transaksinya sendiri</div>
                            </div>
                        </label>
                    </div>
                    <p style="font-size:12px;color:var(--text3);margin-top:6px">
                        Manager &amp; admin selalu melihat seluruh transaksi. Pilihan ini menentukan apa yang dilihat kasir.
                    </p>
                </div>

                <div class="form-group">
                    <label class="form-label">Layout Kasir</label>
                    <input type="hidden" name="pos_layout" id="posLayoutInput" value="{{ $settings['pos_layout'] ?: 'index' }}">
                    <div style="display:flex;gap:12px;margin-top:4px">
                        <div class="pos-layout-opt" data-value="index" onclick="selectPosLayout(this)"
                            style="flex:1;cursor:pointer;border:2px solid var(--border);border-radius:10px;padding:14px;text-align:center;transition:all .2s">
                            <div style="font-size:28px;margin-bottom:6px">🖼️</div>
                            <div style="font-size:13px;font-weight:600">Kasir Klasik</div>
                            <div style="font-size:11px;color:var(--text3);margin-top:2px">Grid produk + gambar</div>
                        </div>
                        <div class="pos-layout-opt" data-value="quick" onclick="selectPosLayout(this)"
                            style="flex:1;cursor:pointer;border:2px solid var(--border);border-radius:10px;padding:14px;text-align:center;transition:all .2s">
                            <div style="font-size:28px;margin-bottom:6px">⚡</div>
                            <div style="font-size:13px;font-weight:600">Kasir Cepat</div>
                            <div style="font-size:11px;color:var(--text3);margin-top:2px">Cari + tabel pesanan</div>
                        </div>
                        <div class="pos-layout-opt" data-value="express" onclick="selectPosLayout(this)"
                            style="flex:1;cursor:pointer;border:2px solid var(--border);border-radius:10px;padding:14px;text-align:center;transition:all .2s">
                            <div style="font-size:28px;margin-bottom:6px">💰</div>
                            <div style="font-size:13px;font-weight:600">Kasir Express</div>
                            <div style="font-size:11px;color:var(--text3);margin-top:2px">Total besar, tanpa tipe pesanan</div>
                        </div>
                    </div>
                    <p style="font-size:12px;color:var(--text3);margin-top:6px">Menentukan tampilan yang dibuka saat kasir klik menu Kasir</p>
                </div>

                {{-- Filter Item Group per layar --}}
                <div style="background:var(--surface2);border-radius:12px;padding:16px;margin-bottom:16px">
                    <div style="font-weight:700;font-size:13px;color:var(--text2);margin-bottom:4px;display:flex;align-items:center;gap:6px">
                        <i class="fas fa-filter" style="color:var(--blue)"></i>
                        Filter Item Group
                    </div>
                    <p style="font-size:11px;color:var(--text3);margin-bottom:12px">
                        Pilih Item Group yang ditampilkan di tiap layar.
                        <strong>Default: kosong = semua tampil</strong>, termasuk Item Group baru dari ERP.
                        Centang hanya kalau ingin membatasi.
                    </p>

                    @php
                        $igScreens = [
                            'pos_item_groups'           => ['Kasir', 'fa-cash-register'],
                            'delivery_item_groups'      => ['Delivery Order', 'fa-truck'],
                            'stock_request_item_groups' => ['Permintaan FG', 'fa-clipboard-check'],
                            'slice_item_groups'         => ['Repack (Konversi)', 'fa-scissors'],
                        ];
                    @endphp

                    @foreach($igScreens as $igKey => $igMeta)
                        @php $igSelected = $settings[$igKey] ?? []; @endphp
                        <div style="margin-bottom:14px">
                            <input type="hidden" name="{{ $igKey }}" id="ig_{{ $igKey }}" value="{{ implode(',', $igSelected) }}">
                            <div style="font-weight:700;font-size:12px;margin-bottom:6px;display:flex;align-items:center;gap:6px">
                                <i class="fas {{ $igMeta[1] }}" style="color:var(--text3)"></i> {{ $igMeta[0] }}
                                <span style="font-size:11px;font-weight:500;color:var(--text3)" id="igcount_{{ $igKey }}"></span>
                                @if(count($itemGroups))
                                    <button type="button" class="ig-bulk" onclick="setAllItemGroups('{{ $igKey }}', true)">Pilih semua</button>
                                    <button type="button" class="ig-bulk" onclick="setAllItemGroups('{{ $igKey }}', false)">Kosongkan</button>
                                @endif
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:6px">
                                @forelse($itemGroups as $g)
                                    <label class="ig-chip{{ in_array($g->id, $igSelected) ? ' on' : '' }}" data-key="{{ $igKey }}" data-id="{{ $g->id }}">
                                        <input type="checkbox" style="display:none" {{ in_array($g->id, $igSelected) ? 'checked' : '' }}
                                            onchange="toggleItemGroup('{{ $igKey }}')">
                                        {{ $g->name }}
                                    </label>
                                @empty
                                    <span style="font-size:12px;color:var(--text3)">Belum ada Item Group. Sync produk dari ERP HPY dulu.</span>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>

                <button type="button" class="btn btn-primary" id="saveBtn" onclick="saveSettings()">
                    <i class="fas fa-save"></i> Simpan Pengaturan
                </button>
            </form>
        </div>
    </div>

    {{-- Kolom Kanan: Logo + Preview --}}
    <div style="display:flex;flex-direction:column;gap:20px">

        {{-- Preview Struk --}}
        <div class="card">
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

        {{-- Logo Struk --}}
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="fas fa-image text-blue" style="margin-right:6px;"></i>Logo Struk</span>
            </div>
            <div class="card-body">
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
        </div>
    </div>{{-- end kolom kanan --}}
</div>
@endsection

@push('scripts')
<script>
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

// Radio button visual untuk cakupan laporan transaksi
function updateReportScopeUI() {
    document.querySelectorAll('.report-scope-radio').forEach(radio => {
        const box = radio.nextElementSibling;
        box.style.borderColor = radio.checked ? 'var(--blue)' : 'var(--border)';
        box.style.background  = radio.checked ? 'var(--blue-light, #E8F0FE)' : '';
        box.style.color       = radio.checked ? 'var(--blue)' : '';
    });
}
document.querySelectorAll('.report-scope-radio').forEach(r => {
    r.addEventListener('change', updateReportScopeUI);
});
updateReportScopeUI();

// Layout Kasir selector
function selectPosLayout(el) {
    document.getElementById('posLayoutInput').value = el.dataset.value;
    document.querySelectorAll('.pos-layout-opt').forEach(o => {
        const active = o === el;
        o.style.borderColor = active ? 'var(--blue)' : 'var(--border)';
        o.style.background  = active ? 'var(--blue-light, #E8F0FE)' : '';
    });
}
(function initPosLayout() {
    const cur = document.getElementById('posLayoutInput').value;
    document.querySelectorAll('.pos-layout-opt').forEach(o => {
        if (o.dataset.value === cur) selectPosLayout(o);
    });
})();

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
        const res  = await fetch('{{ route("settings.logo.upload") }}', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: fd,
        });
        const text = await res.text();
        let json;
        try {
            json = JSON.parse(text);
        } catch (e) {
            console.error('Non-JSON response from settings.logo.upload:', text.substring(0, 300));
            throw new Error('Server error (HTTP ' + res.status + '). Cek Laravel log.');
        }

        if (!json.success) {
            const msg = json.errors?.logo?.[0] || json.message || 'Gagal';
            throw new Error(msg);
        }

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
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        });
        const text = await res.text();
        let json;
        try {
            json = JSON.parse(text);
        } catch (e) {
            console.error('Non-JSON response from settings.logo.remove:', text.substring(0, 300));
            throw new Error('Server error (HTTP ' + res.status + '). Cek Laravel log.');
        }
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
    const name    = val('store_name')     || '';
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
    html += `<div style="display:flex;justify-content:space-between;"><span>Subtotal</span><span>Rp 50.000</span></div>`;
    html += divider;
    html += `<div style="display:flex;justify-content:space-between;font-weight:bold;font-size:14px;"><span>TOTAL</span><span>Rp 50.000</span></div>`;
    html += `<div style="display:flex;justify-content:space-between;"><span>Bayar (CASH)</span><span>Rp 100.000</span></div>`;
    html += `<div style="display:flex;justify-content:space-between;"><span>Kembalian</span><span>Rp 50.000</span></div>`;
    html += divider;
    if (footer) html += `<div style="text-align:center;margin-top:6px;">${footer}</div>`;
    html += `<div style="text-align:center;font-size:10px;color:#aaa;margin-top:4px;">Powered by HPY Solution</div>`;

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


// Item Group filter chips → sync hidden CSV input + visual state + count
function toggleItemGroup(key) {
    const chips = document.querySelectorAll('.ig-chip[data-key="' + key + '"]');
    const ids = [];
    chips.forEach(chip => {
        const on = chip.querySelector('input').checked;
        chip.classList.toggle('on', on);
        if (on) ids.push(chip.dataset.id);
    });
    document.getElementById('ig_' + key).value = ids.join(',');
    document.getElementById('igcount_' + key).textContent = ids.length
        ? '(' + ids.length + ' dipilih)'
        : '(semua tampil — default)';
}

// Centang/lepas semua chip sekaligus untuk satu layar.
function setAllItemGroups(key, on) {
    document.querySelectorAll('.ig-chip[data-key="' + key + '"] input').forEach(cb => cb.checked = on);
    toggleItemGroup(key);
}
['pos_item_groups', 'delivery_item_groups', 'stock_request_item_groups', 'slice_item_groups'].forEach(toggleItemGroup);

// Init preview on load
updatePreview();
updateProdDisplayUI();
</script>
@endpush
