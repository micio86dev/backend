@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
{{--
    The organization's colour, when it has one (CLAUDE.md ruling 10: the words
    are standard, the chrome is per-tenant).

    INLINE, not a stylesheet rule, because the theme CSS is a static file and
    the colour is per-send. `style` also wins over the class below in every
    client, including the ones that strip <style> blocks entirely.

    The borders are not decoration: this template builds the button's padding
    out of borders rather than padding, because Outlook ignores padding on an
    anchor. Recolouring the background and leaving the borders purple would
    draw a purple frame around a tenant-coloured button.

    The TEXT colour is set here too, and it has to be: the layout injects
    `a { color: <brand> }` per send, which outranks the theme's
    `.button { color: #fff }` once inlined, so without it the label took the
    brand colour and vanished into its own background. Black or white by WCAG
    contrast, the rule the backoffice already uses.

    Emits NOTHING when no colour is configured, so the stylesheet's own value
    stands — the brand constant lives in one place.
--}}
@php($branding = app(App\Support\Mail\EmailBranding::class))
@php($brandColor = $branding->primaryColor())
<a href="{{ $url }}" class="button button-{{ $color }}"
   @if ($brandColor !== null && $color === 'primary')
   style="background-color: {{ $brandColor }}; border-color: {{ $brandColor }}; color: {{ $branding->foregroundColor() }};"
   @endif
   target="_blank" rel="noopener">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
