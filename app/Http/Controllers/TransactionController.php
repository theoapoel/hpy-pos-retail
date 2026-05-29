<?php
namespace App\Http\Controllers;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller {
    public function index(Request $request) {
        $query = Transaction::with(['user','customer'])->latest();
        if ($request->search) {
            $query->where('invoice_no','LIKE',"%{$request->search}%");
        }
        if ($request->date_from) $query->whereDate('created_at','>=',$request->date_from);
        if ($request->date_to) $query->whereDate('created_at','<=',$request->date_to);
        if ($request->status) $query->where('status',$request->status);
        $transactions = $query->paginate(20);
        return view('transactions.index', compact('transactions'));
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
