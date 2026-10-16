<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Laporan Dashboard Omset — {{ $labelTanggal }}</title>
<style>
  @page { margin: 10mm; }
  * { font-family: "DejaVu Sans", sans-serif; }
  body { font-size: 9.5px; color: #0C1826; margin: 0; }
  table { border-collapse: collapse; }
  .serif { font-family: "DejaVu Serif", serif; }
  .muted { color: #6B6B6B; }
  .gold { color: #C8940A; }
  .brand { font-size: 17px; font-weight: bold; }
  .brand-sub { font-size: 7px; letter-spacing: .2em; color: #C8940A; font-weight: bold; }
  .brand-addr { font-size: 8px; color: #666; }
  .kop-title { font-size: 7.5px; color: #C8940A; text-align: right; }
  .kop-date { font-size: 15.5px; font-weight: bold; text-align: right; }
  .kop-desc { font-size: 8.5px; color: #555; text-align: right; }
  .rule { background: #F7BB0E; height: 2.5px; }
  .meta td { background: #F6F4EE; border: 1px solid #EAE5D9; border-radius: 8px; padding: 6px 10px; font-size: 9px; }
  .meta .lab { font-size: 7px; color: #777; }
  .meta .val { font-weight: bold; }
  .kpi td { border: 1px solid #E3DFD5; border-radius: 8px; padding: 7px 10px; vertical-align: top; }
  .kpi .lab { font-size: 7px; color: #777; }
  .kpi .val { font-size: 13px; font-weight: 800; margin-top: 2px; }
  .kpi .sub { font-size: 8px; color: #666; margin-top: 2px; }
  .kpi td.hl { background: #FBF3D8; border: 1px solid #EBD9A2; }
  .sec td { background: #F1EEE7; font-weight: bold; font-size: 9.5px; padding: 5px 11px; border: 1px solid #E3DFD5; }
  .rows td { font-size: 9px; padding: 4.5px 11px; border-left: 1px solid #E3DFD5; border-right: 1px solid #E3DFD5; border-bottom: 1px solid #eee; }
  .rows td.k { color: #6B6B6B; width: 52%; }
  .rows td.v { text-align: right; font-weight: bold; }
  .rows tr.sum td { background: #FBF3D8; }
  .note td { background: #FBF3D8; border: 1px solid #EBD9A2; border-radius: 8px; padding: 8px 11px; font-size: 8.5px; }
  .foot td { border-top: 1px solid #E3DFD5; padding-top: 6px; font-size: 7.5px; color: #8A8A8A; }
  .foot .r { text-align: right; }
</style>
</head>
<body>

<table width="100%">
  <tr>
    <td width="54%" valign="top">
      <table><tr>
        @if($logo)<td valign="top"><img src="{{ $logo }}" height="52"></td>@endif
        <td valign="top" style="padding-left:8px;">
          <div class="brand serif">Laksamana Muda</div>
          <div class="brand-sub">COFFEE &amp; LIVE SPACE</div>
          <div class="brand-addr">Jl. Soekarno Hatta No. 39, Payung Sekaki, Pekanbaru</div>
        </td>
      </tr></table>
    </td>
    <td width="46%" valign="top">
      <div class="kop-title">LAPORAN DASHBOARD OMSET</div>
      <div class="kop-date serif">{{ $labelTanggal }}</div>
      <div class="kop-desc">Pencapaian target, komposisi &amp; indikator transaksi</div>
    </td>
  </tr>
</table>

<div class="rule">&nbsp;</div>

<table width="100%" class="meta" style="margin-top:8px;">
  <tr>
    <td width="25%"><div class="lab">PERIODE</div><div class="val">{{ $labelTanggal }}</div></td>
    <td width="25%"><div class="lab">MODE</div><div class="val">Laporan harian</div></td>
    <td width="25%"><div class="lab">HARI TERINPUT</div><div class="val">{{ $hariTerinput }}</div></td>
    <td width="25%"><div class="lab">DICETAK OLEH</div><div class="val">{{ $oleh }}</div></td>
  </tr>
</table>

<table width="100%" class="kpi" cellpadding="0" cellspacing="5">
  <tr>
    <td width="25%">
      <div class="lab">REALISASI (TAGIHAN)</div>
      <div class="val">{{ $h['tagihan'] }}</div>
      <div class="sub">net {{ $h['net'] }} + tax &amp; service {{ $h['taxSvc'] }}</div>
    </td>
    <td width="25%">
      <div class="lab">RATA-RATA PER HARI</div>
      <div class="val">{{ $h['tagihan'] }}</div>
    </td>
    <td width="25%">
      <div class="lab">TOTAL BILL</div>
      <div class="val">{{ $h['bill'] }}</div>
    </td>
    <td width="25%" class="hl">
      <div class="lab">TOTAL TRAFFIC</div>
      <div class="val">{{ $h['traffic'] }}</div>
    </td>
  </tr>
</table>

<table width="100%" class="kpi" cellpadding="0" cellspacing="5">
  <tr>
    <td width="34%">
      <div class="lab">REALISASI BULAN INI</div>
      <div class="val">{{ $bulanTagihan }}</div>
      <div class="sub">{{ $labelBulan }} · net {{ $bulanNet }}</div>
    </td>
    <td width="33%">
      <div class="lab">HARI TERINPUT</div>
      <div class="val">{{ $bulanHari }}</div>
    </td>
    <td width="33%" class="hl">
      <div class="lab">RATA-RATA PER HARI</div>
      <div class="val">{{ $bulanRata }}</div>
    </td>
  </tr>
</table>

<table width="100%" cellpadding="0" cellspacing="6">
  <tr>
    <td width="50%" valign="top">
      <table width="100%"><tr><td class="sec">Komposisi Omset &amp; Rasio</td></tr></table>
      <table width="100%" class="rows">
        <tr><td class="k">Food</td><td class="v">{{ $h['food'] }} · {{ $h['rFood'] }}</td></tr>
        <tr><td class="k">Beverage</td><td class="v">{{ $h['bev'] }} · {{ $h['rBev'] }}</td></tr>
        <tr><td class="k">Lainnya</td><td class="v">{{ $h['lainnya'] }} · {{ $h['rLain'] }}</td></tr>
        <tr class="sum"><td class="k">Total Komposisi<br><span style="font-size:8px;">penyebut rasio, sebelum diskon</span></td><td class="v">{{ $h['kompo'] }}</td></tr>
      </table>
      <table width="100%" style="margin-top:8px;"><tr><td class="sec">Jumlah Item Terjual (Qty)</td></tr></table>
      <table width="100%" class="rows">
        <tr><td class="k">Qty Food</td><td class="v">{{ $h['qfood'] }}</td></tr>
        <tr><td class="k">Qty Beverage</td><td class="v">{{ $h['qbev'] }}</td></tr>
        <tr><td class="k">Qty Lainnya</td><td class="v">{{ $h['qothers'] }}</td></tr>
        <tr class="sum"><td class="k">Total Item Terjual</td><td class="v">{{ $h['totalItem'] }}</td></tr>
      </table>
    </td>
    <td width="50%" valign="top">
      <table width="100%"><tr><td class="sec">Indikator Transaksi</td></tr></table>
      <table width="100%" class="rows">
        <tr><td class="k">UPT <span class="muted" style="font-size:8px;">(item per bill)</span></td><td class="v">{{ $h['upt'] }}</td></tr>
        <tr><td class="k">ATV <span class="muted" style="font-size:8px;">(Rp per bill)</span></td><td class="v">{{ $h['atv'] }}</td></tr>
        <tr><td class="k">APS <span class="muted" style="font-size:8px;">(tamu per bill)</span></td><td class="v">{{ $h['aps'] }}</td></tr>
        <tr><td class="k">Total Bill</td><td class="v">{{ $h['bill'] }}</td></tr>
        <tr><td class="k">Total Traffic</td><td class="v">{{ $h['traffic'] }}</td></tr>
      </table>
      <table width="100%" style="margin-top:8px;"><tr><td class="sec">Jalur Pemesanan &amp; Pos di Luar Omset</td></tr></table>
      <table width="100%" class="rows">
        <tr><td class="k">Self Order</td><td class="v">{{ $h['self'] }} · {{ $h['selfPct'] }}</td></tr>
        <tr><td class="k">Lewat Kasir</td><td class="v">{{ $h['kasir'] }}</td></tr>
        <tr><td class="k">Service Charge</td><td class="v">{{ $h['svc'] }}</td></tr>
        <tr><td class="k">Tax</td><td class="v">{{ $h['tax'] }}</td></tr>
        <tr class="sum"><td class="k">Tax &amp; Service<br><span style="font-size:8px;">di luar omset net</span></td><td class="v">{{ $h['taxSvc'] }}</td></tr>
        <tr><td class="k">Total Discount</td><td class="v">{{ $h['discount'] }}</td></tr>
        <tr><td class="k">Total Compliment</td><td class="v">{{ $h['comp'] }}</td></tr>
      </table>
    </td>
  </tr>
</table>

<table width="100%" class="note" style="margin-top:8px;">
  <tr><td>Omset yang dilaporkan net — sudah dikurangi diskon; tax &amp; service charge di luarnya. Rasio komposisi dihitung terhadap Food + Beverage + Lainnya sebelum diskon, jadi ketiganya selalu berjumlah 100%.</td></tr>
</table>

<table width="100%" class="foot" style="margin-top:11px;">
  <tr>
    <td>Laksamana Muda Coffee &amp; Live Space · Modul KOMPAS</td>
    <td class="r">Dicetak {{ $waktuCetak }} oleh {{ $oleh }}</td>
  </tr>
</table>

</body>
</html>
