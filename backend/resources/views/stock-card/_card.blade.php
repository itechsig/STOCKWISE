@php
    /** @var array $c  StockCardService::present() — one card */
    $nf = fn ($n) => $n == 0 ? '' : rtrim(rtrim(number_format((float) $n, 4, ',', '.'), '0'), ',');
    $nfAll = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, ',', '.'), '0'), ',') ?: '0';
    $loc = trim(implode(' / ', array_filter([$c['location_warehouse'], $c['location_rack']])));
    $blank = max(0, 18 - count($c['rows'])); // empty lines like the paper card
@endphp
<section class="card">
  <div class="top">
    <div>@if (! empty($logo))<img src="{{ $logo }}" alt="Logo">@endif</div>
    <div>
      <h1>KARTU STOCK</h1>
      @if ($c['project'])<div class="proj">Proyek: <b>{{ $c['project']['name'] }}</b>@if ($c['project']['code']) ({{ $c['project']['code'] }})@endif</div>@endif
    </div>
    <div></div>
  </div>
  <div class="head">
    <div>
      <div class="f"><span>Nama Barang</span><b>: {{ $c['item']['description'] }}</b></div>
      <div class="f"><span>Nomor Kartu</span><b>: {{ $c['card_number'] }}</b></div>
      <div class="f"><span>Satuan</span><b>: {{ $c['item']['unit'] ?? '' }}</b></div>
    </div>
    <div>
      <div class="f"><span>Kode Barang</span><b>: {{ $c['item']['code'] }}</b></div>
      <div class="f"><span>Letak Gudang / Rak</span><b>: {{ $loc }}</b></div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:17mm">TGL</th>
        <th style="width:26mm">NO. BUKTI</th>
        <th>KETERANGAN</th>
        <th style="width:15mm">MASUK</th>
        <th style="width:15mm">KELUAR</th>
        <th style="width:15mm">SISA</th>
        <th style="width:24mm">LAMPIRAN</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($c['rows'] as $r)
        <tr>
          <td class="d">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d/m/Y') }}</td>
          <td>{{ $r['doc_no'] }}</td>
          <td>{{ $r['description'] }}</td>
          <td class="n">{{ $nf($r['masuk']) }}</td>
          <td class="n">{{ $nf($r['keluar']) }}</td>
          <td class="n">{{ $nfAll($r['sisa']) }}</td>
          <td>{{ count($r['files']) ? count($r['files']).' lampiran' : '' }}</td>
        </tr>
      @endforeach
      @for ($i = 0; $i < $blank; $i++)
        <tr class="blank"><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
      @endfor
    </tbody>
    <tfoot style="display: table-row-group">
      <tr>
        <td colspan="3">JUMLAH</td>
        <td class="n">{{ $nfAll($c['total_masuk']) }}</td>
        <td class="n">{{ $nfAll($c['total_keluar']) }}</td>
        <td class="n">{{ $nfAll($c['sisa']) }}</td>
        <td></td>
      </tr>
    </tfoot>
  </table>

  <div class="sign">
    <div>Penanggung Jawab<div class="line"></div>{{ $c['person_in_charge'] ?: '( ................................ )' }}</div>
    <div>Direktur<div class="line"></div>{{ $c['director_name'] ?: '( ................................ )' }}</div>
  </div>
  <div class="foot">Dicetak dari STOCKWISE {{ now('Asia/Jakarta')->format('d/m/Y H:i') }}</div>
</section>
