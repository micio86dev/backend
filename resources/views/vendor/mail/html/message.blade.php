{{--
    The name this message is signed with, in the wordmark AND the copyright.

    Per-tenant, from EmailBranding, falling back to the product's own name when
    there is no organization to sign with (a superadmin has none). Both lines
    used to render `config('app.name')` directly, so every message went out
    signed "Laravel".

    Ruling 10 keeps the WORDS of a transactional email standard and out of
    tenant reach; the name on the header is CHROME, the same category as the
    colour this template already takes from the same object. `{{ }}` escapes
    it — an organization name is operator-supplied.
--}}
@php($branding = app(\App\Support\Mail\EmailBranding::class))
@php($signedBy = $branding->organizationName() ?? config('app.name'))
@php($logoUrl = $branding->logoUrl())
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
@if ($logoUrl)
{{-- `alt` is the ORGANIZATION'S NAME, never "logo" or empty. A client that
     still blocks remote images then shows who invited the candidate in words
     rather than an empty rectangle — which is the concern the original
     no-logo ruling was protecting, answered rather than dropped.
     Dimensions inline and in attributes: Outlook ignores CSS height on an
     image, and a logo with no intrinsic box reflows the header while it
     loads. --}}
<img src="{{ $logoUrl }}" alt="{{ $signedBy }}" height="40" style="height: 40px; max-height: 40px; width: auto; border: 0; line-height: 100%; outline: none; text-decoration: none;">
@else
{{ $signedBy }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $signedBy }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
