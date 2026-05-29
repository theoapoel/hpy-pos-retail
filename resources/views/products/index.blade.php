{{-- resources/views/products/index.blade.php --}}
@extends('layouts.app')
@section('title','Produk')
@push('styles')
<style>
    .pagination-wrap { padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 12px; }
    .pagination-info { font-size: 13px; color: var(--text3); }
    .pagination { display: flex; align-items: center; gap: 4px; list-style: none; }
    .pagination li a, .pagination li span {
        display: flex; align-items: center; justify-content: center;
        min-width: 36px; height: 36px; border-radius: 8px; padding: 0 10px;
        font-size: 13px; font-weight: 600; text-decoration: none;
        color: var(--text2); border: 1px solid var(--border);
        background: var(--surface); transition: all .2s; gap: 5px;
    }
    .pagination li a:hover { background: var(--blue-light); color: var(--blue); border-color: var(--blue); }
    .pagination li.active span { background: var(--blue); color: #fff; border-color: var(--blue); }
    .pagination li.disabled span { opacity: .4; cursor: not-allowed; }
</style>
@endpush

@section('content')
<div class="page-header">
    <div><div class="page-title"><i class="fas fa-box text-blue"></i> Manajemen Produk</div></div>
    <a href="{{ route('products.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah Produk</a>
</div>
<div class="card">
    <div class="card-header">
        <form method="GET" style="display:flex;gap:10px;flex:1">
            <input type="text" name="search" class="form-control" placeholder="Cari produk..." value="{{ request('search') }}" style="max-width:280px">
            <select name="category_id" class="form-control form-select" style="max-width:160px">
                <option value="">Semua Kategori</option>
                @foreach($categories as $cat)<option value="{{ $cat->id }}" {{ request('category_id')==$cat->id?'selected':'' }}>{{ $cat->name }}</option>@endforeach
            </select>
            <button type="submit" class="btn btn-outline"><i class="fas fa-search"></i> Cari</button>
        </form>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Produk</th><th>SKU</th><th>Kategori</th><th>Harga</th><th>Stok</th><th>Status Sync</th><th>Aktif</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse($products as $p)
            <tr>
                <td class="font-medium">{{ $p->name }}</td>
                <td class="text-sm text-muted font-medium">{{ $p->sku }}</td>
                <td>{{ $p->category?->name ?? '-' }}</td>
                <td class="money text-blue">Rp {{ number_format($p->price,0,',','.') }}</td>
                <td><span class="{{ $p->isLowStock() ? 'text-red font-bold' : '' }}">{{ $p->track_stock ? $p->stock.' '.$p->unit : '∞' }}</span></td>
                <td>
                    @if($p->erp_item_code)
                        <span class="badge badge-green">✓ Synced</span>
                    @else
                        <span class="badge badge-gray">Local</span>
                    @endif
                </td>
                <td><span class="badge {{ $p->is_active?'badge-green':'badge-red' }}">{{ $p->is_active?'Aktif':'Nonaktif' }}</span></td>
                <td style="white-space:nowrap">
                    <a href="{{ route('products.edit',$p) }}" class="btn btn-ghost btn-sm"><i class="fas fa-edit"></i></a>
                </td>
            </tr>
            @empty
            <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text3)">Belum ada produk</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($products->hasPages())
<div class="pagination-wrap">
    <div class="pagination-info">
        Menampilkan {{ $products->firstItem() }}–{{ $products->lastItem() }}
        dari <strong>{{ $products->total() }}</strong> produk
    </div>
    <ul class="pagination">
        <li class="{{ $products->onFirstPage() ? 'disabled' : '' }}">
            @if($products->onFirstPage())
                <span><i class="fas fa-chevron-left" style="font-size:10px"></i> Prev</span>
            @else
                <a href="{{ $products->previousPageUrl() }}"><i class="fas fa-chevron-left" style="font-size:10px"></i> Prev</a>
            @endif
        </li>
        @foreach($products->getUrlRange(max(1,$products->currentPage()-2), min($products->lastPage(),$products->currentPage()+2)) as $page => $url)
        <li class="{{ $page==$products->currentPage()?'active':'' }}">
            @if($page==$products->currentPage())
                <span>{{ $page }}</span>
            @else
                <a href="{{ $url }}">{{ $page }}</a>
            @endif
        </li>
        @endforeach
        <li class="{{ !$products->hasMorePages() ? 'disabled' : '' }}">
            @if($products->hasMorePages())
                <a href="{{ $products->nextPageUrl() }}">Next <i class="fas fa-chevron-right" style="font-size:10px"></i></a>
            @else
                <span>Next <i class="fas fa-chevron-right" style="font-size:10px"></i></span>
            @endif
        </li>
    </ul>
</div>
@endif
</div>
@endsection
