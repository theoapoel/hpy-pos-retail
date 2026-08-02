<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Struk {{ $transaction->invoice_no }}</title>
    <style>
        @php($paper = receipt_paper())
        * { margin: 0; padding: 0; box-sizing: border-box; }
        /* Kertas termal {{ $paper['page'] }} — area cetak efektif ~{{ $paper['content'] }} */
        @page { size: {{ $paper['page'] }} auto; margin: 0; }
        body {
            font-family: 'Courier New', monospace;
            font-size: 10px; line-height: 1.15;
            width: {{ $paper['content'] }}; margin: 0 auto; padding: 0;
            background: #fff; color: #000;
        }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .big { font-size: 12px; }
        .sm { font-size: 9px; }
        .divider { border: none; border-top: 1px dashed #000; margin: 2px 0; }
        .row { display: flex; justify-content: space-between; gap: 4px; margin: 0; }
        .row > span:last-child { white-space: nowrap; }
        .total-row { display: flex; justify-content: space-between; font-weight: bold; font-size: 11px; margin: 2px 0; }

        /* Struk lama memakai flexbox, sehingga teks panjang (barcode, nama
           produk) mendorong kolom nominal keluar kertas dan terpotong printer.
           table-layout:fixed mengunci lebar kolom: apa pun panjang deskripsinya,
           kolom nominal tidak pernah bergeser. */
        .meta { word-break: break-all; }
        .tbl { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .tbl td { vertical-align: top; padding: 0; }
        .tbl .head { border-bottom: 1px dashed #000; }
        .tbl .desc { word-break: break-all; padding-right: 4px; }
        .tbl .lbl { text-align: right; padding-right: 6px; }
        /* Nominal: lebar tetap + nowrap supaya angka tidak pernah terpenggal. */
        .tbl .amt { width: 40%; text-align: right; white-space: nowrap; }
        .tbl .qty { padding-left: 6px; font-size: 9px; }
        .tbl tr.bold td { font-weight: bold; }
        @media print {
            body { width: {{ $paper['content'] }}; margin: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    {{-- Header --}}
    @if($store['store_logo'])
    <div class="center" style="margin-bottom:6px">
        <img src="{{ asset($store['store_logo']) }}" style="max-height:50px;max-width:200px;object-fit:contain">
    </div>
    @endif
    <div class="center bold big">{{ $store['store_name'] }}</div>
    @if($store['store_tagline'])
        <div class="center sm">{{ $store['store_tagline'] }}</div>
    @endif
    @if($store['store_address'])
        <div class="center sm" style="margin-top:2px;">{{ $store['store_address'] }}</div>
    @endif
    @if($store['store_phone'])
        <div class="center sm">Phone : {{ $store['store_phone'] }}</div>
    @endif
    @if($store['store_email'])
        <div class="center sm">Email : {{ $store['store_email'] }}</div>
    @endif

    {{-- Info transaksi — label menempel di kiri seperti format POS ERP.
         Nomor memakai POS Invoice ERP bila sudah tersinkron, supaya struk di
         tangan pelanggan sama dengan dokumen yang bisa dicari di ERP. --}}
    <div class="meta" style="margin-top:6px">
        Receipt No: {{ $transaction->erp_pos_invoice ?: $transaction->invoice_no }}
    </div>
    <div class="meta">Date: {{ local_dt($transaction->created_at, 'd-m-Y H:i:s') }}</div>
    @if($transaction->customer)
        <div class="meta">Customer: {{ $transaction->customer->name }}</div>
    @endif
    <div class="meta">Cashier: {{ $transaction->user->name }}</div>

    {{-- Item --}}
    <table class="tbl" style="margin-top:6px">
        <thead>
            <tr class="head">
                <td>&lt;Description&gt;</td>
                <td class="amt">Amount</td>
            </tr>
        </thead>
        <tbody>
        @foreach($transaction->items as $item)
            <tr>
                {{-- Barcode + nama dibiarkan membungkus, bukan dipotong, supaya
                     tidak ada informasi yang hilang dari struk pelanggan. --}}
                <td class="desc">{{ $item->product->barcode ?: $item->product_sku }}{{ $item->product_name }}</td>
                <td class="amt">{{ number_format($item->subtotal, 2, '.', ',') }}</td>
            </tr>
            <tr>
                <td class="qty" colspan="2">
                    {{ number_format($item->quantity, 1, '.', '') }} &#64; {{ number_format($item->price, 2, '.', ',') }}
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <hr class="divider">

    {{-- Totals --}}
    <table class="tbl">
        <tr><td class="lbl">Total</td><td class="amt">{{ number_format($transaction->subtotal, 2, '.', ',') }}</td></tr>
        @if($transaction->discount_amount > 0)
            <tr><td class="lbl">Discount</td><td class="amt">-{{ number_format($transaction->discount_amount, 2, '.', ',') }}</td></tr>
        @endif
        @if($transaction->coupon_code && $transaction->coupon_discount > 0)
            <tr><td class="lbl">Coupon ({{ $transaction->coupon_code }})</td><td class="amt">-{{ number_format($transaction->coupon_discount, 2, '.', ',') }}</td></tr>
        @endif
        @if($transaction->tax_amount > 0)
            <tr><td class="lbl">Tax</td><td class="amt">{{ number_format($transaction->tax_amount, 2, '.', ',') }}</td></tr>
        @endif
        <tr class="bold"><td class="lbl">Grand Total</td><td class="amt">{{ number_format($transaction->total, 2, '.', ',') }}</td></tr>

        {{-- Pembayaran: satu baris per metode, seperti format POS ERP.
             payment_details terisi hanya saat pembayaran campuran. --}}
        @php($rincian = $transaction->payment_method === 'mixed' ? ($transaction->payment_details ?: []) : [])
        @if($rincian)
            @foreach($rincian as $metode => $nominal)
                <tr><td class="lbl">{{ $metode }} Paid Amount</td><td class="amt">{{ number_format((float) $nominal, 0, '.', ',') }}</td></tr>
            @endforeach
        @else
            <tr><td class="lbl">{{ strtoupper($transaction->payment_method) }} Paid Amount</td><td class="amt">{{ number_format($transaction->paid_amount, 0, '.', ',') }}</td></tr>
        @endif
        @if($transaction->change_amount > 0)
            <tr><td class="lbl">Change Amount</td><td class="amt">{{ number_format($transaction->change_amount, 0, '.', ',') }}</td></tr>
        @endif

        <tr><td class="lbl">Total Qty</td><td class="amt">{{ number_format($transaction->items->sum('quantity'), 1, '.', '') }}</td></tr>
        @if($transaction->loyalty_amount > 0)
            <tr><td class="lbl">Loyalty Point {{ (int) $transaction->loyalty_points_redeemed }}</td><td class="amt">{{ number_format($transaction->loyalty_amount, 0, '.', ',') }}</td></tr>
        @endif
    </table>

    <hr class="divider">

    {{-- Footer --}}
    @if($store['receipt_footer'])
    <div class="center" style="margin-top:8px;">{{ $store['receipt_footer'] }}</div>
    @endif
    <div class="center sm" style="margin-top:4px;color:#888;">Powered by HPY Solution</div>

    <div class="no-print" style="text-align:center;margin-top:20px">
        <button onclick="window.print()" style="padding:8px 20px;background:#4285F4;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px">🖨️ Print</button>
        <button id="thermalBtn" onclick="directPrint()" style="padding:8px 20px;background:#0F9D58;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;margin-left:8px">🧾 Print Thermal</button>
        <button onclick="window.close()" style="padding:8px 20px;border:1px solid #ccc;border-radius:6px;cursor:pointer;font-size:14px;margin-left:8px">Tutup</button>
    </div>
    <script>
        function directPrint() {
            const btn = document.getElementById('thermalBtn');
            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Mengirim…';

            fetch('{{ route('pos.direct-print', $transaction->id) }}')
                .then(async (res) => {
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data.success) {
                        throw new Error(data.message || 'Gagal mencetak ke printer.');
                    }
                    alert(data.message || 'Struk berhasil dikirim ke printer.');
                })
                .catch((err) => alert('❌ ' + err.message))
                .finally(() => {
                    btn.disabled = false;
                    btn.textContent = original;
                });
        }

        window.onload = () => window.print();
    </script>
</body>
</html>
