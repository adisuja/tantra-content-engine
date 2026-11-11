{{-- tantra: review emails. Plain HTML with inline styles so every mail client shows it the same. --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f2ee;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#1d1d1f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f2ee;">
<tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;">
<tr><td style="padding:28px 28px 8px;">
<div style="font-size:13px;font-weight:600;color:#6b6b6b;letter-spacing:.3px;">{{ config('app.name') }}</div>
<h1 style="margin:10px 0 8px;font-size:22px;line-height:1.3;">{{ $heading }}</h1>
<p style="margin:0 0 18px;font-size:15px;line-height:1.5;color:#3d3d3d;">{{ $intro }}</p>
</td></tr>
<tr><td style="padding:0 28px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
@foreach ($posts as $post)
<tr><td style="padding:10px 0;border-top:1px solid #ecebe7;font-size:14px;line-height:1.45;">
<b>{{ $post['id'] }}</b>@if (! empty($post['date'])) <span style="color:#6b6b6b;">· {{ \Illuminate\Support\Carbon::parse($post['date'])->format('D j M') }}</span>@endif
@if (! empty($post['revised'])) <span style="color:#a15c00;">· new version</span>@endif
@if (! empty($post['short']))<div style="color:#3d3d3d;">{{ $post['short'] }}</div>@endif
@foreach ($post['numbers'] ?? [] as $number)<div style="color:#3d3d3d;">· {{ $number }}</div>@endforeach
</td></tr>
@endforeach
</table>
</td></tr>
<tr><td style="padding:22px 28px 30px;">
<a href="{{ $url }}" style="display:inline-block;background:#141414;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 22px;border-radius:999px;">{{ $button }}</a>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
