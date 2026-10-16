<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Laporan Omset Harian — {{ $labelTanggal }}</title>
<style>
  * { font-family: DejaVu Sans, sans-serif; }
  body { font-size: 11px; color: #222; margin: 24px 28px; }
  h1 { font-size: 17px; margin: 0 0 2px; }
  h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 2px solid #a9791f; padding-bottom: 3px; }
  .sub { color: #666; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  th, td { border: 1px solid #bbb; padding: 5px 8px; }
  th { background: #f2e9d3; text-align: left; }
  td.num, th.num { text-align: right; }
  tr.total td { font-weight: bold; background: #faf6ec; }
  .note { color: #666; font-size: 10px; margin-top: 4px; }
  .foot { margin-top: 16px; color: #666; font-size: 10px; }
</style>
</head>
<body>
<h1>DASHBOARD OMSET — {{ $labelTanggal }}</h1>
<div class="sub">Laksamana Muda · laporan harian</div>

<h2>Pendapatan</h2>
<table>
  <tr><th>Komponen</th><th class="num">Nominal</th></tr>
  <tr><td>Food</td><td class="num">{{ $rp($hari['food']) }}</td></tr>
  <tr><td>Beverage</td><td class="num">{{ $rp($hari['bev']) }}</td></tr>
  <tr><td>Lainnya</td><td class="num">{{ $rp($hari['lainnya']) }}</td></tr>
  <tr><td>Discount</td><td class="num">({{ $rp($hari['discount']) }})</td></tr>
  <tr class="total"><td>Omset Net</td><td class="num">{{ $rp($hari['net']) }}</td></tr>
  <tr><td>Tax &amp; Service</td><td class="num">{{ $rp($hari['taxSvc']) }}</td></tr>
  <tr class="total"><td>Tagihan (dibayar tamu)</td><td class="num">{{ $rp($hari['tagihan']) }}</td></tr>
</table>

<h2>Komposisi (dari Food+Bev+Lainnya {{ $rp($hari['food'] + $hari['bev'] + $hari['lainnya']) }})</h2>
<table>
  <tr><th>Komponen</th><th class="num">Nominal</th><th class="num">%</th></tr>
  <tr><td>Food</td><td class="num">{{ $rp($hari['food']) }}</td><td class="num">{{ number_format($hari['rFood'], 1, ',', '.') }}%</td></tr>
  <tr><td>Beverage</td><td class="num">{{ $rp($hari['bev']) }}</td><td class="num">{{ number_format($hari['rBev'], 1, ',', '.') }}%</td></tr>
  <tr><td>Lainnya</td><td class="num">{{ $rp($hari['lainnya']) }}</td><td class="num">{{ number_format($hari['rLain'], 1, ',', '.') }}%</td></tr>
</table>

<h2>Item Terjual</h2>
<table>
  <tr><th>Kategori</th><th class="num">Qty</th></tr>
  <tr><td>Food</td><td class="num">{{ number_format($hari['qfood'], 0, ',', '.') }}</td></tr>
  <tr><td>Beverage</td><td class="num">{{ number_format($hari['qbev'], 0, ',', '.') }}</td></tr>
  <tr><td>Lainnya</td><td class="num">{{ number_format($hari['qothers'], 0, ',', '.') }}</td></tr>
  <tr class="total"><td>Total Item</td><td class="num">{{ number_format($hari['totalItem'], 0, ',', '.') }}</td></tr>
</table>

<h2>Jalur Pemesanan</h2>
<table>
  <tr><th>Jalur</th><th class="num">Nominal</th></tr>
  <tr><td>Self Order ({{ number_format($hari['selfPct'], 1, ',', '.') }}%)</td><td class="num">{{ $rp($hari['self']) }}</td></tr>
  <tr><td>Lewat Kasir</td><td class="num">{{ $rp($hari['kasir']) }}</td></tr>
</table>

<h2>Indikator &amp; Trafik</h2>
<table>
  <tr><th>Indikator</th><th class="num">Nilai</th></tr>
  <tr><td>Total Bill</td><td class="num">{{ number_format($hari['bill'], 0, ',', '.') }}</td></tr>
  <tr><td>Total Traffic</td><td class="num">{{ number_format($hari['traffic'], 0, ',', '.') }}</td></tr>
  <tr><td>UPT (item/bill)</td><td class="num">{{ number_format($hari['upt'], 2, ',', '.') }}</td></tr>
  <tr><td>ATV (net/bill)</td><td class="num">{{ $rp($hari['atv']) }}</td></tr>
  <tr><td>APS (traffic/bill)</td><td class="num">{{ number_format($hari['aps'], 2, ',', '.') }}</td></tr>
  <tr><td>Compliment</td><td class="num">{{ $rp($hari['comp']) }}</td></tr>
</table>

<h2>Bulan Berjalan — {{ $labelBulan }} ({{ $bulanHari }} hari terinput)</h2>
<table>
  <tr><th>Ukuran</th><th class="num">Nilai</th></tr>
  <tr><td>Realisasi (tagihan)</td><td class="num">{{ $rp($bulan['tagihan']) }}</td></tr>
  <tr><td>Net</td><td class="num">{{ $rp($bulan['net']) }}</td></tr>
  <tr><td>Rata per hari (tagihan)</td><td class="num">{{ $rp($bulanHari ? $bulan['tagihan'] / $bulanHari : 0) }}</td></tr>
</table>

<div class="foot">Dicetak otomatis dari data Input Omset Harian · Laksamana Muda</div>
</body>
</html>
