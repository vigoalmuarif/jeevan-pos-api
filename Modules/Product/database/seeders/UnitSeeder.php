<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Scopes\MerchantOrGlobalScope;
use Modules\Product\Models\Unit;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $units = [

            // =========================
            // UMUM
            // =========================
            ['name' => 'Piece', 'code' => 'pcs'],
            ['name' => 'Unit', 'code' => 'unit'],
            ['name' => 'Set', 'code' => 'set'],
            ['name' => 'Pasang', 'code' => 'psg'],
            ['name' => 'Lot', 'code' => 'lot'],
            ['name' => 'Butir', 'code' => 'btr'],
            ['name' => 'Buah', 'code' => 'buah'],
            ['name' => 'Ekor', 'code' => 'ekr'],
            ['name' => 'Kepala', 'code' => 'kpl'],
            ['name' => 'Orang', 'code' => 'org'],

            // =========================
            // KEMASAN
            // =========================
            ['name' => 'Bungkus', 'code' => 'bks'],
            ['name' => 'Pack', 'code' => 'pack'],
            ['name' => 'Pak', 'code' => 'pak'],
            ['name' => 'Sachet', 'code' => 'sct'],
            ['name' => 'Strip', 'code' => 'str'],
            ['name' => 'Lusin', 'code' => 'lsn'],
            ['name' => 'Gross', 'code' => 'grs'],
            ['name' => 'Kodi', 'code' => 'kodi'],
            ['name' => 'Rim', 'code' => 'rim'],

            // =========================
            // GUDANG & DISTRIBUSI
            // =========================
            ['name' => 'Box', 'code' => 'box'],
            ['name' => 'Dus', 'code' => 'dus'],
            ['name' => 'Karton', 'code' => 'ctn'],
            ['name' => 'Bal', 'code' => 'bal'],
            ['name' => 'Pallet', 'code' => 'plt'],
            ['name' => 'Container', 'code' => 'cnt'],
            ['name' => 'Crate', 'code' => 'crt'],
            ['name' => 'Peti', 'code' => 'pti'],

            // =========================
            // BERAT
            // =========================
            ['name' => 'Mikrogram', 'code' => 'mcg'],
            ['name' => 'Miligram', 'code' => 'mg'],
            ['name' => 'Gram', 'code' => 'gr'],
            ['name' => 'Kilogram', 'code' => 'kg'],
            ['name' => 'Ons', 'code' => 'ons'],
            ['name' => 'Pon', 'code' => 'lb'],
            ['name' => 'Quintal', 'code' => 'qtl'],
            ['name' => 'Ton', 'code' => 'ton'],

            // =========================
            // VOLUME
            // =========================
            ['name' => 'Mililiter', 'code' => 'ml'],
            ['name' => 'Centiliter', 'code' => 'cl'],
            ['name' => 'Desiliter', 'code' => 'dl'],
            ['name' => 'Liter', 'code' => 'l'],
            ['name' => 'Galon', 'code' => 'gal'],
            ['name' => 'Jerigen', 'code' => 'jrg'],
            ['name' => 'Drum', 'code' => 'drm'],
            ['name' => 'Barrel', 'code' => 'bbl'],

            // =========================
            // PANJANG
            // =========================
            ['name' => 'Milimeter', 'code' => 'mm'],
            ['name' => 'Centimeter', 'code' => 'cm'],
            ['name' => 'Desimeter', 'code' => 'dm'],
            ['name' => 'Meter', 'code' => 'm'],
            ['name' => 'Dekameter', 'code' => 'dam'],
            ['name' => 'Hektometer', 'code' => 'hm'],
            ['name' => 'Kilometer', 'code' => 'km'],
            ['name' => 'Inch', 'code' => 'in'],
            ['name' => 'Feet', 'code' => 'ft'],
            ['name' => 'Yard', 'code' => 'yd'],

            // =========================
            // LUAS
            // =========================
            ['name' => 'Sentimeter Persegi', 'code' => 'cm2'],
            ['name' => 'Meter Persegi', 'code' => 'm2'],
            ['name' => 'Kilometer Persegi', 'code' => 'km2'],
            ['name' => 'Are', 'code' => 'are'],
            ['name' => 'Hektar', 'code' => 'ha'],

            // =========================
            // VOLUME RUANG
            // =========================
            ['name' => 'Meter Kubik', 'code' => 'm3'],
            ['name' => 'Feet Kubik', 'code' => 'ft3'],

            // =========================
            // KONSTRUKSI
            // =========================
            ['name' => 'Lembar', 'code' => 'lbr'],
            ['name' => 'Batang', 'code' => 'btg'],
            ['name' => 'Roll', 'code' => 'roll'],
            ['name' => 'Karung', 'code' => 'krg'],
            ['name' => 'Sak', 'code' => 'sak'],
            ['name' => 'Ikat', 'code' => 'ikt'],
            ['name' => 'Tandan', 'code' => 'tdn'],
            ['name' => 'Kubik', 'code' => 'kbk'],

            // =========================
            // INDUSTRI
            // =========================
            ['name' => 'Tabung', 'code' => 'tbg'],
            ['name' => 'Tangki', 'code' => 'tgk'],
            ['name' => 'Can', 'code' => 'can'],
            ['name' => 'Pail', 'code' => 'pail'],
            ['name' => 'Coil', 'code' => 'coil'],

            // =========================
            // MAKANAN & MINUMAN
            // =========================
            ['name' => 'Porsi', 'code' => 'prs'],
            ['name' => 'Cup', 'code' => 'cup'],
            ['name' => 'Gelas', 'code' => 'gls'],
            ['name' => 'Mangkok', 'code' => 'mng'],
            ['name' => 'Tray', 'code' => 'try'],
            ['name' => 'Potong', 'code' => 'ptg'],
            ['name' => 'Tusuk', 'code' => 'tsk'],

            // =========================
            // FARMASI
            // =========================
            ['name' => 'Tablet', 'code' => 'tab'],
            ['name' => 'Kapsul', 'code' => 'kps'],
            ['name' => 'Ampul', 'code' => 'amp'],
            ['name' => 'Vial', 'code' => 'vial'],
            ['name' => 'Botol', 'code' => 'btl'],
            ['name' => 'Tube', 'code' => 'tube'],

            // =========================
            // TEKSTIL
            // =========================
            ['name' => 'Gulung', 'code' => 'glg'],
            ['name' => 'Cone Benang', 'code' => 'cone'],

            // =========================
            // PERTANIAN
            // =========================
            ['name' => 'Bibit', 'code' => 'bbt'],
            ['name' => 'Pohon', 'code' => 'phn'],
            ['name' => 'Polybag', 'code' => 'pbg'],

            // =========================
            // PETERNAKAN
            // =========================
            ['name' => 'Kandang', 'code' => 'kdg'],

            // =========================
            // ELEKTRONIK
            // =========================
            ['name' => 'Papan', 'code' => 'ppn'],
            ['name' => 'Modul', 'code' => 'mdl'],

            // =========================
            // PERCETAKAN
            // =========================
            ['name' => 'Lembar Cetak', 'code' => 'lc'],
            ['name' => 'Buku', 'code' => 'bk'],
            ['name' => 'Eksemplar', 'code' => 'eks'],
        ];

        $data = collect($units)->map(fn ($unit) => [
            'merchant_id' => null,
            'name' => $unit['name'],
            'code' => strtolower($unit['code']),
            'is_system' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->toArray();

        Unit::withoutGlobalScope(MerchantOrGlobalScope::class)->upsert(
            $data,
            ['merchant_id', 'code'],
            ['name', 'is_system', 'is_active', 'updated_at']
        );
    }
}