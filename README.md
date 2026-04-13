# Bayarku DOKU for WooCommerce

**DOKU payment gateway for WooCommerce** — mulai dari QRIS, segera hadir Virtual Account dan eWallet.

[![License: GPLv2](https://img.shields.io/badge/License-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WooCommerce 7.0+](https://img.shields.io/badge/WooCommerce-7.0%2B-purple.svg)](https://woocommerce.com)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg)](https://php.net)
[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org)

---

## Metode Pembayaran

| Metode | Status |
|---|---|
| DOKU QRIS | ✅ Tersedia |
| DOKU Virtual Account | 🚧 Segera hadir |
| DOKU eWallet (OVO, GoPay, Dana) | 🚧 Segera hadir |

## Fitur

- **DOKU QRIS** — QR Code ditampilkan langsung di website Anda (tidak redirect ke halaman DOKU). Polling otomatis setiap 4 detik, redirect ke thank-you page saat pembayaran berhasil.
- **Webhook backup** — `POST /wp-json/bayarku/v1/notify/doku` menangkap pembayaran yang terlewat polling.
- **Sandbox / Production toggle** — ganti environment tanpa ubah kode.
- **QR lokal** — QR Code di-generate di server menggunakan PHP GD + library bundled. Tidak ada request ke layanan eksternal selain DOKU.
- **HPOS compatible** — mendukung WooCommerce High-Performance Order Storage.

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+ dengan extension `gd` dan `openssl`
- Akun merchant [DOKU](https://dashboard.doku.com) dengan akses SNAP API

---

## Installation

### Dari ZIP (manual)

1. Download ZIP dari [halaman Releases](https://github.com/panduaji/bayarku/releases).
2. **WordPress Admin → Plugins → Add New → Upload Plugin**.
3. Upload ZIP, lalu klik **Activate**.

### Dari source

```bash
cd /path/to/wp-content/plugins/
git clone https://github.com/panduaji/bayarku.git bayarku
```

Aktifkan dari **WP Admin → Plugins**.

---

## Konfigurasi

1. Buka **WooCommerce → Pengaturan → Pembayaran → QRIS (DOKU)**.
2. Isi kredensial dari [DOKU Dashboard](https://dashboard.doku.com):

| Field | Keterangan |
|---|---|
| **Client ID (BRN)** | Dashboard → Pengaturan SNAP / API Keys |
| **Client Secret (QRIS)** | Dashboard → Pengaturan Kredensial QRIS → clientSecret |
| **Private Key (RSA)** | Generate RSA key pair PKCS#8, daftarkan public key ke DOKU |
| **QRIS Client ID** | Dashboard → Pengaturan Kredensial QRIS → clientId (angka) |
| **Terminal ID** | Biasanya `A01` kecuali DOKU memberi nilai berbeda |
| **Kode Pos Toko** | Kode pos 5 digit toko Anda |

3. Set **Notify URL** di DOKU Dashboard ke:
   ```
   https://yourdomain.com/wp-json/bayarku/v1/notify/doku
   ```
4. Matikan **Sandbox** saat siap ke production.
5. Buka **Pengaturan → Permalink** dan klik **Simpan** untuk flush rewrite rules.

---

## Cara kerja

```
Checkout → process_payment()
    │
    ├── Call DOKU SNAP API: /qr/qr-mpm-generate
    ├── Simpan QR string + referensi ke order meta
    └── Redirect ke /bayarku-payment/?bayarku_order=X&bayarku_type=qr&bayarku_key=K
             │
             ├── PHP generate QR PNG lokal (GD + bundled qrcode-generator) → simpan base64 di order meta
             ├── JS polling /wp-admin/admin-ajax.php?action=bayarku_poll_qris setiap 4 detik
             │       └── Query DOKU: /qr/qr-mpm-query
             │               ├── status=00 → order.payment_complete() → redirect ke thank-you
             │               └── pending  → lanjut polling
             │
             └── Webhook (backup): POST /wp-json/bayarku/v1/notify/doku
                     └── validasi HMAC → order.payment_complete()
```

---

## Keamanan

- Tanda tangan webhook diverifikasi dengan HMAC-SHA512 + `hash_equals()` (timing-safe).
- AJAX polling dilindungi WordPress nonce per-order.
- Halaman pembayaran memerlukan order key yang valid — tidak bisa di-enumerate.
- RSA signing untuk DOKU B2B token menggunakan `openssl_sign()` dengan SHA-256.
- Semua kredensial tersimpan di WordPress `wp_options`, tidak pernah di-hardcode atau di-log.
- Race condition dicegah dengan transient mutex `bayarku_completing_{order_id}`.

---

## External Services

| Service | Tujuan | Privacy Policy |
|---|---|---|
| `api.doku.com` | DOKU SNAP API (production) | [doku.com/privacy-policy](https://www.doku.com/privacy-policy) |
| `api-sandbox.doku.com` | DOKU SNAP API (sandbox) | Sama |

QR Code di-generate **lokal** menggunakan library [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) PHP (MIT) via PHP GD. Tidak ada request eksternal untuk rendering QR.

---

## License

GPLv2 or later — lihat [LICENSE](LICENSE).

Plugin ini tidak berafiliasi dengan atau didukung oleh DOKU.
