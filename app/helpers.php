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
