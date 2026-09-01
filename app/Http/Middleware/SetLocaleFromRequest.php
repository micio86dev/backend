<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answer in the language the caller is reading in.
 *
 * EXTRACTED, NOT INVENTED. This is `FrameworkController::resolveLocale()`,
 * which every framework-catalogue endpoint called by hand. It worked, and it
 * reached exactly three endpoints — so the backoffice could be in Italian and
 * an evaluation report would still come back in English, because the surface
 * that serves it had never heard of the mechanism.
 *
 * The general rule is that every operator-facing response is localized, and a
 * rule enforced by remembering to call a private method is not a rule. As
 * middleware it applies to a whole route group and a new endpoint inherits it
 * by existing.
 *
 * ORDER OF PRECEDENCE, unchanged from the original:
 *
 *   1. `?locale=` — explicit, and validated against `supported_locales`. An
 *      unsupported value is a 422 rather than a silent fallback: someone who
 *      names a locale is making a claim about what they can read, and quietly
 *      serving them something else is worse than telling them.
 *   2. `Accept-Language` — advisory. Sent by every browser without anybody
 *      choosing it, so an unsupported value degrades to the fallback instead
 *      of failing a request the caller did not know they were making.
 *   3. `fallback_locale`.
 *
 * WHAT THIS DOES NOT TOUCH. Machine-readable values are not user-facing and
 * stay literal in every locale: status payloads, enum values, DB column and
 * API field names, log keys, header values (CLAUDE.md). And it does not
 * re-language stored EVIDENCE — an LLM's explanation of what a candidate said
 * is a record of an assessment conducted in one language, not UI copy.
 */
final class SetLocaleFromRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supportedLocales */
        $supportedLocales = config('app.supported_locales', ['en']);

        $queryLocale = $request->query('locale');

        if ($queryLocale !== null) {
            Validator::make(
                ['locale' => $queryLocale],
                ['locale' => ['required', 'string', Rule::in($supportedLocales)]],
            )->validate();

            App::setLocale((string) $queryLocale);

            return $next($request);
        }

        $acceptLanguage = (string) $request->header('Accept-Language', '');

        if ($acceptLanguage !== '') {
            // First language tag only: "it-IT,it;q=0.9,en;q=0.8" → "it".
            // Quality values are deliberately not honoured — a full
            // negotiation would pick a locale the product may not have
            // authored anchors for, and the supported set is two.
            $primaryTag = strtolower(explode(',', $acceptLanguage)[0]);
            $primaryLang = explode('-', explode(';', $primaryTag)[0])[0];

            if (in_array($primaryLang, $supportedLocales, true)) {
                App::setLocale($primaryLang);

                return $next($request);
            }
        }

        App::setLocale((string) config('app.fallback_locale', 'en'));

        return $next($request);
    }
}
