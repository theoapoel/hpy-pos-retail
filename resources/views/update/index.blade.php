@extends('layouts.app')
@section('title', 'Update Sistem')

@section('content')
<div class="page-header">
    <div>
        <div class="page-title"><i class="fas fa-download text-blue"></i> Update Sistem</div>
        <div class="page-subtitle">Perbarui aplikasi ke versi terbaru dari HPY Solution</div>
    </div>
</div>

@if(!$tokenSet)
<div class="alert alert-warning" style="margin-bottom:20px">
    <i class="fas fa-exclamation-triangle"></i>
    <div>
        <strong>GITHUB_TOKEN belum dikonfigurasi.</strong>
        Tambahkan <code>GITHUB_TOKEN=&lt;token&gt;</code> di file <code>.env</code>, lalu jalankan
        <code>php artisan config:clear</code>. Hubungi HPY Solution untuk mendapatkan token.
    </div>
</div>
@endif

{{-- Version cards --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">
    {{-- Versi Saat Ini --}}
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-desktop text-blue"></i> Versi Saat Ini</div>
            <span class="badge badge-blue">LOKAL</span>
        </div>
        <div class="card-body">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                <div style="font-family:'Google Sans',sans-serif;font-size:28px;font-weight:700;color:var(--blue);letter-spacing:1px">
                    {{ $local['sha'] ?: '?' }}
                </div>
                @if($local['branch'])
                <span class="badge badge-gray">{{ $local['branch'] }}</span>
                @endif
            </div>
            @if($local['date'])
            <div class="text-sm text-muted" style="margin-bottom:4px">
                <i class="fas fa-clock" style="width:14px"></i>
                {{ \Carbon\Carbon::parse($local['date'])->setTimezone('Asia/Jakarta')->isoFormat('D MMM Y, HH:mm') }} WIB
            </div>
            @endif
            @if($local['message'])
            <div class="text-sm" style="color:var(--text2)">
                <i class="fas fa-code-commit" style="width:14px"></i>
                {{ Str::limit($local['message'], 80) }}
            </div>
            @endif
        </div>
    </div>

    {{-- Versi Terbaru (GitHub) --}}
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fab fa-github"></i> Versi Terbaru (GitHub)</div>
            <span id="latestBadge" class="badge badge-gray">BELUM DICEK</span>
        </div>
        <div class="card-body">
            <div id="latestLoading" style="color:var(--text3);font-size:14px;display:flex;align-items:center;gap:8px">
                <div style="width:16px;height:16px;border:2px solid #E8F0FE;border-top-color:#4285F4;border-radius:50%;animation:spin .8s linear infinite"></div>
                Mengambil info dari GitHub...
            </div>
            <div id="latestInfo" style="display:none">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                    <div id="latestSha" style="font-family:'Google Sans',sans-serif;font-size:28px;font-weight:700;color:var(--green);letter-spacing:1px">
                        —
                    </div>
                </div>
                <div id="latestDate" class="text-sm text-muted" style="margin-bottom:4px"></div>
                <div id="latestMsg"  class="text-sm" style="color:var(--text2)"></div>
            </div>
            <div id="latestError" style="display:none" class="alert alert-danger" style="margin:0">
                <i class="fas fa-exclamation-circle"></i>
                <span id="latestErrorMsg"></span>
            </div>
        </div>
    </div>
</div>

{{-- Status bar --}}
<div id="statusBar" style="display:none;margin-bottom:24px"></div>

{{-- Update form --}}
<div id="updateForm" class="card" style="display:none;margin-bottom:24px">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-key text-yellow" style="color:#E37400"></i> Otorisasi Update</div>
    </div>
    <div class="card-body">
        <div class="alert alert-info" style="margin-bottom:16px">
            <i class="fas fa-info-circle"></i>
            Update memerlukan <strong>key</strong> dari HPY Solution.
            Hubungi developer untuk mendapatkan key sebelum melanjutkan.
        </div>
        <div style="display:flex;gap:12px;align-items:flex-end">
            <div class="form-group" style="flex:1;margin-bottom:0">
                <label class="form-label">Key Update</label>
                <input type="password" id="updateKey" class="form-control"
                    placeholder="Masukkan key yang diberikan HPY Solution"
                    style="letter-spacing:2px">
            </div>
            <button id="btnUpdate" class="btn btn-primary" onclick="runUpdate()"
                style="height:42px;border-radius:8px;white-space:nowrap">
                <i class="fas fa-download"></i> Mulai Update
            </button>
        </div>
    </div>
</div>

{{-- Log output --}}
<div id="logCard" class="card" style="display:none">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-terminal"></i> Log Update</div>
        <span id="logStatus" class="badge"></span>
    </div>
    <div class="card-body" style="padding:0">
        <pre id="logOutput" style="font-family:'Courier New',monospace;font-size:12px;background:#1E1E1E;color:#D4D4D4;margin:0;padding:16px;border-radius:0 0 12px 12px;max-height:400px;overflow-y:auto;line-height:1.6;white-space:pre-wrap;word-break:break-all"></pre>
    </div>
</div>

@endsection

@push('scripts')
<script>
const CSRF      = document.querySelector('meta[name="csrf-token"]').content;
const localSha  = '{{ $local["sha"] }}';
let   latestSha = '';

async function checkLatest() {
    try {
        const res  = await fetch('{{ route("update.check") }}');
        const json = await res.json();

        document.getElementById('latestLoading').style.display = 'none';

        if (!json.success) throw new Error(json.error);

        latestSha = json.sha;
        document.getElementById('latestSha').textContent  = json.sha;
        document.getElementById('latestBadge').textContent = 'GITHUB';
        document.getElementById('latestBadge').className   = 'badge badge-green';

        if (json.date) {
            const d = new Date(json.date);
            document.getElementById('latestDate').innerHTML =
                `<i class="fas fa-clock" style="width:14px"></i> ${d.toLocaleDateString('id-ID', {day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})} WIB`;
        }
        if (json.message) {
            const msg = json.message.split('\n')[0].substring(0, 80);
            document.getElementById('latestMsg').innerHTML =
                `<i class="fas fa-code-commit" style="width:14px"></i> ${msg}`;
        }

        document.getElementById('latestInfo').style.display = 'block';

        // Bandingkan SHA
        showStatus(json.sha);

    } catch (e) {
        document.getElementById('latestLoading').style.display = 'none';
        document.getElementById('latestErrorMsg').textContent  = e.message;
        document.getElementById('latestError').style.display   = 'block';
        document.getElementById('latestBadge').textContent     = 'ERROR';
        document.getElementById('latestBadge').className       = 'badge badge-red';
    }
}

function showStatus(remoteSha) {
    const bar = document.getElementById('statusBar');
    bar.style.display = 'block';

    if (remoteSha === localSha || remoteSha === '') {
        bar.innerHTML = `
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <strong>Sistem sudah terbaru.</strong> Tidak ada pembaruan yang tersedia saat ini.
            </div>`;
        document.getElementById('updateForm').style.display = 'none';
    } else {
        bar.innerHTML = `
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Pembaruan tersedia!</strong>
                Versi lokal <code>${localSha}</code> berbeda dengan versi terbaru <code>${remoteSha}</code>.
            </div>`;
        document.getElementById('updateForm').style.display = 'block';
    }
}

async function runUpdate() {
    const key = document.getElementById('updateKey').value.trim();
    if (!key) {
        alert('Masukkan key update terlebih dahulu.');
        return;
    }

    if (!confirm('Sistem akan diperbarui dan semua cache akan dibersihkan. Lanjutkan?')) return;

    const btn = document.getElementById('btnUpdate');
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner"></span> Memperbarui...';

    document.getElementById('logCard').style.display  = 'block';
    document.getElementById('logOutput').textContent  = 'Memulai proses update...\n';
    document.getElementById('logStatus').textContent  = 'BERJALAN';
    document.getElementById('logStatus').className    = 'badge badge-yellow';

    try {
        const res  = await fetch('{{ route("update.run") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ key }),
        });
        const json = await res.json();

        const logEl = document.getElementById('logOutput');
        logEl.textContent = (json.log || []).join('\n');
        logEl.scrollTop   = logEl.scrollHeight;

        if (json.success) {
            document.getElementById('logStatus').textContent = '✓ SELESAI';
            document.getElementById('logStatus').className   = 'badge badge-green';
            document.getElementById('statusBar').innerHTML   = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <strong>Update berhasil!</strong>
                    Sistem sudah diperbarui ke versi terbaru. Refresh halaman untuk melihat perubahan.
                    <a href="{{ route('dashboard') }}" class="btn btn-success btn-sm" style="margin-left:12px">
                        <i class="fas fa-refresh"></i> Kembali ke Dashboard
                    </a>
                </div>`;
            document.getElementById('updateForm').style.display = 'none';
        } else {
            document.getElementById('logStatus').textContent = '✗ GAGAL';
            document.getElementById('logStatus').className   = 'badge badge-red';
            if (json.error) {
                logEl.textContent += '\n\n❌ ERROR: ' + json.error;
            }
        }
    } catch (e) {
        document.getElementById('logStatus').textContent = '✗ ERROR';
        document.getElementById('logStatus').className   = 'badge badge-red';
        document.getElementById('logOutput').textContent += '\n\n❌ ' + e.message;
    }

    btn.disabled  = false;
    btn.innerHTML = '<i class="fas fa-download"></i> Mulai Update';
    document.getElementById('updateKey').value = '';
}

// Auto-cek saat halaman dimuat
checkLatest();
</script>
@endpush
