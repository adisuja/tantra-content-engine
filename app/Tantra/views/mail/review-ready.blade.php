{{-- tantra: review emails (ReviewReady). Shared layout and post cards from mail/kit. --}}
@extends('tantra::mail.kit.layout')
@php($k = \App\Tantra\Mail\Kit::class)
@section('body')
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td class="tm-pad" style="padding:30px 32px 8px;font-family:{{ $k::FONT }};">
  <div style="font-size:13px;line-height:18px;font-weight:600;color:{{ $k::C['accent'] }};margin:0 0 10px;" class="tm-accent">Ready for review</div>
  <h1 class="tm-ink tm-h1" style="margin:0 0 12px;font-size:26px;line-height:33px;font-weight:700;letter-spacing:-.3px;color:{{ $k::C['ink'] }};">{{ $heading }}</h1>
  <p class="tm-body" style="margin:0 0 18px;font-size:16px;line-height:25px;color:{{ $k::C['body'] }};">{{ $lead }}</p>
  @include('tantra::mail.kit.button', ['url' => $url, 'label' => $button])
</td></tr>
@foreach ($cards as $card)
<tr><td class="tm-pad" style="padding:{{ $loop->first ? '22px' : '0' }} 32px 14px;">@include('tantra::mail.kit.post-card', ['card' => $card])</td></tr>
@endforeach
@foreach ($legacy as $post)
<tr><td class="tm-pad tm-line" style="padding:12px 32px;border-top:1px solid {{ $k::C['line'] }};font-family:{{ $k::FONT }};font-size:15px;line-height:22px;">
  <span class="tm-ink" style="color:{{ $k::C['ink'] }};font-weight:600;">{{ $post['id'] }}</span>@if (! empty($post['date']))<span class="tm-muted" style="color:{{ $k::C['muted'] }};"> &middot; {{ \Illuminate\Support\Carbon::parse($post['date'])->format('D j M') }}</span>@endif
  @if (! empty($post['revised']))<span class="tm-chip" style="display:inline-block;margin-left:6px;padding:2px 8px;border-radius:999px;background:{{ $k::C['chip'] }};color:{{ $k::C['accent'] }};font-size:12px;font-weight:600;">New version</span>@endif
  @if (! empty($post['short']))<div class="tm-body" style="color:{{ $k::C['body'] }};">{{ $post['short'] }}</div>@endif
  @foreach ($post['numbers'] ?? [] as $number)<div class="tm-body" style="color:{{ $k::C['body'] }};">&bull; {{ $number }}</div>@endforeach
</td></tr>
@endforeach
@if ($more > 0)
<tr><td class="tm-pad" style="padding:0 32px 12px;font-family:{{ $k::FONT }};font-size:15px;line-height:22px;">
  <a href="{{ $url }}" class="tm-accent" style="color:{{ $k::C['accent'] }};font-weight:600;text-decoration:none;">And {{ $more }} more {{ $more === 1 ? 'post' : 'posts' }} in the review studio &rarr;</a>
</td></tr>
@endif
<tr><td class="tm-pad" style="padding:10px 32px 30px;">@include('tantra::mail.kit.button', ['url' => $url, 'label' => $button])</td></tr>
</table>
@endsection
