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
@php($signedBy = app(\App\Support\Mail\EmailBranding::class)->organizationName() ?? config('app.name'))
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ $signedBy }}
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
