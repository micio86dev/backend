<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
{{--
    The tenant colour, for the TEXT.

    The button took it already; links and the header wordmark did not, so a
    message showed a correctly-branded call to action sitting beside Quint
    purple everywhere else. Both hardcode `#771AAF` in the theme stylesheet,
    which is a static file and cannot know which tenant is being written to.

    NOT inside a media query, and that is load-bearing: Laravel's CSS inliner
    skips media-query rules (which is exactly why the mobile overrides below
    need `!important`). A plain rule here is inlined onto the anchors the same
    way the theme's own rules are, so it reaches clients that strip <style>
    blocks entirely.

    Emits NOTHING when no colour is configured — the stylesheet's own value
    then stands, and the brand constant keeps living in exactly one place.
--}}
@php($brandColor = app(App\Support\Mail\EmailBranding::class)->primaryColor())
@if ($brandColor !== null)
<style>
a, .header a, .inner-body a, .subcopy a {
color: {{ $brandColor }};
}
</style>
@endif
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
