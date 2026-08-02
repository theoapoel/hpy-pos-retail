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
    /**
     * Lebar baris dalam karakter (Font A), mengikuti ukuran kertas terpilih:
     * 32 untuk 58mm, 48 untuk 80mm. Dibaca sekali per instance supaya satu
     * struk tidak setengah tercetak dengan lebar berbeda bila setting berubah
     * di tengah proses.
     */
    private readonly int $width;

    private readonly int $logoDots;

    public function __construct()
    {
        $paper = receipt_paper();
        $this->width = $paper['chars'];
        $this->logoDots = $paper['dots'];
    }

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
                $prefix = match ($key) {
                    'store_phone' => 'Phone : ',
                    'store_email' => 'Email : ',
                    default       => '',
                };
                $printer->text($this->wrap($prefix . $store[$key]));
            }
        }

        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->feed();

        // ── Info transaksi ──────────────────────────
        // Nomor memakai POS Invoice ERP bila sudah tersinkron, supaya struk di
        // tangan pelanggan sama dengan dokumen yang bisa dicari di ERP.
        $printer->text($this->wrap('Receipt No: ' . ($transaction->erp_pos_invoice ?: $transaction->invoice_no)));
        $printer->text($this->wrap('Date: ' . $transaction->created_at->setTimezone(local_tz())->format('d-m-Y H:i:s')));
        if ($transaction->customer) {
            $printer->text($this->wrap('Customer: ' . $transaction->customer->name));
        }
        if ($transaction->user) {
            $printer->text($this->wrap('Cashier: ' . $transaction->user->name));
        }

        $printer->text($this->twoCol('<Description>', 'Amount'));
        $printer->text($this->divider());

        // ── Items ───────────────────────────────────
        // Barcode mendahului nama, seperti format POS ERP. wrap() memotong per
        // baris kertas sehingga barcode panjang tidak mendorong kolom nominal.
        foreach ($transaction->items as $item) {
            $kode = $item->product?->barcode ?: $item->product_sku;
            $printer->text($this->wrap(trim($kode . $item->product_name)));
            $printer->text($this->twoCol('', $this->num($item->subtotal)));
            $printer->text('  ' . $this->qty($item->quantity) . ' @ ' . $this->num($item->price) . "\n");
        }

        $printer->text($this->divider());

        // ── Totals ──────────────────────────────────
        $printer->text($this->twoCol('Total', $this->num($transaction->subtotal)));

        if ($transaction->discount_amount > 0) {
            $printer->text($this->twoCol('Discount', '-' . $this->num($transaction->discount_amount)));
        }
        if ($transaction->coupon_code && $transaction->coupon_discount > 0) {
            $printer->text($this->twoCol('Coupon (' . $transaction->coupon_code . ')', '-' . $this->num($transaction->coupon_discount)));
        }
        if ($transaction->tax_amount > 0) {
            $printer->text($this->twoCol('Tax', $this->num($transaction->tax_amount)));
        }

        $printer->setEmphasis(true);
        $printer->text($this->twoCol('Grand Total', $this->num($transaction->total)));
        $printer->setEmphasis(false);

        // Pembayaran: satu baris per metode. payment_details hanya terisi saat
        // pembayaran campuran.
        $rincian = $transaction->payment_method === 'mixed' ? ($transaction->payment_details ?: []) : [];
        if ($rincian) {
            foreach ($rincian as $metode => $nominal) {
                $printer->text($this->twoCol($metode . ' Paid Amount', number_format((float) $nominal, 0, '.', ',')));
            }
        } else {
            $printer->text($this->twoCol(
                strtoupper($transaction->payment_method) . ' Paid Amount',
                number_format((float) $transaction->paid_amount, 0, '.', ',')
            ));
        }
        if ($transaction->change_amount > 0) {
            $printer->text($this->twoCol('Change Amount', number_format((float) $transaction->change_amount, 0, '.', ',')));
        }

        $printer->text($this->twoCol('Total Qty', $this->qty($transaction->items->sum('quantity'))));
        if ($transaction->loyalty_amount > 0) {
            $printer->text($this->twoCol(
                'Loyalty Point ' . (int) $transaction->loyalty_points_redeemed,
                number_format((float) $transaction->loyalty_amount, 0, '.', ',')
            ));
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
     * Gambar diratakan ke kanvas putih dan di-resize agar muat lebar kertas,
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
            $maxWidth = $this->logoDots; // lebar cetak efektif printer (dots)
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

    /** Nominal gaya format POS ERP: 124,000.00 */
    private function num($amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }

    /** Kuantitas gaya ERP: satu desimal — 1.0, 2.5 */
    private function qty($value): string
    {
        return number_format((float) $value, 1, '.', '');
    }

    /**
     * Baris dua kolom: label kiri, nilai kanan, dipisah spasi.
     *
     * Bila keduanya tidak muat dalam satu baris, label dibungkus ke baris
     * sendiri lalu nominal dirata-kanan di baris berikutnya. Versi lama
     * memotong labelnya ("... Paid Amount" jadi "... Paid Amoun"), yang pada
     * kertas 58mm membuat nama metode pembayaran hilang sebagian di struk
     * pelanggan.
     */
    private function twoCol(string $left, string $right): string
    {
        $space = $this->width - mb_strlen($left) - mb_strlen($right);

        if ($space >= 1) {
            return $left . str_repeat(' ', $space) . $right . "\n";
        }

        return $this->wrap($left) . $this->rightAlign($right);
    }

    /** Satu baris berisi teks yang dirata-kanan pada lebar kertas. */
    private function rightAlign(string $text): string
    {
        $pad = max(0, $this->width - mb_strlen($text));

        return str_repeat(' ', $pad) . $text . "\n";
    }

    /** Bungkus teks panjang agar tidak melebihi lebar kertas. */
    private function wrap(string $text): string
    {
        return wordwrap($text, $this->width, "\n", true) . "\n";
    }

    private function divider(): string
    {
        return str_repeat('-', $this->width) . "\n";
    }
}
