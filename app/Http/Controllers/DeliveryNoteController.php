<?php

namespace App\Http\Controllers;

use App\Models\DeliveryShipment;
use App\Models\DeliveryOrder;
use App\Services\ErpNextService;
use Illuminate\Http\Request;

class DeliveryNoteController extends Controller
{
    public function index(Request $request)
    {
        $query = DeliveryShipment::with('order.customer')
            ->whereHas('order', fn($q) => $q->whereNotIn('status', ['cancelled']))
            ->orderBy(
                DeliveryOrder::select('delivery_date')
                    ->whereColumn('delivery_orders.id', 'delivery_shipments.delivery_order_id')
                    ->limit(1)
            );

        if ($request->date) {
            $query->whereHas('order', fn($q) => $q->whereDate('delivery_date', $request->date));
        }
        if ($request->status) {
            $query->where('status', $request->status);
        }

        $shipments = $query->paginate(25)->withQueryString();

        // Summary counts
        $counts = DeliveryShipment::whereHas('order', fn($q) => $q->whereNotIn('status', ['cancelled']))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('delivery-notes.index', compact('shipments', 'counts'));
    }

    public function markDelivered(DeliveryShipment $shipment)
    {
        if ($shipment->status === 'delivered') {
            return response()->json(['success' => false, 'error' => 'Sudah ditandai terkirim.']);
        }

        $shipment->update([
            'status'       => 'delivered',
            'delivered_at' => now(),
        ]);

        // Cek apakah semua shipment dari order ini sudah delivered
        $order = $shipment->order;
        $allDelivered = $order->shipments()->where('status', '!=', 'delivered')->doesntExist();
        if ($allDelivered) {
            $order->update(['status' => 'completed']);
        } elseif ($order->status === 'confirmed') {
            $order->update(['status' => 'delivering']);
        }

        return response()->json(['success' => true]);
    }

    public function syncDeliveryNote(DeliveryShipment $shipment)
    {
        if ($shipment->erp_sync_status === 'synced') {
            return response()->json(['success' => false, 'error' => 'Delivery Note sudah pernah disync.']);
        }

        $shipment->load('order.customer');
        $erp    = new ErpNextService();
        $result = $erp->createDeliveryNote($shipment);

        return response()->json($result);
    }
}
