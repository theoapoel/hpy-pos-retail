<?php

namespace App\Http\Controllers;

use App\Models\DeliveryOrder;
use Illuminate\Http\Request;

class KitchenController extends Controller
{
    public function index()
    {
        $orders = DeliveryOrder::with(['customer', 'items'])
            ->whereIn('kitchen_status', ['pending', 'preparing', 'ready'])
            ->whereIn('status', ['confirmed', 'delivering', 'completed'])
            ->orderBy('delivery_date')
            ->orderBy('created_at')
            ->get()
            ->groupBy('kitchen_status');

        $pending   = $orders->get('pending',   collect());
        $preparing = $orders->get('preparing', collect());
        $ready     = $orders->get('ready',     collect());

        return view('kitchen.index', compact('pending', 'preparing', 'ready'));
    }

    public function updateStatus(Request $request, DeliveryOrder $order)
    {
        $request->validate([
            'kitchen_status' => 'required|in:pending,preparing,ready',
        ]);

        $newStatus = $request->kitchen_status;
        $data = ['kitchen_status' => $newStatus];

        if ($newStatus === 'preparing' && !$order->kitchen_started_at) {
            $data['kitchen_started_at'] = now();
        } elseif ($newStatus === 'ready' && !$order->kitchen_ready_at) {
            $data['kitchen_ready_at'] = now();
        }

        $order->update($data);

        return response()->json(['success' => true, 'kitchen_status' => $newStatus]);
    }

    public function poll()
    {
        $counts = DeliveryOrder::whereIn('kitchen_status', ['pending', 'preparing', 'ready'])
            ->whereIn('status', ['confirmed', 'delivering', 'completed'])
            ->selectRaw('kitchen_status, count(*) as total')
            ->groupBy('kitchen_status')
            ->pluck('total', 'kitchen_status');

        return response()->json([
            'pending'   => $counts->get('pending', 0),
            'preparing' => $counts->get('preparing', 0),
            'ready'     => $counts->get('ready', 0),
        ]);
    }
}
