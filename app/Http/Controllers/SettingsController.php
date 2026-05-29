<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    private const STORE_KEYS = [
        'store_name', 'store_tagline', 'store_address',
        'store_phone', 'store_email', 'receipt_footer', 'pos_class',
        'pos_product_display',
        'service_charge_enabled', 'service_charge_pct',
        'pb1_enabled', 'pb1_pct',
    ];

    public function index()
    {
        $settings = [];
        foreach (self::STORE_KEYS as $key) {
            $settings[$key] = Setting::get($key, '');
        }

        return view('settings.index', compact('settings'));
    }

    public function save(Request $request)
    {
        $request->validate([
            'store_name'             => 'required|string|max:100',
            'store_tagline'          => 'nullable|string|max:150',
            'store_address'          => 'nullable|string|max:300',
            'store_phone'            => 'nullable|string|max:30',
            'store_email'            => 'nullable|email|max:100',
            'receipt_footer'         => 'nullable|string|max:200',
            'pos_class'              => 'nullable|string|max:100',
            'pos_product_display'    => 'nullable|in:image,text',
            'service_charge_enabled' => 'nullable|in:0,1',
            'service_charge_pct'     => 'nullable|numeric|min:0|max:100',
            'pb1_enabled'            => 'nullable|in:0,1',
            'pb1_pct'                => 'nullable|numeric|min:0|max:100',
        ]);

        // Checkboxes: jika tidak dicentang, request tidak mengirim nilai → default '0'
        $data = $request->only(self::STORE_KEYS);
        $data['service_charge_enabled'] = $request->has('service_charge_enabled') ? '1' : '0';
        $data['pb1_enabled']            = $request->has('pb1_enabled') ? '1' : '0';

        foreach ($data as $key => $value) {
            Setting::set($key, $value ?? '', 'store');
        }

        return response()->json(['success' => true]);
    }

    // Helper yang dipanggil oleh PosController
    public static function storeSettings(): array
    {
        return [
            'store_name'             => Setting::get('store_name', 'HPYSync'),
            'store_tagline'          => Setting::get('store_tagline', 'Point of Sale System'),
            'store_address'          => Setting::get('store_address', ''),
            'store_phone'            => Setting::get('store_phone', ''),
            'store_email'            => Setting::get('store_email', ''),
            'receipt_footer'         => Setting::get('receipt_footer', 'Terima kasih atas kunjungan Anda!'),
            'pos_class'              => Setting::get('pos_class', ''),
            'pos_product_display'    => Setting::get('pos_product_display', 'image'),
            'service_charge_enabled' => Setting::get('service_charge_enabled', '0'),
            'service_charge_pct'     => (float) Setting::get('service_charge_pct', '0'),
            'pb1_enabled'            => Setting::get('pb1_enabled', '0'),
            'pb1_pct'                => (float) Setting::get('pb1_pct', '0'),
        ];
    }
}
