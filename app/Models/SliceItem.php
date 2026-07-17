<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SliceItem extends Model
{
    protected $fillable = [
        'slice_id',
        'source_product_id', 'source_item_name', 'source_item_code', 'source_qty', 'source_uom',
        'target_product_id', 'target_item_name', 'target_item_code', 'target_qty', 'target_uom',
        'notes',
    ];

    protected $casts = [
        'source_qty' => 'decimal:2',
        'target_qty' => 'decimal:2',
    ];

    public function slice()
    {
        return $this->belongsTo(Slice::class);
    }

    public function sourceProduct()
    {
        return $this->belongsTo(Product::class, 'source_product_id');
    }

    public function targetProduct()
    {
        return $this->belongsTo(Product::class, 'target_product_id');
    }
}
