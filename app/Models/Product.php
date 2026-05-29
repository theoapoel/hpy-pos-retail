<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'sku', 'barcode', 'name', 'description', 'category_id',
        'price', 'cost_price', 'stock', 'min_stock', 'unit',
        'image', 'erp_image', 'is_active', 'track_stock', 'tax_rate',
        'erp_item_code', 'erp_last_sync',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'is_active' => 'boolean',
        'track_stock' => 'boolean',
        'erp_last_sync' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function stockInWarehouse(int $warehouseId): int
    {
        return $this->productStocks->firstWhere('warehouse_id', $warehouseId)?->quantity ?? 0;
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && $this->stock <= $this->min_stock;
    }

    public function scopeActive($query) { return $query->where('is_active', true); }
    public function scopeSearch($query, $term) {
        return $query->where(function($q) use ($term) {
            $q->where('name', 'LIKE', "%{$term}%")
              ->orWhere('sku', 'LIKE', "%{$term}%")
              ->orWhere('barcode', 'LIKE', "%{$term}%");
        });
    }
}
