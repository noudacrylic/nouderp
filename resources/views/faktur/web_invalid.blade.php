<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Faktur — Noud Acrylic</title>
<style>
    body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
        background: #eef2f6; color: #1f2937; font-family: "Segoe UI", Arial, sans-serif; padding: 20px; }
    .card { background: #fff; border-radius: 14px; box-shadow: 0 6px 24px rgba(15,23,42,.08);
        padding: 32px 28px; max-width: 380px; text-align: center; }
    .ic { font-size: 34px; }
    h1 { font-size: 16px; margin: 10px 0 6px; }
    p { font-size: 13px; color: #6b7280; margin: 0; }
</style>
</head>
<body>
    <div class="card">
        <div class="ic">🧾</div>
        <h1>Faktur belum tersedia</h1>
        <p>{{ $reason ?? 'Faktur tidak ditemukan.' }}</p>
    </div>
</body>
</html>
