<?php

use App\Models\Setting;
use Carbon\Carbon;

if (! function_exists('local_tz')) {
    /** Timezone lokal aplikasi (mis. WIB). App menyimpan waktu di UTC. */
    function local_tz(): string
    {
        return Setting::get('timezone', 'Asia/Jakarta') ?: 'Asia/Jakarta';
    }
}

if (! function_exists('local_dt')) {
    /**
     * Konversi datetime (tersimpan UTC di DB) ke timezone lokal lalu format.
     * Aman untuk data lama maupun baru karena selalu dikonversi saat ditampilkan.
     */
    function local_dt($dt, string $format = 'd/m/Y H:i'): string
    {
        if (empty($dt)) {
            return '';
        }

        return Carbon::parse($dt)->setTimezone(local_tz())->format($format);
    }
}

if (! function_exists('receipt_paper')) {
    /**
     * Ukuran kertas struk termal, satu sumber untuk semua jalur cetak.
     *
     * - page/content : dipakai @page dan lebar body di view cetak browser
     * - chars        : lebar baris ESC/POS Font A (ThermalPrintService)
     * - dots         : lebar cetak efektif untuk resize logo ESC/POS
     *
     * Nilai 58mm dan 80mm adalah dua ukuran roll termal yang beredar; area
     * cetak efektifnya lebih sempit dari lebar kertas karena ada margin kiri
     * kanan yang tidak terjangkau head printer.
     *
     * @return array{size:string, page:string, content:string, chars:int, dots:int}
     */
    function receipt_paper(): array
    {
        $size = (string) Setting::get('receipt_paper_size', '58');

        if ($size === '80') {
            return ['size' => '80', 'page' => '80mm', 'content' => '72mm', 'chars' => 48, 'dots' => 576];
        }

        return ['size' => '58', 'page' => '58mm', 'content' => '48mm', 'chars' => 32, 'dots' => 384];
    }
}

if (! function_exists('pos_payment_methods')) {
    /**
     * Metode pembayaran POS beserta penanda `is_cash`.
     *
     * Field `type` dari POS Profile ERP sering tidak terisi sehingga semua
     * metode jatuh ke default 'General' — termasuk CASH. Karena itu nama
     * metode ikut diperiksa agar tunai tetap dikenali dan kolom "Nominal
     * Bayar" muncul sebagaimana mestinya.
     */
    function pos_payment_methods(): array
    {
        $methods = json_decode(Setting::get('pos_payment_methods', '[]'), true) ?: [];

        foreach ($methods as $i => $m) {
            $name = $m['mode_of_payment'] ?? '';
            $methods[$i]['is_cash'] = ($m['type'] ?? '') === 'Cash'
                || (bool) preg_match('/(^|[^a-z])(cash|tunai)([^a-z]|$)/i', $name);
        }

        return array_values($methods);
    }
}
