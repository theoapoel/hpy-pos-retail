<?php

namespace App\Http\Controllers;

use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderPayment;
use App\Services\ErpNextService;
use Illuminate\Http\Request;

class DeliveryOrderPaymentController extends Controller
{
    public function store(Request $request, DeliveryOrder $deliveryOrder)
    {
        if ($deliveryOrder->status === 'cancelled') {
            return back()->with('error', 'Order sudah dibatalkan.');
        }

        $request->validate([
            'payment_method' => 'required|string|max:100',
            'amount'         => 'required|numeric|min:1',
            'payment_date'   => 'required|date',
            'reference_no'   => 'nullable|string|max:100',
            'notes'          => 'nullable|string|max:500',
        ]);

        $payment = DeliveryOrderPayment::create([
            'delivery_order_id' => $deliveryOrder->id,
            'payment_method'    => $request->payment_method,
            'amount'            => $request->amount,
            'payment_date'      => $request->payment_date,
            'reference_no'      => $request->reference_no,
            'notes'             => $request->notes,
            'erp_sync_status'   => 'none',
            'created_by'        => auth()->id(),
        ]);

        $deliveryOrder->recalculatePaymentStatus();

        // Auto-sync jika SO ERP sudah ada
        if ($deliveryOrder->erp_sales_order) {
            try {
                $erp    = new ErpNextService();
                $result = $erp->createPaymentEntry($payment);
                if (!$result['success']) {
                    return back()->with('warning', 'Payment disimpan, tapi sync ERP gagal: ' . ($result['error'] ?? ''));
                }
            } catch (\Exception $e) {
                return back()->with('warning', 'Payment disimpan, tapi sync ERP gagal: ' . $e->getMessage());
            }
        }

        return back()->with('success', 'Payment Rp ' . number_format($request->amount, 0, ',', '.') . ' berhasil ditambahkan.');
    }

    public function destroy(DeliveryOrder $deliveryOrder, DeliveryOrderPayment $payment)
    {
        if ($payment->delivery_order_id !== $deliveryOrder->id) {
            abort(404);
        }
        if ($payment->erp_sync_status === 'synced') {
            return back()->with('error', 'Payment yang sudah tersync ke ERP tidak dapat dihapus. Batalkan di ERP HPY terlebih dahulu.');
        }

        $payment->delete();
        $deliveryOrder->recalculatePaymentStatus();

        return back()->with('success', 'Payment berhasil dihapus.');
    }

    public function sync(DeliveryOrder $deliveryOrder, DeliveryOrderPayment $payment)
    {
        if ($payment->delivery_order_id !== $deliveryOrder->id) {
            abort(404);
        }
        if ($payment->erp_sync_status === 'synced') {
            return response()->json(['success' => false, 'error' => 'Payment sudah pernah disync.']);
        }
        if (!$deliveryOrder->erp_sales_order) {
            return response()->json(['success' => false, 'error' => 'Sales Order ERP belum ada. Konfirmasi order terlebih dahulu.']);
        }

        $erp    = new ErpNextService();
        $result = $erp->createPaymentEntry($payment);

        return response()->json($result);
    }
}
