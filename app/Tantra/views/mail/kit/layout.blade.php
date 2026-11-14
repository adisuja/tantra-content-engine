{{--
  tantra: the one layout every Engine email uses (Gmail, Apple Mail, Outlook).
  Tables and inline styles for the body; the <style> block only adds the dark
  palette, the mobile padding and the Outlook.com dark hooks. The logo is an
  opaque white pill, so no client's dark mode can turn it invisible.
  Variables: $preheader, $title, $footer (array: reason, settings_url, unsubscribe_url, unsubscribe_label)
--}}
@php($k = \App\Tantra\Mail\Kit::class)
@php($footer = $footer ?? [])
<!doctype html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no,date=no,address=no,email=no,url=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $title ?? 'Tantra' }}</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><style>td,th,div,p,a,h1,h2,h3,span{font-family:'Segoe UI',Arial,sans-serif !important;}</style><![endif]-->
<style>
:root{color-scheme:light dark;supported-color-schemes:light dark;}
body{margin:0;padding:0;width:100%;-webkit-text-size-adjust:100%;}
a{color:{{ $k::C['accent'] }};}
.tm-btn a:hover{opacity:.92;}
@media (max-width:480px){
  .tm-shell{width:100% !important;}
  .tm-pad{padding-left:20px !important;padding-right:20px !important;}
  .tm-stack{display:block !important;width:100% !important;box-sizing:border-box;}
  .tm-stack-gap{padding:0 0 10px 0 !important;}
  .tm-h1{font-size:24px !important;line-height:30px !important;}
  .tm-hero-num{font-size:40px !important;line-height:44px !important;}
  .tm-strip img{width:52px !important;height:52px !important;}
  .tm-hide-sm{display:none !important;}
}
@media (prefers-color-scheme:dark){
  .tm-canvas{background:{{ $k::D['canvas'] }} !important;}
  .tm-card{background:{{ $k::D['card'] }} !important;border-color:{{ $k::D['line'] }} !important;}
  .tm-sub{background:{{ $k::D['sub'] }} !important;border-color:{{ $k::D['line'] }} !important;}
  .tm-ink{color:{{ $k::D['ink'] }} !important;}
  .tm-body{color:{{ $k::D['body'] }} !important;}
  .tm-muted{color:{{ $k::D['muted'] }} !important;}
  .tm-line{border-color:{{ $k::D['line'] }} !important;}
  .tm-accent{color:{{ $k::D['accent'] }} !important;}
  .tm-chip{background:{{ $k::D['chip'] }} !important;color:{{ $k::D['accent'] }} !important;}
  .tm-hero{background:{{ $k::D['hero'] }} !important;}
}
[data-ogsc] .tm-canvas{background:{{ $k::D['canvas'] }} !important;}
[data-ogsc] .tm-card{background:{{ $k::D['card'] }} !important;}
[data-ogsc] .tm-ink{color:{{ $k::D['ink'] }} !important;}
[data-ogsc] .tm-body{color:{{ $k::D['body'] }} !important;}
[data-ogsc] .tm-muted{color:{{ $k::D['muted'] }} !important;}
</style>
</head>
<body class="tm-canvas" style="margin:0;padding:0;background:{{ $k::C['canvas'] }};">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:{{ $k::C['canvas'] }};">{{ $preheader ?? '' }}@for ($i = 0; $i < 90; $i++)&#8199;&#65279;&#847; @endfor</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tm-canvas" style="background:{{ $k::C['canvas'] }};">
<tr><td align="center" style="padding:28px 12px 36px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="tm-shell" style="width:600px;max-width:600px;">
<tr><td style="padding:0 4px 18px;">
<a href="{{ $k::appUrl() }}" style="text-decoration:none;"><img src="{{ $k::asset('tantra/email/logo.png') }}" width="154" height="40" alt="Tantra" style="display:block;border:0;width:154px;height:40px;"></a>
</td></tr>
<tr><td class="tm-card" style="background:{{ $k::C['card'] }};border:1px solid {{ $k::C['line'] }};border-radius:16px;">
@yield('body')
</td></tr>
<tr><td class="tm-pad" style="padding:22px 28px 0;font-family:{{ $k::FONT }};font-size:12px;line-height:18px;">
@isset($footer['reason'])<p class="tm-muted" style="margin:0 0 8px;color:{{ $k::C['muted'] }};">{{ $footer['reason'] }}</p>@endisset
<p class="tm-muted" style="margin:0;color:{{ $k::C['muted'] }};">
<a href="{{ $footer['settings_url'] ?? $k::settingsUrl() }}" class="tm-muted" style="color:{{ $k::C['muted'] }};text-decoration:underline;">Email settings</a>
@isset($footer['unsubscribe_url'])&nbsp;&middot;&nbsp;<a href="{{ $footer['unsubscribe_url'] }}" class="tm-muted" style="color:{{ $k::C['muted'] }};text-decoration:underline;">{{ $footer['unsubscribe_label'] ?? 'Unsubscribe' }}</a>@endisset
&nbsp;&middot;&nbsp;<a href="https://usetantra.com" class="tm-muted" style="color:{{ $k::C['muted'] }};text-decoration:underline;">usetantra.com</a>
</p>
<p class="tm-muted" style="margin:8px 0 0;color:{{ $k::C['muted'] }};">Tantra Content Engine &middot; GrowthClub</p>
</td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
