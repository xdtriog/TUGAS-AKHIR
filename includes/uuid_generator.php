<?php
/**
 * Global UUID Generator
 * Fungsi generate hanya untuk generate UUID saja
 * Check duplikat dilakukan di file yang memanggil dengan pattern:
 * generate > check > pass, atau generate > check (duplikat) > generate > check > pass
 * 
 * Usage:
 * require_once 'includes/uuid_generator.php';
 * 
 * // Generate UUID
 * $uuid = ShortIdGenerator::generate($length, $prefix);
 * 
 * // Check duplikat
 * if (checkUUIDExists($conn, 'MASTER_BARANG', 'KD_BARANG', $uuid)) {
 *     // Generate lagi jika duplikat
 *     $uuid = ShortIdGenerator::generate($length, $prefix);
 * }
 */

class ShortIdGenerator
{
    private static $lastTimestamp = 0;
    private static $counter = 0;

    /**
     * Generate ID transaksi pendek, tanpa library
     * Hanya generate saja, tidak ada check duplikat
     * 
     * @param int $length Panjang yang kamu mau (8 sampai 20 direkomendasikan)
     * @param string $prefix Optional: prefix untuk ID
     * @return string
     */
    public static function generate(int $length = 12, string $prefix = ''): string
    {
        // 1. Ambil waktu sekarang dalam milidetik
        $now = (int)(microtime(true) * 1000);

        // 2. Reset counter kalau timestamp beda
        if ($now !== self::$lastTimestamp) {
            self::$lastTimestamp = $now;
            self::$counter = 0;
        }

        // 3. Increment counter (max 9999 per ms → cukup buat 1 juta/detik)
        self::$counter = (self::$counter + 1) % 10000;

        // 4. Bangun data unik: timestamp + counter + random kecil
        $random = mt_rand(0, 999); // tambah noise biar lebih acak

        $uniqueNumber = ($now % 10000000) * 10000 + self::$counter; // pakai 7 digit terakhir timestamp
        $uniqueNumber = $uniqueNumber * 1000 + $random; // tambah 3 digit random

        // 5. Encode ke base62 (a-zA-Z0-9) biar pendek & aman URL
        // Hapus karakter yang mirip: 0, O, I, l
        $base62 = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
        $short = '';
        $num = $uniqueNumber;

        while ($num > 0) {
            $short = $base62[$num % 58] . $short;
            $num = (int)($num / 58);
        }

        // 6. Pad dengan karakter random jika kurang dari panjang yang diminta
        while (strlen($short) < $length) {
            $short = $base62[mt_rand(0, 57)] . $short;
        }

        // 7. Ambil sesuai panjang yang diminta (ambil dari kanan jika lebih panjang)
        if (strlen($short) > $length) {
            $short = substr($short, -$length);
        }

        return $prefix . $short;
    }
}

/**
 * Check apakah UUID sudah ada di database
 * 
 * @param mysqli $conn Koneksi database
 * @param string $table Nama tabel
 * @param string $column Nama kolom primary key
 * @param string $uuid UUID yang akan di-check
 * @return bool true jika sudah ada (duplikat), false jika belum ada (unique)
 */
function checkUUIDExists($conn, $table, $column, $uuid)
{
    $check_query = "SELECT {$column} FROM {$table} WHERE {$column} = ?";
    $check_stmt = $conn->prepare($check_query);
    
    if (!$check_stmt) {
        return false; // Jika prepare gagal, anggap tidak duplikat
    }
    
    $check_stmt->bind_param("s", $uuid);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $exists = $check_result->num_rows > 0;
    $check_stmt->close();
    
    return $exists;
}

