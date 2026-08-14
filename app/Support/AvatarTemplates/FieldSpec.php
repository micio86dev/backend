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
        ], fn (mixed $v): bool => $v !== null && $v !== false);
    }
}
