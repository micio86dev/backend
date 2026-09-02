{{--
    Quint-themed mail header (self-service-password-reset AD-6).

    The published default hotlinked https://laravel.com/img/notification-logo-v2.1.png
    whenever the app name was "Laravel". That is gone, and what replaced it is
    the ORGANIZATION'S logo when one is configured (product decision reversed
    2026-09-02) — never a hardcoded product image.

    The reasoning that once refused any logo here still stands where it was
    right: a client with images off must not leave the header blank. So the
    caller renders the tenant NAME as the image's `alt`, and falls back to the
    same name as text when no logo exists. Both states name whoever invited the
    candidate, which is what a security-sensitive message has to do.
--}}
@props(['url'])
{{--
    INLINE, like the button, and for the same reason: the theme's `.header a`
    rule is a static file that cannot know the tenant, and a declaration in the
    element's own style attribute is applied last by the CSS inliner — so it
    wins even against the theme's more specific selector, and it survives
    clients that strip <style> blocks entirely.

    Emits nothing when no colour is configured, leaving the stylesheet's own
    value in place.
--}}
@php($headerBrandColor = app(App\Support\Mail\EmailBranding::class)->primaryColor())
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;@if ($headerBrandColor !== null) color: {{ $headerBrandColor }};@endif">
{!! $slot !!}
</a>
</td>
</tr>
