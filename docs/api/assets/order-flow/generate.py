"""Regenerate the audit diagrams using Python 3 and Pillow."""

from html import escape
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent
FONT = Path('/usr/share/fonts/truetype/dejavu')
COLORS = {
    'normal': ('#f0f6ff', '#265a99'),
    'storage': ('#edf7f1', '#297149'),
    'note': ('#fff7e5', '#926714'),
    'warning': ('#fff0ef', '#a3302a'),
}


class Diagram:
    def __init__(self, title, subtitle, height):
        self.height = height
        self.image = Image.new('RGB', (2000, height * 2), 'white')
        self.draw = ImageDraw.Draw(self.image)
        self.svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="{height}" viewBox="0 0 1000 {height}">', '<rect width="100%" height="100%" fill="white"/>']
        self.text(35, 45, title, 27, bold=True)
        self.text(35, 79, subtitle, 16)

    def text(self, x, y, value, size=19, bold=False):
        font = ImageFont.truetype(str(FONT / ('DejaVuSans-Bold.ttf' if bold else 'DejaVuSans.ttf')), size * 2)
        self.draw.text((x * 2, y * 2), value, font=font, fill='#172b3a', anchor='ls')
        self.svg.append(f'<text x="{x}" y="{y}" font-family="DejaVu Sans, sans-serif" font-size="{size}" font-weight="{"bold" if bold else "normal"}" fill="#172b3a">{escape(value)}</text>')

    def box(self, y, title, lines, style='normal', x=35, w=600, h=112):
        fill, border = COLORS[style]
        self.draw.rounded_rectangle((x * 2, y * 2, (x + w) * 2, (y + h) * 2), radius=16, fill=fill, outline=border, width=3)
        self.svg.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="8" fill="{fill}" stroke="{border}" stroke-width="1.5"/>')
        title_size = 20 if w > 400 else 18
        while ImageFont.truetype(str(FONT / 'DejaVuSans-Bold.ttf'), title_size * 2).getlength(title) > (w - 36) * 2:
            title_size -= 1
        self.text(x + 18, y + 30, title, title_size, bold=True)
        for i, line in enumerate(lines):
            size = 18 if w > 400 else 16
            assert ImageFont.truetype(str(FONT / 'DejaVuSans.ttf'), size * 2).getlength(line) <= (w - 36) * 2, line
            assert 58 + i * 24 < h - 5, line
            self.text(x + 18, y + 58 + i * 24, line, size)

    def arrow(self, start, end, label=None):
        x1, y1 = start
        x2, y2 = end
        self.draw.line((x1 * 2, y1 * 2, x2 * 2, y2 * 2), fill='#42576b', width=4)
        if x1 == x2:
            points = [(x2, y2), (x2 - 6, y2 - 10), (x2 + 6, y2 - 10)]
        else:
            points = [(x2, y2), (x2 - 10, y2 - 6), (x2 - 10, y2 + 6)]
        self.draw.polygon([(x * 2, y * 2) for x, y in points], fill='#42576b')
        self.svg.append(f'<path d="M{x1},{y1} L{x2},{y2}" stroke="#42576b" stroke-width="2"/><polygon points="{" ".join(f"{x},{y}" for x, y in points)}" fill="#42576b"/>')
        if label:
            self.text(x1 + 10, (y1 + y2) / 2 + 5, label, 15)

    def chain(self, rows):
        for i, (title, lines, style) in enumerate(rows):
            y = 115 + i * 158
            self.box(y, title, lines, style)
            if i < len(rows) - 1:
                self.arrow((335, y + 112), (335, y + 158))

    def note(self, y, title, lines, warning=False, h=136):
        self.box(y, title, lines, 'warning' if warning else 'note', x=665, w=300, h=h)

    def save(self, name):
        self.text(35, self.height - 20, 'Audit backend Walatra | 12 November 2026 | Catatan mengikuti implementasi saat audit', 13)
        self.image.save(ROOT / f'{name}.png', optimize=True)
        (ROOT / f'{name}.svg').write_text('\n'.join(self.svg + ['</svg>']))


d = Diagram('01. Alur pembelian dari cart sampai lunas', 'Semua endpoint customer memakai Bearer token dan prefix /api/v1.', 1455)
d.chain([
    ('Customer memilih produk atau bundle', ['Gunakan listing toko dan varian yang tersedia.', 'Produk dan bundle masuk ke satu cart.'], 'normal'),
    ('POST /cart/items', ['Backend validasi barang dan toko.', 'Simpan carts + cart_items; item sama dijumlahkan.'], 'storage'),
    ('GET /cart lalu pilih item dan alamat', ['Kirim pilihan ke GET cart untuk ringkasan.', 'Tanpa pilihan, ringkasan GET kosong.'], 'normal'),
    ('POST /checkout/preview', ['Periksa harga, gudang, stok, dan ongkir.', 'Kembalikan total serta checkout_token.'], 'normal'),
    ('POST /orders + Idempotency-Key', ['Wajib token preview valid untuk order baru.', 'Simpan snapshot lalu hapus item terpilih.'], 'storage'),
    ('POST /orders/{id}/payment', ['Pilih metode; pakai key pembayaran tersendiri.', 'Backend charge Midtrans lalu simpan VA / QRIS.'], 'normal'),
    ('Customer membayar VA atau QRIS', ['Order tetap pending_payment selama menunggu.', 'Frontend membaca status dari backend.'], 'normal'),
    ('Webhook -> queue -> verifikasi Midtrans', ['Periksa transaksi lalu update order menjadi paid.', 'Simpan hasil pembayaran dan riwayat status.'], 'storage'),
])
d.note(430, 'Preview belum order', ['Token berlaku 15 menit.', 'Tidak mereservasi stok.', 'Jika pilihan berubah,', 'buat preview baru.'])
d.note(747, 'Order belum punya VA', ['Respons awal: payment null.', 'VA / QRIS diterbitkan', 'di langkah pembayaran.'])
d.note(1060, 'Lunas bukan sudah dikirim', ['paid hanya konfirmasi bayar.', 'Pengiriman dan nomor resi', 'berada di luar flow ini.'])
d.save('01-overview')

