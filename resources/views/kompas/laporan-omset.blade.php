<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Laporan Dashboard Omset — {{ $labelTanggal }}</title>
<style>
  * { font-family: DejaVu Sans, sans-serif; }
  body { font-size: 10px; color: #2b2b2b; margin: 22px 26px; }
  table { border-collapse: collapse; }
  .gold { color: #a9791f; }
  .muted { color: #777; }
  .small { font-size: 8.5px; }
  .head-brand { font-size: 17px; font-weight: bold; }
  .head-sub { font-size: 8px; letter-spacing: 3px; color: #a9791f; font-weight: bold; }
  .head-addr { font-size: 8.5px; color: #666; }
  .head-title { font-size: 8px; letter-spacing: 3px; color: #a9791f; font-weight: bold; text-align: right; }
  .head-date { font-size: 17px; font-weight: bold; text-align: right; }
  .head-desc { font-size: 8.5px; color: #666; text-align: right; }
  .rule { background: #c8961f; height: 3px; }
  .strip td { background: #faf6ec; border: 1px solid #e3d5b3; padding: 6px 8px; font-size: 9px; }
  .card td { border: 1px solid #d8d8d8; padding: 7px 9px; vertical-align: top; }
  .card .cap { font-size: 7.5px; letter-spacing: 1px; color: #777; }
  .card .val { font-size: 14px; font-weight: bold; margin-top: 2px; }
  .card .sub2 { font-size: 8px; color: #666; margin-top: 2px; }
  .hl td { background: #fdf6e3; }
  .sec { background: #f5f1e8; border: 1px solid #ddd; font-weight: bold; font-size: 10px; padding: 6px 8px; }
  .rows td { border-left: 1px solid #ddd; border-right: 1px solid #ddd; border-bottom: 1px solid #eee; padding: 5px 8px; }
  .rows td.num, .rows th.num { text-align: right; }
  .rows tr.total td { background: #fdf6e3; font-weight: bold; }
  .note td { background: #fdf6e3; border: 1px solid #e3d5b3; padding: 8px 10px; font-size: 9px; }
  .foot { font-size: 8px; color: #888; margin-top: 12px; }
</style>
</head>
<body>

<table width="100%">
  <tr>
    <td width="52%" valign="top">
      <table><tr>
        <td valign="top"><img src="{{ public_path('img/logo-laksamana.png') }}" height="44"></td>
        <td valign="top" style="padding-left:8px;">
          <div class="head-brand">Laksamana Muda</div>
          <div class="head-sub">COFFEE &amp; LIVE SPACE</div>
          <div class="head-addr">Jl. Soekarno Hatta No. 39, Payung Sekaki,<br>Pekanbaru</div>
        </td>
      </tr></table>
    </td>
    <td width="48%" valign="top">
      <div class="head-title">LAPORAN DASHBOARD OMSET</div>
      <div class="head-date">{{ $labelTanggal }}</div>
      <div class="head-desc">Pencapaian target, komposisi &amp; indikator transaksi</div>
    </td>
  </tr>
</table>

<div class="rule">&nbsp;</div>

<table width="100%" class="strip" style="margin-top:8px;">
  <tr>
    <td width="25%"><b>Periode:</b> {{ $labelTanggal }}</td>
    <td width="25%"><b>Mode:</b> Laporan harian</td>
    <td width="25%"><b>Hari Terinput:</b> 1 hari</td>
    <td width="25%"><b>Dicetak Oleh:</b> {{ $oleh }}</td>
  </tr>
</table>

<table width="100%" class="card" style="margin-top:8px;" cellpadding="0" cellspacing="4">
  <tr>
    <td width="25%">
      <div class="cap">REALISASI (TAGIHAN)</div>
      <div class="val">{{ $rp($hari['tagihan']) }}</div>
      <div class="sub2">net {{ $rp($hari['net']) }} + tax &amp; service {{ $rp($hari['taxSvc']) }}</div>
    </td>
    <td width="25%">
      <div class="cap">RATA-RATA PER HARI</div>
      <div class="val">{{ $rp($hari['tagihan']) }}</div>
    </td>
    <td width="25%">
      <div class="cap">TOTAL BILL</div>
      <div class="val">{{ number_format($hari['bill'], 0, ',', '.') }}</div>
    </td>
    <td width="25%" class="hl" style="background:#fdf6e3;">
      <div class="cap">TOTAL TRAFFIC</div>
      <div class="val">{{ number_format($hari['traffic'], 0, ',', '.') }}</div>
    </td>
  </tr>
</table>

<table width="100%" class="card" cellpadding="0" cellspacing="4">
  <tr>
    <td width="34%">
      <div class="cap">REALISASI BULAN INI</div>
      <div class="val">{{ $rp($bulan['tagihan']) }}</div>
      <div class="sub2">{{ $labelBulan }} · net {{ $rp($bulan['net']) }}</div>
    </td>
    <td width="33%">
      <div class="cap">HARI TERINPUT</div>
      <div class="val">{{ $bulanHari }} hari</div>
    </td>
    <td width="33%" class="hl" style="background:#fdf6e3;">
      <div class="cap">RATA-RATA PER HARI</div>
      <div class="val">{{ $rp($bulanHari ? $bulan['tagihan'] / $bulanHari : 0) }}</div>
    </td>
  </tr>
</table>

<table width="100%" cellpadding="0" cellspacing="6">
  <tr>
    <td width="50%" valign="top">
      <table width="100%">
        <tr><td class="sec">Komposisi Omset &amp; Rasio</td></tr>
      </table>
      <table width="100%" class="rows">
        <tr><td>Food</td><td class="num"><b>{{ $rp($hari['food']) }} · {{ number_format($hari['rFood'], 1, ',', '.') }}%</b></td></tr>
        <tr><td>Beverage</td><td class="num"><b>{{ $rp($hari['bev']) }} · {{ number_format($hari['rBev'], 1, ',', '.') }}%</b></td></tr>
        <tr><td>Lainnya</td><td class="num"><b>{{ $rp($hari['lainnya']) }} · {{ number_format($hari['rLain'], 1, ',', '.') }}%</b></td></tr>
        <tr class="total"><td>Total Komposisi<br><span class="small muted">penyebut rasio, sebelum diskon</span></td><td class="num">{{ $rp($hari['food'] + $hari['bev'] + $hari['lainnya']) }}</td></tr>
      </table>
      <table width="100%" style="margin-top:8px;">
        <tr><td class="sec">Jumlah Item Terjual (Qty)</td></tr>
      </table>
      <table width="100%" class="rows">
        <tr><td>Qty Item Food</td><td class="num"><b>{{ number_format($hari['qfood'], 0, ',', '.') }}</b></td></tr>
        <tr><td>Qty Item Beverage</td><td class="num"><b>{{ number_format($hari['qbev'], 0, ',', '.') }}</b></td></tr>
        <tr><td>Qty Item Lainnya</td><td class="num"><b>{{ number_format($hari['qothers'], 0, ',', '.') }}</b></td></tr>
        <tr class="total"><td>Total Item Terjual</td><td class="num">{{ number_format($hari['totalItem'], 0, ',', '.') }}</td></tr>
      </table>
    </td>
    <td width="50%" valign="top">
      <table width="100%">
        <tr><td class="sec">Indikator Transaksi</td></tr>
      </table>
      <table width="100%" class="rows">
        <tr><td><span class="muted small">UPT</span> item ÷ bill</td><td class="num"><b>{{ number_format($hari['upt'], 2, ',', '.') }}</b></td></tr>
        <tr><td><span class="muted small">ATV</span> omset ÷ bill</td><td class="num"><b>{{ $rp($hari['atv']) }}</b></td></tr>
        <tr><td><span class="muted small">APS</span> traffic ÷ bill</td><td class="num"><b>{{ number_format($hari['aps'], 2, ',', '.') }}</b></td></tr>
        <tr><td>Total Bill</td><td class="num">{{ number_format($hari['bill'], 0, ',', '.') }}</td></tr>
        <tr><td>Total Traffic</td><td class="num">{{ number_format($hari['traffic'], 0, ',', '.') }}</td></tr>
      </table>
      <table width="100%" style="margin-top:8px;">
        <tr><td class="sec">Jalur Pemesanan &amp; Pos di Luar Omset</td></tr>
      </table>
      <table width="100%" class="rows">
        <tr><td>Self Order</td><td class="num"><b>{{ $rp($hari['self']) }} · {{ number_format($hari['selfPct'], 1, ',', '.') }}%</b></td></tr>
        <tr><td>Lewat Kasir</td><td class="num"><b>{{ $rp($hari['kasir']) }}</b></td></tr>
        <tr><td>Service Charge</td><td class="num"><b>{{ $rp($hari['svcOnly']) }}</b></td></tr>
        <tr><td>Tax</td><td class="num"><b>{{ $rp($hari['taxOnly']) }}</b></td></tr>
        <tr class="total"><td>Tax &amp; Service<br><span class="small muted">di luar omset net</span></td><td class="num">{{ $rp($hari['taxSvc']) }}</td></tr>
        <tr><td>Total Discount</td><td class="num"><b>{{ $rp($hari['discount']) }}</b></td></tr>
        <tr><td>Total Compliment</td><td class="num"><b>{{ $rp($hari['comp']) }}</b></td></tr>
      </table>
    </td>
  </tr>
</table>

<table width="100%" class="note" style="margin-top:8px;">
  <tr><td><b class="gold">Omset yang dilaporkan net</b> — sudah dikurangi diskon; tax &amp; service charge di luarnya. Rasio komposisi dihitung terhadap Food + Beverage + Lainnya sebelum diskon, jadi ketiganya selalu berjumlah 100%.</td></tr>
</table>

<table width="100%" class="foot">
  <tr>
    <td>Laksamana Muda Coffee &amp; Live Space · Modul KOMPAS</td>
    <td style="text-align:right;">Dicetak {{ $dicetakPada }} oleh {{ $oleh }}</td>
  </tr>
</table>

</body>
</html>
