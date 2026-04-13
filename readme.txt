=== Bayarku DOKU for WooCommerce ===
Contributors: panduaji
Tags: woocommerce, payment gateway, qris, doku
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
WC requires at least: 7.0
WC tested up to: 10.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

DOKU payment gateway for WooCommerce. Saat ini mendukung QRIS — QR Code ditampilkan langsung di website Anda, tanpa redirect, polling otomatis, webhook backup.

== Description ==

Bayarku DOKU mengintegrasikan metode pembayaran DOKU langsung ke WooCommerce menggunakan DOKU SNAP API resmi.

**Saat ini tersedia:**
- DOKU QRIS — QR Code ditampilkan di website Anda sendiri, pembeli tidak pernah meninggalkan halaman Anda

**Segera hadir:**
- DOKU Virtual Account
- DOKU eWallet (OVO, GoPay, Dana)

**Fitur:**
- QR Code QRIS ditampilkan di website Anda sendiri — pembeli tidak pernah meninggalkan halaman Anda
- Polling otomatis setiap 4 detik — halaman redirect ke thank-you page secara otomatis saat pembayaran berhasil
- Webhook backup (`POST /wp-json/bayarku/v1/notify/doku`) — menangkap pembayaran yang terlewat polling
- Sandbox / Production toggle di WP Admin
- Kredensial tersimpan aman di WordPress options — tidak pernah di-hardcode
- HPOS (High-Performance Order Storage) compatible
- QR Code di-generate lokal menggunakan PHP GD — tidak ada request ke layanan eksternal selain DOKU

**Penting:** Anda perlu mendaftar di DOKU secara terpisah di https://dashboard.doku.com untuk mendapatkan Client ID, Shared Key, Private Key, dan Terminal ID.

== Installation ==

1. Upload folder `bayarku` ke `/wp-content/plugins/`.
2. Aktifkan plugin melalui menu WordPress Plugins.
3. Buka **WooCommerce → Pengaturan → Pembayaran → QRIS (DOKU)**.
4. Masukkan kredensial DOKU Anda (Client ID, Shared Key, Private Key, QRIS Client ID, Terminal ID).
5. Set webhook URL di dashboard DOKU ke: `https://yourdomain.com/wp-json/bayarku/v1/notify/doku`
6. Matikan Sandbox saat siap ke production.
7. Simpan dan uji dengan pesanan nyata.

Setelah mengaktifkan plugin, buka **Pengaturan → Permalink** dan klik Simpan untuk flush rewrite rules (diperlukan untuk halaman pembayaran).

== Frequently Asked Questions ==

= Apakah saya perlu akun DOKU? =
Ya. Daftar di https://dashboard.doku.com. Plugin ini menggunakan DOKU SNAP API yang memerlukan kredensial resmi.

= Di mana QR Code ditampilkan? =
Di website Anda sendiri di `/bayarku-payment/` — pembeli tidak pernah meninggalkan website Anda.

= Apakah plugin ini berafiliasi dengan DOKU? =
Tidak. Ini adalah plugin open-source independen yang menggunakan DOKU SNAP API publik.

= Apakah bisa digunakan di mode sandbox? =
Ya. Ada toggle Sandbox di halaman pengaturan gateway.

= Apakah Virtual Account dan eWallet sudah bisa digunakan? =
Belum. VA dan eWallet sedang dalam pengembangan dan akan tersedia di versi berikutnya.

== Third-Party Libraries ==

Plugin ini menyertakan library open-source berikut:

* **QR Code Generator for PHP** by Kazuhiko Arase
* Source: https://github.com/kazuhikoarase/qrcode-generator
* License: MIT
* Location: `includes/lib/qrcode.php`
* Purpose: Generate QR Code image secara lokal menggunakan PHP GD extension — tidak memerlukan layanan eksternal.

== External Services ==

Plugin ini terhubung ke layanan eksternal berikut:

= api.doku.com / api-sandbox.doku.com (DOKU SNAP API) =
Semua operasi pembayaran (generate QR, query status, cancel QR) dikirim ke DOKU SNAP API resmi.

* Production: https://api.doku.com
* Sandbox: https://api-sandbox.doku.com
* Data yang dikirim: jumlah pesanan, kredensial merchant, nomor referensi QR
* Privacy policy: https://www.doku.com/privacy-policy

Anda harus mendaftar di https://dashboard.doku.com dan menyetujui syarat DOKU sebelum menggunakan plugin ini.

== Privacy Policy ==

Plugin ini sendiri tidak mengumpulkan, menyimpan, atau mengirimkan data pribadi di luar apa yang sudah ditangani WooCommerce. Namun:

1. **DOKU API**: Jumlah pesanan dan kredensial merchant dikirim ke server DOKU untuk memproses pembayaran.

QR Code image di-generate **lokal** di server Anda menggunakan library qrcode-generator yang sudah dibundel. Tidak ada data yang dikirim ke layanan eksternal untuk rendering QR.

Pemilik toko yang menggunakan plugin ini di wilayah yang tercakup GDPR atau undang-undang privasi serupa harus mengungkapkan alur data DOKU dalam kebijakan privasi mereka.

== Changelog ==

= 1.0.0 =
* Rilis awal — DOKU QRIS full implementation.
