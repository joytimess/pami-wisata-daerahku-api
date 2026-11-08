<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Kembali ke Wisata Daerahku</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f7f4ef; color: #1f2a37; font: 16px/1.55 system-ui, sans-serif; }
        main { box-sizing: border-box; width: 100%; max-width: 420px; margin: 16px; padding: 28px 24px; background: #fff; border-radius: 20px; box-shadow: 0 6px 24px rgb(0 0 0 / 8%); }
        h1 { margin: 0 0 12px; font-size: 20px; color: #0a6e5e; }
        p { margin: 0 0 14px; }
        .button { display: block; padding: 14px 18px; border-radius: 14px; background: #0a6e5e; color: #fff; text-align: center; font-weight: 700; text-decoration: none; }
        .note { margin: 14px 0 0; font-size: 13px; color: #526787; }
    </style>
</head>
<body>
<main>
    <h1>Kembali ke aplikasi</h1>
    @if ($hint === 'success')
        <p>Proses di e-wallet telah ditutup. Hasil pembayaran akan dikonfirmasi oleh server, bukan oleh halaman ini.</p>
    @elseif ($hint === 'cancel')
        <p>Pembayaran di e-wallet dibatalkan atau belum diselesaikan. Anda dapat memeriksa status atau mencoba lagi dari aplikasi.</p>
    @elseif ($hint === 'error')
        <p>Terjadi kendala pada e-wallet. Periksa status di aplikasi sebelum mencoba lagi.</p>
    @else
        <p>Anda kembali dari e-wallet. Status pembayaran sedang diperiksa oleh server dan baru sah setelah dikonfirmasi oleh server.</p>
    @endif
    @if ($appUrl !== '')
        <a class="button" href="{{ $appUrl }}">Buka aplikasi Wisata Daerahku</a>
    @endif
    <p class="note">Jika aplikasi tidak terbuka, buka Wisata Daerahku secara manual. Status pembayaran tersimpan di server.</p>
</main>
</body>
</html>
