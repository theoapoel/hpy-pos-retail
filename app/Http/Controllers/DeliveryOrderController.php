<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use App\Models\DeliveryShipment;
use App\Models\Product;
use App\Services\ErpNextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = DeliveryOrder::with('customer', 'creator')
            ->orderByDesc('delivery_date')
            ->orderByDesc('id');

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->date_from) {
            $query->whereDate('delivery_date', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('delivery_date', '<=', $request->date_to);
        }
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('order_no', 'like', '%' . $request->search . '%')
                  ->orWhereHas('customer', fn($c) => $c->where('name', 'like', '%' . $request->search . '%'));
            });
        }

        $orders = $query->paginate(20)->withQueryString();
        return view('delivery-orders.index', compact('orders'));
    }

    public function create()
    {
        $customers = Customer::where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone', 'address']);
        $products  = Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'price', 'erp_item_code']);
        return view('delivery-orders.create', compact('customers', 'products'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'customer_id'              => 'required|exists:customers,id',
            'delivery_date'            => 'required|date|after_or_equal:today',
            'billing_address'          => 'nullable|string|max:500',
            'notes'                    => 'nullable|string|max:1000',
            'items'                    => 'required|array|min:1',
            'items.*.product_name'     => 'required|string',
            'items.*.qty'              => 'required|numeric|min:0.01',
            'items.*.price'            => 'required|numeric|min:0',
            'shipments'                => 'required|array|min:1',
            'shipments.*.recipient_name'    => 'required|string|max:200',
            'shipments.*.recipient_phone'   => 'nullable|string|max:30',
            'shipments.*.shipping_address'  => 'required|string|max:500',
            'shipments.*.notes'             => 'nullable|string|max:500',
            'shipments.*.items'             => 'required|array|min:1',
        ]);

        DB::transaction(function () use ($request) {
            $order = DeliveryOrder::create([
                'order_no'        => DeliveryOrder::generateOrderNo(),
                'customer_id'     => $request->customer_id,
                'billing_address' => $request->billing_address,
                'delivery_date'   => $request->delivery_date,
                'notes'           => $request->notes,
                'status'          => 'draft',
                'created_by'      => auth()->id(),
            ]);

            foreach ($request->items as $row) {
                $subtotal = $row['price'] * $row['qty'];
                DeliveryOrderItem::create([
                    'delivery_order_id' => $order->id,
                    'product_id'        => $row['product_id'] ?? null,
                    'product_name'      => $row['product_name'],
                    'product_sku'       => $row['product_sku'] ?? null,
                    'price'             => $row['price'],
                    'qty'               => $row['qty'],
                    'subtotal'          => $subtotal,
                ]);
            }

            $order->recalculateTotal();

            foreach ($request->shipments as $i => $ship) {
                $shipItems = collect($ship['items'])->map(fn($si) => [
                    'product_name' => $si['product_name'],
                    'product_sku'  => $si['product_sku'] ?? null,
                    'qty'          => (float)($si['qty'] ?? 0),
                    'price'        => (float)($si['price'] ?? 0),
                    'subtotal'     => (float)($si['price'] ?? 0) * (float)($si['qty'] ?? 0),
                ])->filter(fn($si) => $si['qty'] > 0)->values()->toArray();

                $shipTotal = collect($shipItems)->sum('subtotal');

                DeliveryShipment::create([
                    'delivery_order_id' => $order->id,
                    'sequence'          => $i + 1,
                    'recipient_name'    => $ship['recipient_name'],
                    'recipient_phone'   => $ship['recipient_phone'] ?? null,
                    'shipping_address'  => $ship['shipping_address'],
                    'notes'             => $ship['notes'] ?? null,
                    'items'             => $shipItems,
                    'total'             => $shipTotal,
                ]);
            }

            $this->order = $order;
        });

        return redirect()->route('delivery-orders.show', $this->order ?? DeliveryOrder::latest()->first())
            ->with('success', 'Delivery order ' . ($this->order->order_no ?? '') . ' berhasil dibuat.');
    }

    public function show(DeliveryOrder $deliveryOrder)
    {
        $deliveryOrder->load('customer', 'creator', 'items.product', 'shipments');
        return view('delivery-orders.show', ['order' => $deliveryOrder]);
    }

    public function confirm(DeliveryOrder $deliveryOrder)
    {
        if ($deliveryOrder->status !== 'draft') {
            return back()->with('error', 'Order sudah tidak dalam status draft.');
        }
        $deliveryOrder->update(['status' => 'confirmed']);
        return back()->with('success', 'Order dikonfirmasi.');
    }

    public function cancel(DeliveryOrder $deliveryOrder)
    {
        if (in_array($deliveryOrder->status, ['completed', 'cancelled'])) {
            return back()->with('error', 'Order tidak dapat dibatalkan.');
        }
        $deliveryOrder->update(['status' => 'cancelled']);
        return back()->with('success', 'Order dibatalkan.');
    }

    public function syncSalesOrder(DeliveryOrder $deliveryOrder)
    {
        if ($deliveryOrder->erp_sync_status === 'synced') {
            return response()->json(['success' => false, 'error' => 'Sales Order sudah pernah disync.']);
        }

        $deliveryOrder->load('items.product', 'customer');
        $erp    = new ErpNextService();
        $result = $erp->createSalesOrder($deliveryOrder);

        return response()->json($result);
    }
}
