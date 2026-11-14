{{--
  tantra: the standard Engine email. What happened (eyebrow + heading), why it matters
  (lead), the facts, the post it is about, and one action. Data from App\Tantra\Mail\Letter.
--}}
@extends('tantra::mail.kit.layout')
@php($k = \App\Tantra\Mail\Kit::class)
@php($toneColor = ['accent' => $k::C['accent'], 'good' => $k::C['good'], 'warn' => $k::C['warn'], 'bad' => $k::C['bad']][$tone ?? 'accent'] ?? $k::C['accent'])
@section('body')
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
@if (! empty($hero))
<tr><td style="padding:0;"><a href="{{ $cta['url'] ?? $k::appUrl() }}"><img src="{{ $hero['src'] }}" width="598" alt="{{ $hero['alt'] }}" style="display:block;width:100%;max-width:598px;height:auto;border:0;border-radius:15px 15px 0 0;"></a></td></tr>
@endif
<tr><td class="tm-pad" style="padding:30px 32px 6px;font-family:{{ $k::FONT }};">
  @if (! empty($eyebrow))
  <div style="font-size:13px;line-height:18px;font-weight:600;color:{{ $toneColor }};letter-spacing:.2px;margin:0 0 10px;">{{ $eyebrow }}</div>
  @endif
  <h1 class="tm-ink tm-h1" style="margin:0 0 12px;font-size:26px;line-height:33px;font-weight:700;letter-spacing:-.3px;color:{{ $k::C['ink'] }};">{{ $heading }}</h1>
  @if (! empty($lead))
  <p class="tm-body" style="margin:0 0 14px;font-size:16px;line-height:25px;color:{{ $k::C['body'] }};">{{ $lead }}</p>
  @endif
  @foreach ($paragraphs ?? [] as $p)
  <p class="tm-body" style="margin:0 0 14px;font-size:16px;line-height:25px;color:{{ $k::C['body'] }};">{!! $p !!}</p>
  @endforeach
</td></tr>
@if (! empty($facts))
<tr><td class="tm-pad" style="padding:4px 32px 14px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tm-sub" style="background:{{ $k::C['sub'] }};border:1px solid {{ $k::C['line'] }};border-radius:12px;">
  @foreach ($facts as $fact)
    <tr>
      <td class="tm-muted {{ $loop->first ? '' : 'tm-line' }}" style="padding:11px 16px;font-family:{{ $k::FONT }};font-size:14px;line-height:20px;color:{{ $k::C['muted'] }};{{ $loop->first ? '' : 'border-top:1px solid '.$k::C['line'].';' }}" width="38%">{{ $fact[0] }}</td>
      <td class="tm-ink {{ $loop->first ? '' : 'tm-line' }}" style="padding:11px 16px;font-family:{{ $k::FONT }};font-size:14px;line-height:20px;color:{{ $k::C['ink'] }};font-weight:600;word-break:break-word;overflow-wrap:anywhere;{{ $loop->first ? '' : 'border-top:1px solid '.$k::C['line'].';' }}">{!! $fact[1] !!}</td>
    </tr>
  @endforeach
  </table>
</td></tr>
@endif
@if (! empty($quote))
<tr><td class="tm-pad" style="padding:4px 32px 14px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
    <td style="border-left:3px solid {{ $toneColor }};padding:4px 0 4px 14px;font-family:{{ $k::FONT }};">
      @if (! empty($quote['by']))<div class="tm-muted" style="font-size:13px;line-height:18px;font-weight:600;color:{{ $k::C['muted'] }};margin-bottom:2px;">{{ $quote['by'] }}</div>@endif
      <div class="tm-ink" style="font-size:16px;line-height:24px;color:{{ $k::C['ink'] }};">{!! nl2br(e($quote['text'])) !!}</div>
    </td>
  </tr></table>
</td></tr>
@endif
@if (! empty($card))
<tr><td class="tm-pad" style="padding:4px 32px 16px;">@include('tantra::mail.kit.post-card', ['card' => $card])</td></tr>
@endif
@if (! empty($list))
<tr><td class="tm-pad" style="padding:0 32px 14px;font-family:{{ $k::FONT }};">
  @foreach ($list as $row)
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
    <td width="24" valign="top" class="tm-accent" style="width:24px;font-size:15px;line-height:24px;font-weight:700;color:{{ $toneColor }};">{{ $loop->iteration }}.</td>
    <td class="tm-body" style="font-size:15px;line-height:24px;color:{{ $k::C['body'] }};padding-bottom:6px;">{!! $row !!}</td>
  </tr></table>
  @endforeach
</td></tr>
@endif
@if (! empty($cta))
<tr><td class="tm-pad" style="padding:8px 32px {{ empty($secondary) ? '30px' : '12px' }};">@include('tantra::mail.kit.button', ['url' => $cta['url'], 'label' => $cta['label']])</td></tr>
@endif
@if (! empty($secondary))
<tr><td class="tm-pad" style="padding:0 32px 28px;font-family:{{ $k::FONT }};font-size:14px;line-height:20px;">
  <a href="{{ $secondary['url'] }}" class="tm-accent" style="color:{{ $k::C['accent'] }};text-decoration:none;font-weight:600;">{{ $secondary['label'] }}</a>
</td></tr>
@endif
@if (! empty($smallprint))
<tr><td class="tm-pad tm-line" style="padding:16px 32px 22px;border-top:1px solid {{ $k::C['line'] }};font-family:{{ $k::FONT }};font-size:13px;line-height:19px;">
  <p class="tm-muted" style="margin:0;color:{{ $k::C['muted'] }};">{!! $smallprint !!}</p>
</td></tr>
@endif
</table>
@endsection
