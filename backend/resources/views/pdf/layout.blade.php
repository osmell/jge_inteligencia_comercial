<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>@yield('title')</title>
<style>
    @page { margin: 28px 30px 36px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2933; }
    h1 { font-size: 16px; margin: 0 0 2px; color: #0b3d91; }
    h2 { font-size: 11px; margin: 14px 0 4px; color: #0b3d91; }
    .meta { color: #52606d; margin: 0 0 8px; line-height: 1.5; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    th { background: #eef2f7; text-align: left; font-size: 8px; text-transform: uppercase; padding: 4px 5px; border-bottom: 1px solid #c5ced8; }
    td { padding: 3px 5px; border-bottom: 1px solid #e4e9ef; }
    .n { text-align: right; white-space: nowrap; }
    .note { color: #52606d; font-size: 8px; margin: 2px 0 0; }
    .warn { border: 1px solid #b7791f; background: #fff8e6; color: #7a4f10; padding: 4px 6px; margin: 3px 0; }
    .ok { color: #16803c; } .mid { color: #b7791f; } .bad { color: #c62828; }
    .footer { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 8px; color: #7b8794; }
</style>
</head>
<body>
<div class="footer">JGE Inteligencia Comercial · Generado por {{ $generatedBy }} el {{ $generatedAt }}</div>
@yield('content')
</body>
</html>
