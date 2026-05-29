<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'HPYSync')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --blue: #4285F4;
            --red: #EA4335;
            --yellow: #FBBC05;
            --green: #34A853;
            --blue-dark: #1967D2;
            --blue-light: #E8F0FE;
            --bg: #F8F9FA;
            --surface: #FFFFFF;
            --surface2: #F1F3F4;
            --border: #DADCE0;
            --text: #202124;
            --text2: #5F6368;
            --text3: #80868B;
            --shadow-sm: 0 1px 3px rgba(60,64,67,.15),0 1px 2px rgba(60,64,67,.1);
            --shadow: 0 2px 6px 2px rgba(60,64,67,.15),0 1px 2px rgba(60,64,67,.2);
            --shadow-lg: 0 4px 12px 3px rgba(60,64,67,.15),0 2px 4px rgba(60,64,67,.2);
            --radius: 8px;
            --radius-lg: 12px;
            --nav-w: 240px;
            --header-h: 60px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Roboto', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        /* HEADER */
        .header {
            position: fixed; top: 0; left: 0; right: 0; height: var(--header-h);
            background: var(--surface); border-bottom: 1px solid var(--border);
            display: flex; align-items: center; padding: 0 16px; z-index: 100;
            box-shadow: var(--shadow-sm);
        }
        .header-brand { display: flex; align-items: center; gap: 8px; text-decoration: none; width: var(--nav-w); }
        .brand-logo { width: 32px; height: 32px; display: flex; }
        .brand-text { font-family: 'Google Sans', sans-serif; font-size: 18px; font-weight: 700; }
        .brand-text span:nth-child(1) { color: #4285F4; }
        .brand-text span:nth-child(2) { color: #EA4335; }
        .brand-text span:nth-child(3) { color: #FBBC05; }
        .brand-text span:nth-child(4) { color: #4285F4; }
        .brand-text span:nth-child(5) { color: #34A853; }
        .brand-text span:nth-child(6) { color: #EA4335; }
        .brand-text span:nth-child(7) { color: #4285F4; }
        .header-right { margin-left: auto; display: flex; align-items: center; gap: 16px; }
        .sync-badge { background: var(--blue-light); color: var(--blue); font-size: 12px; padding: 4px 10px; border-radius: 12px; font-weight: 500; cursor: pointer; }
        .sync-badge.warn { background: #FEF3E2; color: #E37400; }
        .user-menu { display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 6px 10px; border-radius: 20px; transition: background .2s; }
        .user-menu:hover { background: var(--surface2); }
        .user-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--blue); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; }
        .user-name { font-size: 14px; font-weight: 500; }

        /* SIDEBAR */
        .sidebar {
            position: fixed; top: var(--header-h); left: 0; width: var(--nav-w);
            height: calc(100vh - var(--header-h)); background: var(--surface);
            border-right: 1px solid var(--border); padding: 8px 0; overflow-y: auto; z-index: 90;
        }
        .nav-section { padding: 16px 12px 4px; font-size: 11px; font-weight: 700; color: var(--text3); text-transform: uppercase; letter-spacing: .8px; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 16px; text-decoration: none; color: var(--text2); font-size: 14px; font-weight: 500; border-radius: 0 24px 24px 0; margin-right: 8px; transition: all .2s; }
        .nav-item:hover { background: var(--surface2); color: var(--text); }
        .nav-item.active { background: var(--blue-light); color: var(--blue); }
        .nav-item.active .nav-icon { color: var(--blue); }
        .nav-icon { width: 20px; text-align: center; font-size: 16px; }
        .nav-badge { margin-left: auto; background: var(--red); color: #fff; font-size: 11px; padding: 2px 7px; border-radius: 10px; font-weight: 700; }

        /* MAIN CONTENT */
        .main { margin-left: var(--nav-w); margin-top: var(--header-h); padding: 24px; min-height: calc(100vh - var(--header-h)); }
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .page-title { font-family: 'Google Sans', sans-serif; font-size: 24px; font-weight: 700; color: var(--text); }
        .page-subtitle { font-size: 13px; color: var(--text3); margin-top: 2px; }

        /* CARDS */
        .card { background: var(--surface); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); border: 1px solid var(--border); }
        .card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .card-title { font-family: 'Google Sans', sans-serif; font-size: 15px; font-weight: 700; }
        .card-body { padding: 20px; }

        /* STAT CARDS */
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border); padding: 20px; display: flex; align-items: center; gap: 16px; box-shadow: var(--shadow-sm); transition: box-shadow .2s; }
        .stat-card:hover { box-shadow: var(--shadow); }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .stat-icon.blue { background: var(--blue-light); color: var(--blue); }
        .stat-icon.green { background: #E6F4EA; color: var(--green); }
        .stat-icon.yellow { background: #FEF3E2; color: #E37400; }
        .stat-icon.red { background: #FCE8E6; color: var(--red); }
        .stat-value { font-family: 'Google Sans', sans-serif; font-size: 22px; font-weight: 700; line-height: 1; }
        .stat-label { font-size: 12px; color: var(--text3); margin-top: 4px; font-weight: 500; }

        /* BUTTONS */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 500; cursor: pointer; border: none; text-decoration: none; transition: all .2s; font-family: 'Google Sans', sans-serif; }
        .btn-primary { background: var(--blue); color: #fff; }
        .btn-primary:hover { background: var(--blue-dark); box-shadow: var(--shadow-sm); }
        .btn-success { background: var(--green); color: #fff; }
        .btn-success:hover { background: #2D9247; }
        .btn-danger { background: var(--red); color: #fff; }
        .btn-danger:hover { background: #C5221F; }
        .btn-outline { background: transparent; color: var(--blue); border: 1px solid var(--blue); }
        .btn-outline:hover { background: var(--blue-light); }
        .btn-ghost { background: transparent; color: var(--text2); }
        .btn-ghost:hover { background: var(--surface2); }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .btn-lg { padding: 12px 24px; font-size: 15px; border-radius: 24px; }

        /* TABLES */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        thead tr { border-bottom: 2px solid var(--border); }
        th { padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 700; color: var(--text3); text-transform: uppercase; letter-spacing: .5px; }
        td { padding: 12px 16px; border-bottom: 1px solid var(--border); color: var(--text); }
        tbody tr:hover { background: #F8F9FA; }
        tbody tr:last-child td { border-bottom: none; }

        /* BADGES */
        .badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-blue { background: var(--blue-light); color: var(--blue); }
        .badge-green { background: #E6F4EA; color: var(--green); }
        .badge-red { background: #FCE8E6; color: var(--red); }
        .badge-yellow { background: #FEF3E2; color: #E37400; }
        .badge-gray { background: var(--surface2); color: var(--text2); }

        /* FORMS */
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: var(--text2); margin-bottom: 6px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: var(--radius); font-size: 14px; color: var(--text); background: var(--surface); transition: border-color .2s, box-shadow .2s; font-family: 'Roboto', sans-serif; }
        .form-control:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(66,133,244,.15); }
        .form-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%235F6368'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; background-size: 20px; padding-right: 36px; }

        /* ALERTS */
        .alert { padding: 12px 16px; border-radius: var(--radius); margin-bottom: 16px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: #E6F4EA; color: #137333; border-left: 4px solid var(--green); }
        .alert-danger { background: #FCE8E6; color: #C5221F; border-left: 4px solid var(--red); }
        .alert-warning { background: #FEF3E2; color: #B06000; border-left: 4px solid var(--yellow); }
        .alert-info { background: var(--blue-light); color: var(--blue-dark); border-left: 4px solid var(--blue); }

        /* MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 1000; display: none; align-items: center; justify-content: center; }
        .modal-overlay.show { display: flex; }
        .modal { background: var(--surface); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); max-width: 480px; width: 90%; max-height: 90vh; overflow-y: auto; animation: modalIn .2s ease; }
        @keyframes modalIn { from { opacity:0; transform:translateY(-20px) scale(.97); } to { opacity:1; transform:none; } }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .modal-title { font-family:'Google Sans',sans-serif; font-size: 18px; font-weight: 700; }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; }

        /* TOASTS */
        #toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
        .toast { background: var(--text); color: #fff; padding: 14px 20px; border-radius: var(--radius); font-size: 14px; box-shadow: var(--shadow-lg); display: flex; align-items: center; gap: 10px; animation: toastIn .3s ease; min-width: 280px; }
        .toast.success { background: var(--green); }
        .toast.error { background: var(--red); }
        .toast.warning { background: #E37400; }
        @keyframes toastIn { from { opacity:0; transform:translateX(40px); } to { opacity:1; transform:none; } }

        /* UTILITIES */
        .text-blue { color: var(--blue); }
        .text-green { color: var(--green); }
        .text-red { color: var(--red); }
        .text-muted { color: var(--text3); }
        .font-bold { font-weight: 700; }
        .font-medium { font-weight: 500; }
        .text-sm { font-size: 13px; }
        .text-xs { font-size: 11px; }
        .flex { display: flex; }
        .items-center { align-items: center; }
        .gap-2 { gap: 8px; }
        .gap-3 { gap: 12px; }
        .mt-1 { margin-top: 4px; }
        .mt-2 { margin-top: 8px; }
        .mb-3 { margin-bottom: 12px; }
        .mb-4 { margin-bottom: 16px; }
        .p-4 { padding: 16px; }
        .rounded { border-radius: var(--radius); }
        .w-full { width: 100%; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        .spinner { width: 18px; height: 18px; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .divider { border: none; border-top: 1px solid var(--border); margin: 16px 0; }
        .money { font-family: 'Google Sans', sans-serif; font-weight: 700; }
    </style>
    @stack('styles')
</head>
<body>
    <!-- Header -->
    <header class="header">
        <a href="{{ route('dashboard') }}" class="header-brand">
            <img src="{{ asset('images/happypos.png') }}" alt="HPYSync"
                style="height:52px;width:auto;object-fit:contain;">
        </a>
        <div class="header-right">
            @php $pendingSync = \App\Models\Transaction::where('erp_sync_status','pending')->where('status','completed')->count(); @endphp
            @if($pendingSync > 0)
            <a href="{{ route('sync.index') }}" class="sync-badge warn">
                <i class="fas fa-sync-alt"></i> {{ $pendingSync }} Pending Sync
            </a>
            @endif
            <div class="user-menu">
                <div class="user-avatar">{{ substr(auth()->user()->name, 0, 1) }}</div>
                <span class="user-name">{{ auth()->user()->name }}</span>
                <i class="fas fa-chevron-down text-xs text-muted"></i>
            </div>
            <form method="POST" action="{{ route('logout') }}" style="display:inline">
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-sign-out-alt"></i></button>
            </form>
        </div>
    </header>

    <!-- Sidebar -->
    <nav class="sidebar">
        @php
            $u         = auth()->user();
            $role      = $u?->role;
            $roleModel = \App\Models\Role::where('name', $role)->first();
            $roleColor = $roleModel?->color ?? '#4285F4';
            $canDashboard     = $u->hasPermission('dashboard');
            $canPos           = $u->hasPermission('pos');
            $canTransactions  = $u->hasPermission('transactions');
            $canProducts      = $u->hasPermission('products');
            $canCustomers     = $u->hasPermission('customers');
            $canStockTransfer = $u->hasPermission('stock_transfer');
            $canStock         = $u->hasPermission('stock');
            $canSync          = $u->hasPermission('sync');
            $showManajemen    = $canProducts || $canCustomers || $canStockTransfer || $canStock;
        @endphp

        <div class="nav-section">Menu</div>
        @if($canDashboard)
        <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i class="fas fa-th-large nav-icon"></i> Dashboard
        </a>
        @endif
        @if($canPos)
        <a href="{{ route('pos.index') }}" class="nav-item {{ request()->routeIs('pos.*') ? 'active' : '' }}">
            <i class="fas fa-cash-register nav-icon"></i> Kasir (POS)
        </a>
        @endif
        @if($canTransactions)
        <a href="{{ route('transactions.index') }}" class="nav-item {{ request()->routeIs('transactions.*') ? 'active' : '' }}">
            <i class="fas fa-receipt nav-icon"></i> Transaksi
        </a>
        @endif

        @if($showManajemen)
        <div class="nav-section">Manajemen</div>
        @if($canProducts)
        <a href="{{ route('products.index') }}" class="nav-item {{ request()->routeIs('products.*') ? 'active' : '' }}">
            <i class="fas fa-box nav-icon"></i> Produk
        </a>
        @endif
        @if($canCustomers)
        <a href="{{ route('customers.index') }}" class="nav-item {{ request()->routeIs('customers.*') ? 'active' : '' }}">
            <i class="fas fa-users nav-icon"></i> Customer
        </a>
        @endif
        @if($canStock)
        <a href="{{ route('stock.index') }}" class="nav-item {{ request()->routeIs('stock.index') || request()->routeIs('stock.debug*') || request()->routeIs('stock.sync*') ? 'active' : '' }}">
            <i class="fas fa-boxes nav-icon"></i> Stok Barang
        </a>
        <a href="{{ route('stock-opname.index') }}" class="nav-item {{ request()->routeIs('stock-opname.*') ? 'active' : '' }}">
            <i class="fas fa-clipboard-list nav-icon"></i> Stock Opname
        </a>
        @endif
        @if($canStockTransfer)
        <a href="{{ route('stock-transfer.index') }}" class="nav-item {{ request()->routeIs('stock-transfer.*') ? 'active' : '' }}">
            <i class="fas fa-truck-loading nav-icon"></i> Transfer Barang
        </a>
        @endif
        @endif

        @if($canSync)
        <div class="nav-section">Integrasi</div>
        <a href="{{ route('sync.index') }}" class="nav-item {{ request()->routeIs('sync.*') ? 'active' : '' }}">
            <i class="fas fa-sync-alt nav-icon"></i> Sync HPY
            @if($pendingSync > 0)
                <span class="nav-badge">{{ $pendingSync }}</span>
            @endif
        </a>
        @endif

        @if($role === 'admin')
        <div class="nav-section">Sistem</div>
        <a href="{{ route('users.index') }}" class="nav-item {{ request()->routeIs('users.*') ? 'active' : '' }}">
            <i class="fas fa-users-cog nav-icon"></i> Manajemen User
        </a>
        <a href="{{ route('roles.index') }}" class="nav-item {{ request()->routeIs('roles.*') ? 'active' : '' }}">
            <i class="fas fa-layer-group nav-icon"></i> Manajemen Role
        </a>
        <a href="{{ route('permissions.index') }}" class="nav-item {{ request()->routeIs('permissions.*') ? 'active' : '' }}">
            <i class="fas fa-shield-alt nav-icon"></i> Hak Akses
        </a>
        <a href="{{ route('warehouses.index') }}" class="nav-item {{ request()->routeIs('warehouses.*') ? 'active' : '' }}">
            <i class="fas fa-warehouse nav-icon"></i> Warehouse
        </a>
        <a href="{{ route('settings.index') }}" class="nav-item {{ request()->routeIs('settings.*') ? 'active' : '' }}">
            <i class="fas fa-store nav-icon"></i> Pengaturan Toko
        </a>
        <a href="{{ route('backup.restore') }}" class="nav-item {{ request()->routeIs('backup.*') ? 'active' : '' }}">
            <i class="fas fa-upload nav-icon"></i> Restore Backup
        </a>
        <a href="{{ route('factory-reset.index') }}"
            class="nav-item {{ request()->routeIs('factory-reset.*') ? 'active' : '' }}"
            style="{{ request()->routeIs('factory-reset.*') ? '' : 'color:#EA4335;' }}"
            onmouseenter="this.style.color='#EA4335'"
            onmouseleave="this.style.color='{{ request()->routeIs('factory-reset.*') ? 'var(--blue)' : '#EA4335' }}'">
            <i class="fas fa-trash-alt nav-icon" style="color:#EA4335;"></i> Factory Reset
        </a>
        @endif

        {{-- User badge di bawah sidebar --}}
        <div style="position:absolute;bottom:0;left:0;right:0;padding:12px 16px;border-top:1px solid var(--border);background:var(--surface)">
            <div style="display:flex;align-items:center;gap:8px">
                <div style="width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;color:#fff;flex-shrink:0;
                    background:{{ $roleColor }}">
                    {{ strtoupper(substr($u->name, 0, 1)) }}
                </div>
                <div style="overflow:hidden;flex:1">
                    <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $u->name }}</div>
                    <div style="font-size:11px;color:var(--text3);text-transform:capitalize">{{ $role }}</div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main -->
    <main class="main">
        @if(session('success'))
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> {{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> {{ session('error') }}</div>
        @endif
        @yield('content')
    </main>

    <!-- Toast Container -->
    <div id="toast-container"></div>

    <script>
    // Global helpers
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const api = {
        async post(url, data={}) {
            const r = await fetch(url, {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
                body: JSON.stringify(data)
            });
            return r.json();
        },
        async get(url) {
            const r = await fetch(url, { headers: {'Accept':'application/json','X-CSRF-TOKEN':csrf} });
            return r.json();
        }
    };

    function toast(msg, type='success', dur=3500) {
        const c = document.getElementById('toast-container');
        const t = document.createElement('div');
        t.className = `toast ${type}`;
        const icons = {success:'check-circle', error:'exclamation-circle', warning:'exclamation-triangle'};
        t.innerHTML = `<i class="fas fa-${icons[type]||'info-circle'}"></i> ${msg}`;
        c.appendChild(t);
        setTimeout(() => { t.style.opacity='0'; t.style.transform='translateX(40px)'; t.style.transition='.3s'; setTimeout(()=>t.remove(),300); }, dur);
    }

    function formatMoney(n) {
        return 'Rp ' + parseFloat(n||0).toLocaleString('id-ID',{minimumFractionDigits:0});
    }
    </script>
    @stack('scripts')
</body>
</html>
