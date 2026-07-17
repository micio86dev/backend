<?php

/**
 * Spatie Laravel Translatable — explicit fallback configuration (C3).
 *
 * spatie/laravel-translatable ^6.x does not publish a config file via
 * vendor:publish. This file is created manually and wired in AppServiceProvider
 * via `app('translatable')->fallback(fallbackLocale: 'en', fallbackAny: true)`.
 *
 * These values are read by AppServiceProvider::boot() and applied to the
 * Translatable singleton so that HasTranslations accessors on Role, Competency,
 * and BarsIndicator fall back to EN explicitly — not implicitly via app.fallback_locale.
 *
 * fallback_locale: the locale returned when the requested locale is absent.
 * fallback_any:    if fallback_locale is also absent, use the first available locale.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Fallback Locale
    |--------------------------------------------------------------------------
    |
    | When a translation is missing for the requested locale, this locale is
    | used as fallback. Aligns with app.fallback_locale but is set explicitly
    | on the Translatable singleton so behaviour is traceable and testable.
    |
    */
    'fallback_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Fallback Any
    |--------------------------------------------------------------------------
    |
    | If the fallback_locale translation is also absent, return the first
    | available locale's translation instead of an empty string.
    |
    */
    'fallback_any' => true,
];
