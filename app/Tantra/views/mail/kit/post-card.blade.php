{{--
  tantra: one post, as a reviewer would see it in the feed. $card from App\Tantra\Mail\PostCard.
  Media: image (the image), carousel (cover large, a strip of the next slides, the count),
  gif (the animated GIF; Outlook desktop shows its first frame), video (a poster with a play
  button that opens the post in the app: email cannot play video).
--}}
@php($k = \App\Tantra\Mail\Kit::class)
@php($m = $card['media'] ?? ['kind' => 'none'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tm-sub" style="background:{{ $k::C['sub'] }};border:1px solid {{ $k::C['line'] }};border-radius:14px;">
<tr><td style="padding:16px 16px 12px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
    <td width="44" valign="middle" style="width:44px;">
      @if (! empty($card['avatar']))
        <img src="{{ $card['avatar'] }}" width="36" height="36" alt="" style="display:block;width:36px;height:36px;border-radius:18px;border:0;">
      @else
        <img src="{{ $card['icon'] }}" width="36" height="36" alt="" style="display:block;width:36px;height:36px;border-radius:9px;border:0;">
      @endif
    </td>
    <td valign="middle" style="font-family:{{ $k::FONT }};">
      <div class="tm-ink" style="font-size:15px;line-height:20px;font-weight:600;color:{{ $k::C['ink'] }};">{{ $card['account'] }}</div>
      <div class="tm-muted" style="font-size:13px;line-height:18px;color:{{ $k::C['muted'] }};"><img src="{{ $card['icon'] }}" width="13" height="13" alt="" style="width:13px;height:13px;vertical-align:-2px;border:0;border-radius:3px;">&nbsp;{{ $card['network_label'] }}@if (! empty($card['when'])) &middot; {{ $card['when'] }}@endif</div>
    </td>
    @if (! empty($card['badge']))
    <td align="right" valign="middle" style="font-family:{{ $k::FONT }};white-space:nowrap;">
      <span class="tm-chip" style="display:inline-block;padding:4px 9px;border-radius:999px;background:{{ $k::C['chip'] }};color:{{ $k::C['accent'] }};font-size:12px;line-height:16px;font-weight:600;">{{ $card['badge'] }}</span>
    </td>
    @endif
  </tr></table>
</td></tr>
@if (filled($card['text'] ?? null))
<tr><td class="tm-body" style="padding:0 16px 12px;font-family:{{ $k::FONT }};font-size:15px;line-height:23px;color:{{ $k::C['body'] }};">
  {!! nl2br(e($card['text'])) !!}@if (! empty($card['more'])) <a href="{{ $card['url'] }}" class="tm-accent" style="color:{{ $k::C['accent'] }};font-weight:600;text-decoration:none;white-space:nowrap;">Read more</a>@endif
</td></tr>
@endif
@if (($m['kind'] ?? 'none') !== 'none' && ! empty($m['cover']))
<tr><td style="padding:0 16px 12px;">
  <a href="{{ $card['url'] }}" style="display:block;text-decoration:none;position:relative;">
  @if ($m['kind'] === 'video')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
      <td background="{{ $m['cover'] }}" bgcolor="#12131a" valign="middle" align="center" height="{{ $m['h'] }}" style="background:#12131a url('{{ $m['cover'] }}') center / cover no-repeat;border-radius:10px;height:{{ $m['h'] }}px;">
        <!--[if gte mso 9]><v:image xmlns:v="urn:schemas-microsoft-com:vml" src="{{ $m['cover'] }}" style="width:{{ $m['w'] }}px;height:{{ $m['h'] }}px;" /><v:rect xmlns:v="urn:schemas-microsoft-com:vml" fill="false" stroke="false" style="position:absolute;width:{{ $m['w'] }}px;height:{{ $m['h'] }}px;"><v:textbox inset="0,0,0,0"><![endif]-->
        <img src="{{ $k::asset('tantra/email/play.png') }}" width="64" height="64" alt="Play video in Tantra" style="display:inline-block;width:64px;height:64px;border:0;">
        <!--[if gte mso 9]></v:textbox></v:rect><![endif]-->
      </td>
    </tr></table>
  @else
    <img src="{{ $m['cover'] }}" width="{{ $m['w'] }}" alt="{{ $m['alt'] ?? 'Post image' }}" style="display:block;width:100%;max-width:{{ $m['w'] }}px;height:auto;border:0;border-radius:10px;">
  @endif
  </a>
  @if ($m['kind'] === 'carousel' && ! empty($m['strip']))
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="tm-strip" style="margin-top:8px;"><tr>
    @foreach ($m['strip'] as $thumb)
      <td style="padding-right:6px;" @if ($loop->iteration > 3) class="tm-hide-sm" @endif><a href="{{ $card['url'] }}"><img src="{{ $thumb }}" width="64" height="64" alt="Slide {{ $loop->iteration + 1 }}" style="display:block;width:64px;height:64px;border:0;border-radius:6px;object-fit:cover;"></a></td>
    @endforeach
    <td class="tm-muted" style="font-family:{{ $k::FONT }};font-size:13px;line-height:18px;color:{{ $k::C['muted'] }};padding-left:4px;">{{ $m['count'] }}&nbsp;slides</td>
  </tr></table>
  @elseif ($m['kind'] === 'video')
  <div class="tm-muted" style="font-family:{{ $k::FONT }};font-size:13px;line-height:18px;color:{{ $k::C['muted'] }};padding-top:6px;">Video. Opens in Tantra to play.</div>
  @elseif ($m['kind'] === 'gif')
  <div class="tm-muted" style="font-family:{{ $k::FONT }};font-size:13px;line-height:18px;color:{{ $k::C['muted'] }};padding-top:6px;">Animated image</div>
  @endif
</td></tr>
@endif
@if (filled($card['first_comment'] ?? null))
<tr><td style="padding:0 16px 12px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
    <td class="tm-line" style="border-left:3px solid {{ $k::C['line'] }};padding:2px 0 2px 12px;font-family:{{ $k::FONT }};">
      <div class="tm-muted" style="font-size:12px;line-height:16px;font-weight:600;color:{{ $k::C['muted'] }};text-transform:uppercase;letter-spacing:.4px;">First comment</div>
      <div class="tm-body" style="font-size:14px;line-height:21px;color:{{ $k::C['body'] }};">{!! nl2br(e($card['first_comment'])) !!}</div>
    </td>
  </tr></table>
</td></tr>
@endif
<tr><td class="tm-line" style="padding:12px 16px 14px;border-top:1px solid {{ $k::C['line'] }};font-family:{{ $k::FONT }};font-size:14px;line-height:20px;">
  <a href="{{ $card['url'] }}" class="tm-accent" style="color:{{ $k::C['accent'] }};font-weight:600;text-decoration:none;">{{ $card['link_label'] ?? 'Review this post' }} &rarr;</a>
</td></tr>
</table>
