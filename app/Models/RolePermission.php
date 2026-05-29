<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class RolePermission extends Model
{
    protected $fillable = ['role', 'module', 'allowed'];
    protected $casts    = ['allowed' => 'boolean'];

    // All configurable modules (admin-only modules are excluded intentionally)
    public static function modules(): array
    {
        return [
            'dashboard'      => ['label' => 'Dashboard',       'icon' => 'fa-th-large'],
            'pos'            => ['label' => 'Kasir (POS)',      'icon' => 'fa-cash-register'],
            'transactions'   => ['label' => 'Transaksi',        'icon' => 'fa-receipt'],
            'products'       => ['label' => 'Produk',           'icon' => 'fa-box'],
            'customers'      => ['label' => 'Customer',         'icon' => 'fa-users'],
            'stock_transfer' => ['label' => 'Transfer Barang',  'icon' => 'fa-truck-loading'],
            'stock'          => ['label' => 'Stok Barang',      'icon' => 'fa-boxes'],
            'sync'           => ['label' => 'Sync HPY',         'icon' => 'fa-sync-alt'],
        ];
    }

    public static function forRole(string $role): array
    {
        return Cache::remember("role_permissions_{$role}", 300, function () use ($role) {
            return static::where('role', $role)->pluck('allowed', 'module')->toArray();
        });
    }

    public static function can(string $role, string $module): bool
    {
        $perms = static::forRole($role);
        // Fall back to sensible defaults if row missing (e.g. new module added)
        if (!array_key_exists($module, $perms)) {
            return $role === 'manager';
        }
        return (bool) $perms[$module];
    }

    public static function clearCache(?string $role = null): void
    {
        if ($role) {
            Cache::forget("role_permissions_{$role}");
            return;
        }
        // Clear all distinct roles stored in DB
        foreach (static::distinct('role')->pluck('role') as $r) {
            Cache::forget("role_permissions_{$r}");
        }
    }
}
