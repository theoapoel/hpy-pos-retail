<?php
namespace App\Http\Controllers;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller {
    public function index(Request $request) {
        $query = Transaction::with(['user','customer'])->latest();
        // Cakupan laporan (Pengaturan Toko): 'all' = semua transaksi toko,
        // 'user' = kasir non-manager hanya melihat transaksinya sendiri.
        $user = auth()->user();
        if (Setting::reportScopedByUser() && $user && ! $user->isManager()) {
            $query->where('user_id', $user->id);
        }
        if ($request->search) {
            $query->where('invoice_no','LIKE',"%{$request->search}%");
        }
        if ($request->erp_invoice) {
            $query->where('erp_pos_invoice','LIKE',"%{$request->erp_invoice}%");
        }
        if ($request->date_from) $query->whereDate('created_at','>=',$request->date_from);
        if ($request->date_to) $query->whereDate('created_at','<=',$request->date_to);
        if ($request->status) $query->where('status',$request->status);
        if ($request->payment_method) $query->where('payment_method',$request->payment_method);
        // Ringkasan seluruh hasil filter (bukan hanya halaman yang tampil).
        // Nilai uang dihitung dari transaksi selesai saja agar yang dibatalkan
        // tidak menggelembungkan total.
        $summary = (clone $query)->without(['user','customer'])->where('status','completed')
            ->selectRaw('COALESCE(SUM(total),0) as total_amount')
            ->reorder()->first();

        $transactions = $query->paginate(20)->withQueryString();

        // Keadaan invoice di ERP HPY untuk baris yang tampil saja (1 request per
        // halaman). Tujuannya menangkap invoice yang dibatalkan/dihapus di ERP
        // tetapi di sini masih tercatat selesai — pembatalan di ERP tidak mengubah
        // data lokal. Kalau ERP tidak bisa dihubungi, halaman tetap tampil dan
        // kolomnya diberi tanda "tidak terperiksa", bukan disimpulkan aman.
        $erpNames = collect($transactions->items())->pluck('erp_pos_invoice')->filter()->all();
        $erpCheck = $erpNames
            ? (new \App\Services\ErpNextService)->fetchPosInvoiceStates($erpNames)
            : ['success' => true, 'data' => []];
        $erpStates = $erpCheck['data'];
        $erpCheckFailed = ! $erpCheck['success'];

        // Pilihan tipe bayar = metode dari POS Profile ERP + metode yang pernah
        // dipakai di transaksi lama (agar data lama tetap bisa difilter).
        $paymentMethods = collect(pos_payment_methods())
            ->pluck('mode_of_payment')
            ->merge(Transaction::select('payment_method')->distinct()->pluck('payment_method'))
            ->map(fn ($m) => trim((string) $m))
            ->filter()
            // ERP menyimpan "Cash" sedangkan transaksi lama "CASH" — samakan agar
            // tidak muncul dua kali di dropdown (perbandingan DB sendiri case-insensitive).
            ->unique(fn ($m) => mb_strtoupper($m))
            ->sort(fn ($a, $b) => strcasecmp($a, $b))
            ->values();

        return view('transactions.index', compact('transactions','paymentMethods','summary','erpStates','erpCheckFailed'));
    }

    public function show(Transaction $transaction) {
        $transaction->load('items.product','customer','user');
        return view('transactions.show', compact('transaction'));
    }

    public function cancel(Transaction $transaction) {
        if ($transaction->status !== 'completed') {
            return response()->json(['success'=>false,'error'=>'Transaksi tidak bisa dibatalkan'],422);
        }
        // Restore stock
        foreach ($transaction->items as $item) {
            if ($item->product && $item->product->track_stock) {
                $item->product->increment('stock', $item->quantity);
            }
        }
        $transaction->update(['status'=>'cancelled']);
        return response()->json(['success'=>true]);
    }
}
