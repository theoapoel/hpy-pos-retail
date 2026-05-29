<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_no', 'customer_id', 'billing_address', 'delivery_date',
        'notes', 'status',
        'erp_sales_order', 'erp_sync_status', 'erp_sync_error',
        'subtotal', 'total', 'created_by',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'subtotal'      => 'decimal:2',
        'total'         => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryOrderItem::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(DeliveryShipment::class)->orderBy('sequence');
    }

    public static function generateOrderNo(): string
    {
        $prefix = 'DO-' . now()->format('Ymd') . '-';
        $last   = static::where('order_no', 'like', $prefix . '%')->orderByDesc('id')->first();
        $seq    = $last ? (intval(substr($last->order_no, -4)) + 1) : 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    public function recalculateTotal(): void
    {
        $subtotal = $this->items()->sum('subtotal');
        $this->update(['subtotal' => $subtotal, 'total' => $subtotal]);
    }

    public function canEdit(): bool
    {
        return in_array($this->status, ['draft', 'confirmed']);
    }
}
