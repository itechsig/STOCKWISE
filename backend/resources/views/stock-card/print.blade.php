@php
    /** @var array $cards  StockCardService::present() of each card — one per page */
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
  @page { size: A4 portrait; margin: 12mm 12mm 14mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #111; margin: 0; }
  h1 { text-align: center; font-size: 18px; letter-spacing: 1px; margin: 0 0 10px; }
  .head { display: grid; grid-template-columns: 1fr 1fr; column-gap: 24px; margin-bottom: 10px; }
  .f { display: grid; grid-template-columns: 34mm 1fr; padding: 3px 0; border-bottom: 1px dotted #888; }
  .f span:first-child { color: #333; }
  .f b { font-weight: 600; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #222; padding: 4px 5px; vertical-align: top; }
  th { background: #eee; font-size: 10.5px; letter-spacing: .5px; }
  td.n { text-align: right; white-space: nowrap; }
  td.d { white-space: nowrap; }
  tr { break-inside: avoid; }
  tr.blank td { height: 20px; }
  tfoot td { font-weight: bold; background: #f4f4f4; }
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 28px; text-align: center; break-inside: avoid; }
  .sign .line { margin: 60px auto 4px; width: 60mm; border-bottom: 1px solid #222; }
  .foot { margin-top: 10px; font-size: 9px; color: #666; }
  .card + .card { break-before: page; }
  .proj { text-align: center; font-size: 11px; color: #333; margin: 2px 0 0; }
  /* logo left, title centred on the page: an empty cell as wide as the logo on the right */
  .top { display: grid; grid-template-columns: 22mm 1fr 22mm; align-items: center; margin-bottom: 10px; }
  .top img { width: 20mm; height: 20mm; object-fit: contain; }
  .top h1 { margin: 0; }
</style>
</head>
<body>
@php
    // company logo, embedded so the headless-Chrome PDF never depends on a URL
    $logoFile = public_path('logo.png');
    $logo = is_file($logoFile) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoFile)) : null;
@endphp
@foreach ($cards as $c)
  @include('stock-card._card', ['c' => $c, 'logo' => $logo])
@endforeach
</body>
</html>
