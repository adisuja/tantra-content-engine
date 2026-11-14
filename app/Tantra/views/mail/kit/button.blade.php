{{-- tantra: bulletproof button. VML for Outlook desktop, a padded link everywhere else. $url, $label, $variant (primary|secondary), $width --}}
@php($k = \App\Tantra\Mail\Kit::class)
@php($primary = ($variant ?? 'primary') === 'primary')
@php($w = $width ?? 220)
<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="tm-btn" style="margin:0;">
<tr><td align="center" style="border-radius:10px;background:{{ $primary ? $k::C['accent'] : $k::C['card'] }};{{ $primary ? '' : 'border:1px solid '.$k::C['line'].';' }}" @unless($primary) class="tm-card" @endunless>
<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:46px;v-text-anchor:middle;width:{{ $w }}px;" arcsize="22%" strokecolor="{{ $primary ? $k::C['accent'] : $k::C['line'] }}" fillcolor="{{ $primary ? $k::C['accent'] : '#ffffff' }}"><w:anchorlock/><center style="color:{{ $primary ? '#ffffff' : $k::C['ink'] }};font-family:'Segoe UI',Arial,sans-serif;font-size:15px;font-weight:600;">{{ $label }}</center></v:roundrect><![endif]-->
<!--[if !mso]><!-- --><a href="{{ $url }}" target="_blank" @unless($primary) class="tm-ink" @endunless style="display:inline-block;padding:13px 22px;font-family:{{ $k::FONT }};font-size:15px;line-height:20px;font-weight:600;color:{{ $primary ? '#ffffff' : $k::C['ink'] }};text-decoration:none;border-radius:10px;mso-hide:all;">{{ $label }}</a><!--<![endif]-->
</td></tr>
</table>
