<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Transaction;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\Printer;

class ThermalPrintService
{
    /** Lebar kertas 58mm dalam karakter (Font A). */
    private const WIDTH = 32;

    /**
     * Cetak struk langsung ke thermal printer (ESC/POS).
     *
     * @throws \Exception jika printer tidak dapat dihubungi.
     */
    public function printReceipt(Transaction $transaction, array $store): void
    {
        $connector = $this->makeConnector();
        $printer   = new Printer($connector);

        try {
            $this->render($printer, $transaction, $store);
            $printer->cut();
            $printer->pulse(); // buka cash drawer bila tersambung
        } finally {
            $printer->close();
        }
    }

    /**
     * Pilih connector sesuai OS.
     * - Windows: WindowsPrintConnector ke nama shared printer.
     * - Linux/lainnya: FilePrintConnector ke device path.
     *
     * @throws \Exception jika koneksi ke printer gagal.
     */
    private function makeConnector()
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $name = Setting::get('thermal_printer_name', 'EPPOS58');
                return new WindowsPrintConnector($name);
            }

            $device = Setting::get('thermal_printer_device', '/dev/usb/lp1');

            // Cegah kegagalan diam-diam: kalau device tidak ada atau bukan
            // character device (mis. proses root sempat membuat file biasa
            // di path ini saat printer terputus), gagalkan dengan pesan jelas
            // agar data struk tidak nyasar ke file di disk.
            if (!file_exists($device)) {
                throw new \Exception("Device printer '{$device}' tidak ditemukan. Pastikan printer menyala & kabel USB tersambung.");
            }
            if (filetype($device) !== 'char') {
                throw new \Exception("Path '{$device}' bukan device printer (terdeteksi file biasa). Hapus dengan: sudo rm {$device} — lalu colok ulang printer.");
            }

            return new FilePrintConnector($device);
        } catch (\Throwable $e) {
            throw new \Exception('Tidak dapat terhubung ke printer: ' . $e->getMessage(), 0, $e);
        }
    }

    private function render(Printer $printer, Transaction $transaction, array $store): void
    {
        // ── Header toko ─────────────────────────────
        $printer->setJustification(Printer::JUSTIFY_CENTER);

        $this->printLogo($printer, $store);

        if (!empty($store['store_name'])) {
            $printer->setEmphasis(true);
            $printer->setTextSize(2, 2);
            $printer->text($this->wrap($store['store_name']));
            $printer->setTextSize(1, 1);
            $printer->setEmphasis(false);
        }

        foreach (['store_tagline', 'store_address', 'store_phone', 'store_email'] as $key) {
            if (!empty($store[$key])) {
                $prefix = $key === 'store_phone' ? 'Telp: ' : '';
                $printer->text($this->wrap($prefix . $store[$key]));
            }
        }

        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text($this->divider());

        // ── Info transaksi ──────────────────────────
        $printer->text($this->twoCol('Invoice', $transaction->invoice_no));
        $printer->text($this->twoCol('Tanggal', $transaction->created_at->format('d/m/Y H:i')));
        if ($transaction->user) {
            $printer->text($this->twoCol('Kasir', $transaction->user->name));
        }
        if ($transaction->customer) {
            $printer->text($this->twoCol('Customer', $transaction->customer->name));
        }

        $printer->text($this->divider());

        // ── Items ───────────────────────────────────
        foreach ($transaction->items as $item) {
            $printer->text($this->wrap($item->product_name));
            $qtyLine = $item->quantity . ' x ' . $this->rp($item->price);
            $printer->text($this->twoCol($qtyLine, $this->rp($item->subtotal)));
        }

        $printer->text($this->divider());

        // ── Totals ──────────────────────────────────
        if ($transaction->order_type) {
            $types = ['dine_in' => 'Dine In', 'take_away' => 'Take Away', 'delivery' => 'Delivery'];
            $printer->text($this->twoCol('Tipe', $types[$transaction->order_type] ?? $transaction->order_type));
        }

        $printer->text($this->twoCol('Subtotal', $this->rp($transaction->subtotal)));

        if ($transaction->discount_amount > 0) {
            $printer->text($this->twoCol('Diskon', '-' . $this->rp($transaction->discount_amount)));
        }
        if ($transaction->coupon_code && $transaction->coupon_discount > 0) {
            $printer->text($this->twoCol('Kupon (' . $transaction->coupon_code . ')', '-' . $this->rp($transaction->coupon_discount)));
        }
        if ($transaction->tax_amount > 0) {
            $printer->text($this->twoCol('Pajak', $this->rp($transaction->tax_amount)));
        }
        if ($transaction->service_charge_amount > 0) {
            $printer->text($this->twoCol('Service (' . rtrim(rtrim(number_format($transaction->service_charge_pct, 2), '0'), '.') . '%)', $this->rp($transaction->service_charge_amount)));
        }
        if ($transaction->pb1_amount > 0) {
            $printer->text($this->twoCol('PB1 (' . rtrim(rtrim(number_format($transaction->pb1_pct, 2), '0'), '.') . '%)', $this->rp($transaction->pb1_amount)));
        }

        $printer->text($this->divider());

        // ── Total & pembayaran ──────────────────────
        $printer->setEmphasis(true);
        $printer->text($this->twoCol('TOTAL', $this->rp($transaction->total)));
        $printer->setEmphasis(false);

        $printer->text($this->twoCol('Bayar (' . strtoupper($transaction->payment_method) . ')', $this->rp($transaction->paid_amount)));
        if ($transaction->change_amount > 0) {
            $printer->text($this->twoCol('Kembalian', $this->rp($transaction->change_amount)));
        }

        $printer->text($this->divider());

        // ── Footer ──────────────────────────────────
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        if (!empty($store['receipt_footer'])) {
            $printer->feed();
            $printer->text($this->wrap($store['receipt_footer']));
        }
        $printer->feed();
        $printer->text("Powered by HPY Solution\n");
        $printer->feed();
    }

    /**
     * Cetak logo toko sebagai gambar raster (kalau ada & GD tersedia).
     * Gambar diratakan ke kanvas putih dan di-resize agar muat lebar 58mm,
     * supaya area transparan tidak jadi hitam dan gambar tidak terpotong.
     * Kegagalan logo tidak boleh menggagalkan seluruh struk.
     */
    private function printLogo(Printer $printer, array $store): void
    {
        $logo = $store['store_logo'] ?? '';
        if ($logo === '') {
            return;
        }

        $path = public_path($logo);
        if (!is_file($path) || !extension_loaded('gd')) {
            return;
        }

        $tmp = null;
        try {
            $maxWidth = 384; // lebar cetak efektif printer 58mm (dots)
            $src = @imagecreatefromstring(file_get_contents($path));
            if ($src === false) {
                return;
            }

            $w  = imagesx($src);
            $h  = imagesy($src);
            $tw = min($w, $maxWidth);
            $th = (int) max(1, round($h * $tw / $w));

            $canvas = imagecreatetruecolor($tw, $th);
            $white  = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

            $tmp = tempnam(sys_get_temp_dir(), 'poslogo') . '.png';
            imagepng($canvas, $tmp);
            imagedestroy($src);
            imagedestroy($canvas);

            $img = EscposImage::load($tmp, false);
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->bitImage($img);
            $printer->feed();
        } catch (\Throwable $e) {
            // Logo gagal dicetak — lewati diam-diam, struk tetap jalan.
        } finally {
            if ($tmp && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** Format angka jadi "Rp 1.000". */
    private function rp($amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }

    /** Baris dua kolom: label kiri, nilai kanan, dipisah spasi. */
    private function twoCol(string $left, string $right): string
    {
        $space = self::WIDTH - mb_strlen($left) - mb_strlen($right);
        if ($space < 1) {
            // Kolom terlalu panjang: potong label agar muat.
            $maxLeft = max(0, self::WIDTH - mb_strlen($right) - 1);
            $left    = mb_substr($left, 0, $maxLeft);
            $space   = self::WIDTH - mb_strlen($left) - mb_strlen($right);
        }

        return $left . str_repeat(' ', max(1, $space)) . $right . "\n";
    }

    /** Bungkus teks panjang agar tidak melebihi lebar kertas. */
    private function wrap(string $text): string
    {
        return wordwrap($text, self::WIDTH, "\n", true) . "\n";
    }

    private function divider(): string
    {
        return str_repeat('-', self::WIDTH) . "\n";
    }
}
