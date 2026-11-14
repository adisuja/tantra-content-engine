{{-- tantra: the daily digest (App\Tantra\Digest\DailyDigest). --}}
@extends('tantra::mail.kit.layout')
@php($k = \App\Tantra\Mail\Kit::class)
@section('body')
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td class="tm-pad" style="padding:28px 32px 6px;font-family:{{ $k::FONT }};">
  <div class="tm-muted" style="font-size:13px;line-height:18px;font-weight:600;color:{{ $k::C['muted'] }};margin:0 0 8px;">{{ $s['date'] }}</div>
  <h1 class="tm-ink tm-h1" style="margin:0 0 6px;font-size:26px;line-height:33px;font-weight:700;letter-spacing:-.3px;color:{{ $k::C['ink'] }};">
    @if ($s['waiting'] > 0)
      {{ $firstName }}, {{ $s['waiting'] === 1 ? 'one post waits' : $s['waiting'].' posts wait' }} for you
    @else
      {{ $firstName }}, here is your day
    @endif
  </h1>
  <p class="tm-body" style="margin:0 0 18px;font-size:16px;line-height:25px;color:{{ $k::C['body'] }};">
    @if ($s['waiting'] > 0)
      Nothing goes out until you approve it, so the week's plan is in your hands.
    @else
      Nothing needs you today. Here is how your content is doing.
    @endif
  </p>
</td></tr>

@if ($s['standout'] !== null)
<tr><td class="tm-pad" style="padding:0 32px 18px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tm-hero" style="background:{{ $k::C['hero'] }};border-radius:14px;">
  <tr><td style="padding:22px 22px 20px;font-family:{{ $k::FONT }};">
    <div class="tm-hero-num" style="font-size:48px;line-height:52px;font-weight:800;letter-spacing:-1px;color:#ffffff;">{{ $s['standout']['value'] }}</div>
    <div style="font-size:17px;line-height:24px;font-weight:600;color:#ffffff;margin-top:4px;">{{ $s['standout']['label'] }}</div>
    <div title="{{ $s['standout']['math'] }}" style="font-size:12px;line-height:17px;color:#b8bccb;margin-top:10px;">How we count: {{ $s['standout']['math'] }}</div>
  </td></tr>
  </table>
</td></tr>
@endif

<tr><td class="tm-pad" style="padding:0 32px 8px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
  @foreach ([['Went live', $s['published_24h'], 'last 24 hours'], ['Published', $s['published_all'], 'all time'], ['Scheduled', $s['scheduled_7d'], 'next 7 days']] as [$label, $value, $hint])
    <td class="tm-stack tm-stack-gap" width="33%" valign="top" style="padding:0 {{ $loop->last ? '0' : '8px' }} 0 0;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tm-sub" style="background:{{ $k::C['sub'] }};border:1px solid {{ $k::C['line'] }};border-radius:12px;">
      <tr><td style="padding:14px 14px 12px;font-family:{{ $k::FONT }};">
        <div class="tm-muted" style="font-size:12px;line-height:16px;font-weight:600;color:{{ $k::C['muted'] }};">{{ $label }}</div>
        <div class="tm-ink" style="font-size:26px;line-height:32px;font-weight:700;color:{{ $k::C['ink'] }};">{{ number_format($value) }}</div>
        <div class="tm-muted" style="font-size:12px;line-height:16px;color:{{ $k::C['muted'] }};">{{ $hint }}</div>
      </td></tr>
      </table>
    </td>
  @endforeach
  </tr></table>
</td></tr>

@if ($s['views'] > 0)
<tr><td class="tm-pad" style="padding:14px 32px 6px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tm-sub" style="background:{{ $k::C['sub'] }};border:1px solid {{ $k::C['line'] }};border-radius:12px;">
  <tr><td style="padding:16px 16px 6px;font-family:{{ $k::FONT }};">
    <div class="tm-muted" style="font-size:12px;line-height:16px;font-weight:600;color:{{ $k::C['muted'] }};">Views across every channel</div>
    <div><span class="tm-ink" style="font-size:30px;line-height:38px;font-weight:700;color:{{ $k::C['ink'] }};">{{ $k::compact($s['views']) }}</span>
      @if ($s['views_delta'] > 0)<span style="font-size:14px;line-height:20px;font-weight:600;color:{{ $k::C['good'] }};">&nbsp;+{{ $k::compact($s['views_delta']) }} since yesterday</span>@endif
    </div>
  </td></tr>
  @foreach ($networks as $n)
  <tr><td style="padding:6px 16px;font-family:{{ $k::FONT }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
      <td width="22" valign="middle"><img src="{{ $n['icon'] }}" width="16" height="16" alt="" style="display:block;width:16px;height:16px;border:0;border-radius:4px;"></td>
      <td width="96" valign="middle" class="tm-body" style="font-size:13px;line-height:18px;color:{{ $k::C['body'] }};">{{ $n['label'] }}</td>
      <td valign="middle"><table role="presentation" width="{{ $n['pct'] }}%" cellpadding="0" cellspacing="0" border="0"><tr><td height="8" style="height:8px;line-height:8px;font-size:0;background:{{ $k::C['accent'] }};border-radius:4px;">&nbsp;</td></tr></table></td>
      <td width="56" align="right" valign="middle" class="tm-ink" style="font-size:13px;line-height:18px;font-weight:600;color:{{ $k::C['ink'] }};">{{ $n['views'] }}</td>
    </tr></table>
  </td></tr>
  @endforeach
  <tr><td class="tm-muted" style="padding:6px 16px 14px;font-family:{{ $k::FONT }};font-size:12px;line-height:17px;color:{{ $k::C['muted'] }};">Each post's latest views, or impressions where the network counts those instead, summed for every channel your team connected.</td></tr>
  </table>
</td></tr>
@endif

@if ($cards !== [])
<tr><td class="tm-pad" style="padding:22px 32px 10px;font-family:{{ $k::FONT }};">
  <div class="tm-ink" style="font-size:18px;line-height:24px;font-weight:700;color:{{ $k::C['ink'] }};">Waiting for your review</div>
</td></tr>
@foreach ($cards as $card)
<tr><td class="tm-pad" style="padding:0 32px 14px;">@include('tantra::mail.kit.post-card', ['card' => $card])</td></tr>
@endforeach
@if ($more > 0)
<tr><td class="tm-pad" style="padding:0 32px 8px;font-family:{{ $k::FONT }};font-size:15px;line-height:22px;">
  <a href="{{ $reviewUrl }}" class="tm-accent" style="color:{{ $k::C['accent'] }};font-weight:600;text-decoration:none;">And {{ $more }} more waiting &rarr;</a>
</td></tr>
@endif
<tr><td class="tm-pad" style="padding:8px 32px 30px;">@include('tantra::mail.kit.button', ['url' => $reviewUrl, 'label' => $s['waiting'] === 1 ? 'Review the post' : 'Review all '.$s['waiting']])</td></tr>
@else
<tr><td class="tm-pad" style="padding:18px 32px 30px;">@include('tantra::mail.kit.button', ['url' => $insightsUrl, 'label' => 'See what is working'])</td></tr>
@endif
</table>
@endsection
