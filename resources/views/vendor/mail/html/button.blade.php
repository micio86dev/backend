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

    Emits NOTHING when no colour is configured, so the stylesheet's own value
    stands — the brand constant lives in one place.
--}}
@php($brandColor = app(App\Support\Mail\EmailBranding::class)->primaryColor())
<a href="{{ $url }}" class="button button-{{ $color }}"
   @if ($brandColor !== null && $color === 'primary')
   style="background-color: {{ $brandColor }}; border-color: {{ $brandColor }};"
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
