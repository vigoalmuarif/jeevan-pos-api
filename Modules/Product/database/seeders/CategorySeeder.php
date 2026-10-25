<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'MAKANAN_POKOK', 'name' => 'Makanan Pokok', 'description' => 'Beras, jagung, tepung, sagu, dan bahan pangan pokok lainnya.'],
            ['code' => 'MINYAK_LEMAK', 'name' => 'Minyak dan Lemak', 'description' => 'Minyak goreng, margarin, mentega, dan lemak konsumsi.'],
            ['code' => 'BUMBU_BAHAN_MASAK', 'name' => 'Bumbu dan Bahan Masak', 'description' => 'Bumbu, rempah, saus, kecap, penyedap, dan bahan pelengkap masakan.'],
            ['code' => 'MAKANAN_KALENG', 'name' => 'Makanan Kaleng dan Kemasan', 'description' => 'Makanan siap saji, makanan kaleng, mi instan, dan makanan kemasan.'],
            ['code' => 'SNACK', 'name' => 'Snack dan Makanan Ringan', 'description' => 'Keripik, biskuit, wafer, kacang, permen, dan makanan ringan lainnya.'],
            ['code' => 'ROTI_KUE', 'name' => 'Roti dan Kue', 'description' => 'Roti, kue, pastry, dan produk bakery.'],
            ['code' => 'SUSU_PRODUK_SUSU', 'name' => 'Susu dan Produk Susu', 'description' => 'Susu cair, susu bubuk, susu kental, yoghurt, keju, dan produk olahan susu.'],
            ['code' => 'MINUMAN_AIR', 'name' => 'Air Mineral dan Air Minum', 'description' => 'Air mineral, air minum dalam kemasan, dan air isi ulang.'],
            ['code' => 'MINUMAN_NON_SUSU', 'name' => 'Minuman Non-Susu', 'description' => 'Teh, kopi, jus, minuman ringan, minuman energi, dan minuman siap konsumsi.'],
            ['code' => 'KOPI_TEH', 'name' => 'Kopi dan Teh', 'description' => 'Kopi, teh, cokelat minuman, dan bahan minuman seduh.'],
            ['code' => 'MAKANAN_BEKU', 'name' => 'Makanan Beku', 'description' => 'Produk frozen food, es krim, dan makanan yang memerlukan penyimpanan beku.'],
            ['code' => 'MAKANAN_SEGAR', 'name' => 'Makanan Segar', 'description' => 'Buah, sayuran, daging, ikan, telur, dan produk segar lainnya.'],
            ['code' => 'ROKOK_TEMBAKAU', 'name' => 'Rokok dan Produk Tembakau', 'description' => 'Rokok, cerutu, tembakau, dan produk terkait sesuai ketentuan yang berlaku.'],
            ['code' => 'PERLENGKAPAN_MEROKOK', 'name' => 'Perlengkapan Merokok', 'description' => 'Korek api, kertas linting, dan aksesori terkait.'],
            ['code' => 'KEBUTUHAN_RUMAH', 'name' => 'Kebutuhan Rumah Tangga', 'description' => 'Perlengkapan kebersihan, penyimpanan, dan kebutuhan rumah tangga sehari-hari.'],
            ['code' => 'PEMBERSIH_RUMAH', 'name' => 'Pembersih Rumah', 'description' => 'Deterjen, sabun cuci, pembersih lantai, pewangi, dan produk kebersihan rumah.'],
            ['code' => 'PERAWATAN_TUBUH', 'name' => 'Perawatan Tubuh', 'description' => 'Sabun mandi, sampo, pasta gigi, deodoran, dan produk perawatan diri.'],
            ['code' => 'KOSMETIK', 'name' => 'Kosmetik dan Perawatan Wajah', 'description' => 'Kosmetik, produk perawatan kulit, dan perawatan wajah.'],
            ['code' => 'KESEHATAN_OBAT', 'name' => 'Kesehatan dan Obat Bebas', 'description' => 'Vitamin, suplemen, obat bebas, dan perlengkapan kesehatan sesuai ketentuan.'],
            ['code' => 'PERLENGKAPAN_BAYI', 'name' => 'Perlengkapan Bayi', 'description' => 'Popok, susu formula, makanan bayi, dan perlengkapan perawatan bayi.'],
            ['code' => 'PERLENGKAPAN_SEKOLAH', 'name' => 'Alat Tulis dan Perlengkapan Sekolah', 'description' => 'Alat tulis, buku, kertas, dan kebutuhan sekolah atau kantor.'],
            ['code' => 'ELEKTRONIK', 'name' => 'Elektronik', 'description' => 'Perangkat elektronik rumah tangga, audio, dan perangkat digital.'],
            ['code' => 'TELEPON_AKSESORIS', 'name' => 'Telepon dan Aksesori', 'description' => 'Telepon seluler, pengisi daya, kabel, casing, dan aksesori perangkat.'],
            ['code' => 'KOMPUTER_AKSESORIS', 'name' => 'Komputer dan Aksesori', 'description' => 'Komputer, komponen, periferal, dan aksesori komputer.'],
            ['code' => 'PERALATAN_RUMAH', 'name' => 'Peralatan Rumah Tangga', 'description' => 'Peralatan masak, alat makan, perkakas, dan peralatan rumah tangga.'],
            ['code' => 'PAKAIAN', 'name' => 'Pakaian dan Aksesori', 'description' => 'Pakaian, alas kaki, tas, dan aksesori fesyen.'],
            ['code' => 'OTOMOTIF', 'name' => 'Otomotif', 'description' => 'Oli, suku cadang, aksesori, dan perlengkapan kendaraan.'],
            ['code' => 'BAHAN_BANGUNAN', 'name' => 'Bahan Bangunan dan Perkakas', 'description' => 'Bahan bangunan, alat kerja, perkakas, dan perlengkapan perbaikan.'],
            ['code' => 'PERTANIAN_PETERNAKAN', 'name' => 'Pertanian dan Peternakan', 'description' => 'Benih, pupuk, pakan, dan perlengkapan pertanian atau peternakan.'],
            ['code' => 'HEWAN_PELIHARAAN', 'name' => 'Kebutuhan Hewan Peliharaan', 'description' => 'Pakan, pasir, aksesori, dan perlengkapan perawatan hewan peliharaan.'],
            ['code' => 'MAINAN_HOBI', 'name' => 'Mainan dan Hobi', 'description' => 'Mainan, permainan, koleksi, dan perlengkapan hobi.'],
            ['code' => 'PERLENGKAPAN_PESTA', 'name' => 'Perlengkapan Pesta', 'description' => 'Dekorasi, alat pesta, hadiah, dan perlengkapan acara.'],
            ['code' => 'ALAT_KEBERSIHAN', 'name' => 'Alat Kebersihan', 'description' => 'Sapu, pel, sikat, tempat sampah, dan alat kebersihan lainnya.'],
            ['code' => 'GAS_BAHAN_BAKAR', 'name' => 'Gas dan Bahan Bakar', 'description' => 'Gas rumah tangga dan bahan bakar lain yang dijual sesuai ketentuan.'],
            ['code' => 'PULSA_DIGITAL', 'name' => 'Pulsa dan Produk Digital', 'description' => 'Pulsa, paket data, token, voucher, dan produk digital.'],
            ['code' => 'JASA', 'name' => 'Jasa', 'description' => 'Produk nonfisik dan layanan yang ditawarkan kepada pelanggan.'],
            ['code' => 'LAIN_LAIN', 'name' => 'Lain-lain', 'description' => 'Produk yang belum sesuai dengan kategori lain yang tersedia.'],
        ];

        $now = now();
        foreach ($categories as $category) {
            $existing = DB::table('categories')->where('code', $category['code'])->first();
            if ($existing && $existing->merchant_id !== null) {
                throw new \RuntimeException("Kode kategori {$category['code']} sudah digunakan oleh kategori merchant.");
            }
            $values = [
                'merchant_id' => null,
                'name' => $category['name'],
                'slug' => Str::slug($category['name']),
                'description' => $category['description'],
                'is_active' => true,
                'deleted_at' => null,
                'updated_at' => $now,
            ];
            if ($existing) {
                DB::table('categories')->where('id', $existing->id)->update($values);
            } else {
                DB::table('categories')->insert([
                    ...$values,
                    'code' => $category['code'],
                    'created_at' => $now,
                ]);
            }
        }
    }
}
