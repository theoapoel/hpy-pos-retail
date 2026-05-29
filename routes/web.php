<?php

use App\Http\Controllers\{
    DashboardController, PosController, ProductController,
    CustomerController, TransactionController, ErpSyncController,
    StockTransferController, StockController, StockOpnameController,
    FactoryResetController, SettingsController,
    UserController, PermissionController, RoleController, WarehouseController,
    BackupController, SetupController, OnlineReportController
};
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

// ─── Setup Awal (tidak perlu auth, tidak perlu setup selesai) ────────────────
Route::get('/setup',                    [SetupController::class, 'index'])->name('setup.index');
Route::post('/setup',                   [SetupController::class, 'store'])->name('setup.store');
Route::post('/setup/test-connection',   [SetupController::class, 'testConnection'])->name('setup.test');

// ─── Auth ────────────────────────────────────────────────────────────────────
Route::get('/login', fn() => view('auth.login'))->name('login')->middleware('guest');
Route::post('/login', function (\Illuminate\Http\Request $req) {
    $req->validate(['email' => 'required|email']);
    $mode = $req->input('mode', 'online');

    // ── MODE OFFLINE: verifikasi PIN lokal ──────────────────────
    if ($mode === 'pin') {
        $req->validate(['pin' => 'required|digits:6']);

        $user = \App\Models\User::where('email', $req->email)
                    ->where('is_active', true)
                    ->first();

        if (!$user || $user->pin !== $req->pin) {
            return back()
                ->withInput($req->only('email', 'mode'))
                ->withErrors(['pin' => 'Email atau PIN tidak valid.']);
        }

        Auth::login($user, false);
        $req->session()->regenerate();
        $landing = $user->role === 'cashier' ? route('pos.index') : route('dashboard');
        return redirect()->intended($landing);
    }

    // ── MODE ONLINE: verifikasi password ke ERP HPY ─────────────
    $req->validate(['password' => 'required']);

    $erp    = new \App\Services\ErpNextService();
    $result = $erp->loginUser($req->email, $req->password);

    if (!$result['success']) {
        if (!empty($result['erp_unavailable'])) {
            return back()
                ->withInput($req->only('email'))
                ->withErrors(['email' => 'Server ERP HPY tidak dapat dihubungi. Gunakan login offline dengan PIN.']);
        }
        return back()
            ->withInput($req->only('email'))
            ->withErrors(['email' => $result['error']]);
    }

    $user = \App\Models\User::where('email', $req->email)->first();

    if (!$user) {
        // Auto-create: ambil detail role dari ERP HPY jika API key sudah dikonfigurasi
        $role = 'cashier';
        $apiKey = \App\Models\Setting::get('erpnext_api_key');
        if ($apiKey) {
            $info = $erp->getUserInfo($req->email);
            if ($info['success']) {
                $roleNames = $info['roles'] ?? [];
                if (in_array('System Manager', $roleNames))  $role = 'admin';
                elseif (in_array('POS Manager', $roleNames)) $role = 'manager';
            }
        } elseif (\App\Models\User::count() === 0) {
            // Tidak ada user sama sekali → user pertama jadi admin (bootstrap)
            $role = 'admin';
        }

        $user = \App\Models\User::create([
            'name'      => $result['full_name'],
            'email'     => $req->email,
            'password'  => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    if (!$user->is_active) {
        return back()
            ->withInput($req->only('email'))
            ->withErrors(['email' => 'Akun Anda dinonaktifkan. Hubungi administrator.']);
    }

    Auth::login($user, $req->boolean('remember'));
    $req->session()->regenerate();
    $landing = $user->role === 'cashier' ? route('pos.index') : route('dashboard');
    return redirect()->intended($landing);
})->name('login.post');

Route::post('/logout', function (\Illuminate\Http\Request $req) {
    Auth::logout();
    $req->session()->invalidate();
    $req->session()->regenerateToken();
    return redirect()->route('login');
})->name('logout');

// ─── Protected ───────────────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard')
        ->name('dashboard');

    // POS
    Route::prefix('pos')->name('pos.')->middleware('permission:pos')->group(function () {
        Route::get('/',                      [PosController::class, 'index'])->name('index');
        Route::get('/search-products',       [PosController::class, 'searchProducts'])->name('search-products');
        Route::post('/checkout',             [PosController::class, 'checkout'])->name('checkout');
        Route::get('/receipt/{transaction}', [PosController::class, 'receipt'])->name('receipt');
        Route::get('/print/{transaction}',   [PosController::class, 'printReceipt'])->name('print');
    });

    // Transaksi
    Route::middleware('permission:transactions')->group(function () {
        Route::get('/transactions',               [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    });
    Route::post('/transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])
        ->middleware('role:admin,manager')
        ->name('transactions.cancel');

    // Produk
    Route::middleware('permission:products')->group(function () {
        Route::resource('products', ProductController::class)->except(['show']);
    });

    // Customer
    Route::middleware('permission:customers')->group(function () {
        Route::get('/customers/search', [CustomerController::class, 'search'])->name('customers.search');
        Route::resource('customers', CustomerController::class)->except(['show', 'create', 'edit']);
    });

    // Stock
    Route::middleware('permission:stock')->group(function () {
        Route::get('/stock',                              [StockController::class, 'index'])->name('stock.index');
        Route::post('/stock/sync-bin',                    [StockController::class, 'syncFromBin'])->name('stock.sync-bin');
        Route::post('/stock/sync-warehouse/{warehouse}',  [StockController::class, 'syncWarehouse'])->name('stock.sync-warehouse');
    });
    Route::get('/stock/debug-bin',  [StockController::class, 'debugBinEndpoint'])->name('stock.debug-bin');
    Route::get('/stock/debug-sync', [StockController::class, 'debugSync'])->name('stock.debug-sync');

    // Stock Opname
    Route::prefix('stock-opname')->name('stock-opname.')->middleware('permission:stock')->group(function () {
        Route::get('/',                         [StockOpnameController::class, 'index'])->name('index');
        Route::get('/create',                   [StockOpnameController::class, 'create'])->name('create');
        Route::post('/',                        [StockOpnameController::class, 'store'])->name('store');
        Route::get('/{stockOpname}',            [StockOpnameController::class, 'show'])->name('show');
        Route::post('/{stockOpname}/items',     [StockOpnameController::class, 'updateItems'])->name('update-items');
        Route::post('/{stockOpname}/submit',    [StockOpnameController::class, 'submit'])->name('submit');
        Route::post('/{stockOpname}/cancel',    [StockOpnameController::class, 'cancel'])->name('cancel');
    });

    // Stock Transfer
    Route::prefix('stock-transfer')->name('stock-transfer.')->middleware('permission:stock_transfer')->group(function () {
        Route::get('/',            [StockTransferController::class, 'index'])->name('index');
        Route::get('/send',        [StockTransferController::class, 'createSend'])->name('send.create');
        Route::post('/send',       [StockTransferController::class, 'storeSend'])->name('send.store');
        Route::get('/receive',     [StockTransferController::class, 'createReceive'])->name('receive.create');
        Route::post('/receive',    [StockTransferController::class, 'storeReceive'])->name('receive.store');
        Route::post('/load-items', [StockTransferController::class, 'loadEntryItems'])->name('load-items');
        Route::get('/{stockTransfer}',        [StockTransferController::class, 'show'])->name('show');
        Route::post('/{stockTransfer}/retry', [StockTransferController::class, 'retry'])->name('retry');
    });

    // Laporan Online — data historis langsung dari ERPNext
    Route::prefix('online-report')->name('online-report.')->middleware('permission:sync')->group(function () {
        Route::get('/',             [OnlineReportController::class, 'index'])->name('index');
        Route::post('/fetch',       [OnlineReportController::class, 'fetch'])->name('fetch');
        Route::get('/detail/{name}', [OnlineReportController::class, 'detail'])->name('detail')
            ->where('name', '.+');
    });

    // Sync HPY — jalankan (permission:sync), konfigurasi (admin only)
    Route::prefix('sync')->name('sync.')->group(function () {
        Route::middleware('permission:sync')->group(function () {
            Route::get('/',                           [ErpSyncController::class, 'index'])->name('index');
            Route::post('/all',                       [ErpSyncController::class, 'syncAll'])->name('all');
            Route::post('/transaction/{transaction}', [ErpSyncController::class, 'syncSingle'])->name('single');
            Route::post('/retry-failed',              [ErpSyncController::class, 'retryFailed'])->name('retry');
            Route::post('/pull-products',             [ErpSyncController::class, 'pullProducts'])->name('pull-products');
            Route::post('/pull-payment-methods',      [ErpSyncController::class, 'pullPaymentMethods'])->name('pull-payment-methods');
            Route::post('/pull-users',                [ErpSyncController::class, 'pullUsers'])->name('pull-users');
            Route::post('/pull-delivery-prices',      [ErpSyncController::class, 'pullDeliveryPrices'])->name('pull-delivery-prices');
            Route::post('/push-customer/{customer}',  [ErpSyncController::class, 'pushCustomer'])->name('push-customer');
        });
        Route::middleware('role:admin')->group(function () {
            Route::post('/test-connection', [ErpSyncController::class, 'testConnection'])->name('test');
            Route::post('/settings',        [ErpSyncController::class, 'saveSettings'])->name('settings');
            Route::get('/logs',             [ErpSyncController::class, 'logs'])->name('logs');
        });
    });

    // ─── Admin only ──────────────────────────────────────────────────────────
    Route::middleware('role:admin')->group(function () {

        // Pengaturan Toko
        Route::get('/settings',  [SettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingsController::class, 'save'])->name('settings.save');

        // Hak Akses
        Route::get('/permissions',  [PermissionController::class, 'index'])->name('permissions.index');
        Route::post('/permissions', [PermissionController::class, 'save'])->name('permissions.save');

        // Backup & Restore
        Route::prefix('backup')->name('backup.')->group(function () {
            Route::get('/download', [BackupController::class, 'download'])->name('download');
            Route::get('/restore',  [BackupController::class, 'restorePage'])->name('restore');
            Route::post('/restore', [BackupController::class, 'restore'])->name('restore.post');
        });

        // Factory Reset
        Route::prefix('factory-reset')->name('factory-reset.')->group(function () {
            Route::get('/',         [FactoryResetController::class, 'index'])->name('index');
            Route::get('/counts',   [FactoryResetController::class, 'counts'])->name('counts');
            Route::post('/verify',  [FactoryResetController::class, 'verify'])->name('verify');
            Route::post('/execute', [FactoryResetController::class, 'execute'])->name('execute');
        });

        // Manajemen User
        Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::resource('users', UserController::class)->except(['show']);

        // Manajemen Role
        Route::post('/roles/slug', [RoleController::class, 'generateSlug'])->name('roles.slug');
        Route::resource('roles', RoleController::class)->except(['show', 'create', 'edit']);

        // Warehouse Assignment
        Route::prefix('warehouses')->name('warehouses.')->group(function () {
            Route::get('/',                          [WarehouseController::class, 'index'])->name('index');
            Route::post('/pull',                     [WarehouseController::class, 'pull'])->name('pull');
            Route::post('/{warehouse}/toggle',       [WarehouseController::class, 'toggle'])->name('toggle');
            Route::post('/{warehouse}/set-default',  [WarehouseController::class, 'setDefault'])->name('set-default');
            Route::post('/{warehouse}/set-transit',  [WarehouseController::class, 'setTransit'])->name('set-transit');
            Route::post('/{warehouse}/clear-flag',   [WarehouseController::class, 'clearFlag'])->name('clear-flag');
        });
    });
});
