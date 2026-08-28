{{--
    Quint-themed mail header (self-service-password-reset AD-6).

    The published default hotlinked https://laravel.com/img/notification-logo-v2.1.png
    whenever the app name was "Laravel". That is removed outright and not
    replaced with our own remote logo: every major client blocks remote images
    by default, so the header would read as a broken-image icon on a
    security-sensitive message, and a remote fetch leaks an open-tracking signal
    to whoever serves it. A wordmark in text always renders, in every client,
    with images off.
--}}
@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{!! $slot !!}
</a>
</td>
</tr>