d = Diagram('02. Validasi dan penyimpanan saat checkout', 'Jika validasi gagal, order tidak dibuat dan item cart tetap tersedia.', 1300)
d.chain([
    ('Baca cart milik customer', ['Pilihan checkout harus dari cart sendiri.', 'Tanpa pilihan checkout berarti seluruh cart.'], 'normal'),
    ('Periksa ketersediaan dan harga master', ['Produk: listing + toko + produk + varian aktif.', 'Bundle: listing + master + komponen tersedia.'], 'normal'),
    ('Tentukan satu titik pengiriman bersama', ['Cari irisan gudang seluruh varian terpilih.', 'Termasuk setiap varian komponen bundle.'], 'normal'),
    ('Validasi checkout_token dan hitung total', ['Token mengikat user, item, jumlah, harga, gudang.', 'Ongkir gratis: shipping_fee = 0.'], 'normal'),
    ('Periksa / reservasi stok jika external', ['Mode unlimited melewati check dan reserve.', 'Tetap membutuhkan titik pengiriman yang valid.'], 'normal'),
    ('Transaksi database + salinan foto', ['orders + order_items + media + status_histories.', 'status pending_payment untuk alur Midtrans.'], 'storage'),
    ('Commit dan kembalikan order', ['Hapus hanya cart_items yang masuk order.', 'Item lainnya tetap berada dalam cart.'], 'storage'),
])
d.note(115, 'Cart barang terhapus', ['Tetap terbaca dan bisa dihapus.', 'Ditandai Tidak tersedia.', 'Tidak bisa dibeli.'])
d.note(273, '409: tinjau ulang', ['Barang tidak tersedia,', 'harga atau token berubah,', 'gudang tidak cocok.'])
d.note(430, 'Bukan jarak terdekat', ['Belum menghitung jarak.', 'Lebih dari satu gudang:', 'butuh preferensi server.'])
d.note(590, 'Batas token saat ini', ['Token belum mengikat', 'alamat, ongkir, maupun', 'komposisi bundle.'], warning=True)
d.note(905, 'Foto gagal disalin', ['Rollback order dan item.', 'Bersihkan salinan baru.', 'Cart tetap tersedia.'])
d.save('02-checkout')

d = Diagram('03. Pembayaran dan notifikasi Midtrans', 'Webhook diterima tidak langsung berarti order sudah lunas.', 1455)
d.chain([
    ('POST /orders/{id}/payment', ['Backend periksa order, deadline, key, dan lock.', 'Catat payment_requests serta order_payments.'], 'storage'),
    ('Backend -> Midtrans: POST /v2/charge', ['Gunakan provider_order_id khusus tiap attempt.', 'Server Key tetap berada di backend.'], 'normal'),
    ('Simpan dan kirim instruksi bayar', ['VA: bank + va_number; QRIS: qr_code_url.', 'Metode Mandiri memakai bill_key + biller_code.'], 'storage'),
    ('Customer membayar -> Midtrans', ['Frontend dapat GET order / payment.', 'GET membaca database, tanpa sinkronisasi provider.'], 'normal'),
    ('Midtrans -> POST /payments/midtrans/webhook', ['Validasi SHA-512 dan deduplikasi notifikasi.', 'Simpan payment_notifications; respons HTTP 200.'], 'storage'),
    ('Worker payments memproses notifikasi', ['Cari attempt melalui provider_order_id.', 'Backend GET /v2/{provider_order_id}/status.'], 'normal'),
    ('Validasi hasil resmi dari Midtrans', ['Cocokkan identitas, nominal, mata uang, metode.', 'Periksa status pembayaran dan fraud.'], 'normal'),
    ('Update order dan riwayat di database', ['Normal: pending_payment menjadi paid.', 'Kasus khusus: payment_review / refunded.'], 'storage'),
])
d.note(115, 'Request bersamaan', ['HTTP 202:', 'PAYMENT_PROCESSING.', 'Baca ulang status.'])
d.note(273, 'Charge timeout', ['Attempt dapat unknown.', 'Retry key yang sama.', 'Jangan checkout ulang.'], warning=True)
d.note(748, 'Worker wajib aktif', ['Queue: payments.', 'Scheduler setiap menit:', 'payments:reconcile.'])
d.note(905, 'Refresh punya efek lain', ['Attempt unknown / creating', 'yang belum ada di provider', 'dapat di-charge ulang.'], warning=True)
d.note(1220, 'Perlu pemeriksaan', ['Pembayaran ganda, terlambat,', 'attempt lama, atau reversal.', 'Jangan otomatis kirim barang.'])
d.save('03-payment')
