<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Category extends Model {
    protected $fillable = ['name','slug','color','icon','is_active','erp_item_group'];
    protected $casts = ['is_active'=>'boolean'];
    public function products(): HasMany { return $this->hasMany(Product::class); }
}
