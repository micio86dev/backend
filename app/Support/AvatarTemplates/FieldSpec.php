<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

/**
 * One configurable knob on an avatar template (C14).
 *
 * Declarative on purpose. This single definition drives the backoffice form,
 * the API's validation, and the payload sent to the provider — three things
 * that must never disagree. Written three times they drift, and the drift is
 * invisible: a form offering a knob the payload never sends looks exactly like
 * a knob that does not work.
 */
final readonly class FieldSpec
{
    /**
     * @param  list<string>|null  $options  Allowed values for a select.
     * @param  string|null  $palPath  JSON-pointer-ish path into the Tavus PAL
     *                                body. Fields WITHOUT one are
     *                                conversation-level; a persona knob sent on
     *                                a conversation is silently ignored, which
     *                                is the worst kind of wrong.
     */
    public function __construct(
        public string $key,
        public FieldType $type,
        public string $labelKey,
        /**
         * i18n key for the one-line explanation shown under the control.
         *
         * Carried by the SPEC, not the form: a field added server-side then
         * arrives with its explanation instead of acquiring one later, if ever.
         * Nullable so a field without a hint still renders its control —
         * explanation is an aid, and losing it must never cost the operator the
         * ability to configure.
         */
        public ?string $hintKey = null,
        public bool $required = false,
        public ?array $options = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public int|float|null $step = null,
        public ?string $palPath = null,
        /**
         * Which provider catalogue resource (`voice`|`avatar`|`replica`) backs
         * this field, if any (avatar-template-catalogue PR2, D2).
         *
         * Orthogonal to `type`: the stored value stays a plain string and
         * `ConfigValidator` needs no change. Nullable so most fields — including
         * `ttsExternalVoiceId`, which has no provider catalogue at all — simply
         * omit it, exactly like `hintKey`.
         */
        public ?string $catalogueResource = null,
        /**
         * For a select whose valid options depend on ANOTHER field's value:
         * that field's key (`ttsModelName` depends on `ttsEngine`).
         * `$options` stays the flat union so a client that ignores this still
         * renders a legal list; `$optionsByValue` narrows it per value.
         * `ConfigValidator` enforces the pairing server-side.
         */
        public ?string $optionsDependOn = null,
        /** @var array<string, list<string>>|null */
        public ?array $optionsByValue = null,
        /**
         * The field belongs to PLATFORM templates only (heygen-third-party-voices):
         * an organization's template routes refuse it (`platform_only`) and the
         * organization field-spec route does not list it. A first cut deliberately
         * not opened to organization admins.
         */
        public bool $platformOnly = false,
        /**
         * The field is REPLACED by another field's value: when `supersededByKey`
         * holds one of `supersededByValues` this field is not required and must
         * be absent (`superseded_by_*`), so a native HeyGen voice id cannot sit
         * beside an external voice that overrides it (a dead knob).
         */
        public ?string $supersededByKey = null,
        /** @var list<string>|null */
        public ?array $supersededByValues = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'type' => $this->type->value,
            'label_key' => $this->labelKey,
            'hint_key' => $this->hintKey,
            'required' => $this->required,
            'options' => $this->options,
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
            'catalogue_resource' => $this->catalogueResource,
            'options_depend_on' => $this->optionsDependOn,
            'options_by_value' => $this->optionsByValue,
            'platform_only' => $this->platformOnly,
            'superseded_by_key' => $this->supersededByKey,
            'superseded_by_values' => $this->supersededByValues,
        ], fn (mixed $v): bool => $v !== null && $v !== false);
    }
}
