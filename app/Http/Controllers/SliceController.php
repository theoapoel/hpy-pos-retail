<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Slice;
use App\Models\SliceItem;
use App\Services\ErpNextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SliceController extends Controller
{
    public function index(Request $request)
    {
        $query = Slice::with('creator')->withCount('items')->orderByDesc('id');

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->search) {
            $query->where('slice_no', 'like', '%'.$request->search.'%');
        }

        $slices = $query->paginate(20)->withQueryString();

        return view('slices.index', compact('slices'));
    }

    public function create()
    {
        $products = Product::where('is_active', true)->inItemGroups('slice_item_groups')->orderBy('name')
            ->get(['id', 'name', 'sku', 'erp_item_code', 'unit']);

        return view('slices.create', compact('products'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.source_product_id' => 'required|integer|exists:products,id',
            'items.*.source_qty' => 'required|numeric|min:0.01',
            'items.*.target_product_id' => 'required|integer|exists:products,id',
            'items.*.target_qty' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string|max:255',
        ]);

        $slice = DB::transaction(function () use ($request) {
            $slice = Slice::create([
                'slice_no' => Slice::generateSliceNo(),
                'created_by' => auth()->id(),
                'status' => 'draft',
                'notes' => $request->notes,
            ]);

            foreach ($request->items as $row) {
                $source = Product::find($row['source_product_id']);
                $target = Product::find($row['target_product_id']);

                SliceItem::create([
                    'slice_id' => $slice->id,
                    'source_product_id' => $source->id,
                    'source_item_name' => $source->name,
                    'source_item_code' => $source->erp_item_code ?: $source->sku,
                    'source_qty' => $row['source_qty'],
                    'source_uom' => $source->unit ?: 'Nos',
                    'target_product_id' => $target->id,
                    'target_item_name' => $target->name,
                    'target_item_code' => $target->erp_item_code ?: $target->sku,
                    'target_qty' => $row['target_qty'],
                    'target_uom' => $target->unit ?: 'Nos',
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            return $slice;
        });

        return redirect()->route('slices.show', $slice)
            ->with('success', 'Slice '.$slice->slice_no.' berhasil dibuat.');
    }

    public function show(Slice $slice)
    {
        $slice->load('creator', 'items');

        return view('slices.show', ['slice' => $slice]);
    }

    public function submit(Slice $slice)
    {
        if ($slice->status !== 'draft') {
            return back()->with('error', 'Hanya draft yang bisa disubmit.');
        }

        $slice->load('items');

        $erp = new ErpNextService;
        if (! $erp->isConfigured()) {
            return back()->with('error', 'ERP HPY belum dikonfigurasi. Tidak dapat memproses konversi.');
        }

        $result = $erp->createRepackEntry($slice);

        if (! $result['success']) {
            return back()->with('error', 'Gagal memproses ke ERP: '.($result['error'] ?? 'Unknown error'));
        }

        $slice->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return back()->with('success', 'Slice diproses. Stock Entry ERP: '.($result['docname'] ?? 'synced').'.');
    }

    public function cancel(Slice $slice)
    {
        if ($slice->status === 'cancelled') {
            return back()->with('error', 'Sudah dibatalkan.');
        }

        // Draft: belum masuk ERP, cukup tandai dibatalkan.
        if ($slice->status !== 'submitted') {
            $slice->update(['status' => 'cancelled']);

            return back()->with('success', 'Slice dibatalkan.');
        }

        // Submitted: batalkan dulu Stock Entry (Repack) di ERP supaya stok balik.
        if ($slice->erp_stock_entry) {
            $erp = new ErpNextService;
            if (! $erp->isConfigured()) {
                return back()->with('error', 'ERP HPY belum dikonfigurasi. Tidak dapat membatalkan Stock Entry.');
            }

            $result = $erp->cancelRepackEntry($slice);
            if (! $result['success']) {
                return back()->with('error', 'Gagal membatalkan Stock Entry di ERP: '.($result['error'] ?? 'Unknown error'));
            }
        }

        $slice->update([
            'status' => 'cancelled',
            'erp_sync_status' => 'pending',
            'erp_sync_error' => null,
        ]);

        return back()->with('success', 'Slice dibatalkan dan Stock Entry ERP dibatalkan.');
    }

    public function syncErp(Slice $slice)
    {
        if ($slice->erp_sync_status === 'synced') {
            return response()->json(['success' => false, 'error' => 'Sudah pernah disync.']);
        }

        $slice->load('items');
        $erp = new ErpNextService;
        $result = $erp->createRepackEntry($slice);

        if ($result['success'] && $slice->status === 'draft') {
            $slice->update(['status' => 'submitted', 'submitted_at' => now()]);
        }

        return response()->json($result);
    }
}
