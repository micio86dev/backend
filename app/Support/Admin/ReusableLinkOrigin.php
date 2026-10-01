<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Participant;
use App\Support\PublicApi\PublicId;

/**
 * The reusable link origin of a participant, as the admin reads show it
 * (reusable-interview-links, slice B4).
 *
 * One owner for the shape, so the list row and the detail can never disagree
 * about it: exactly the link's public id and its label, and `null` for every
 * participant that did not come from a reusable link. Nothing else about the
 * link is exposed here: not its internal id, token prefix, language, counters,
 * hash or any URL.
 *
 * The link is read through the `reusableInterviewLink` relation, which is a
 * tenant-scoped model: a row whose key points at another organization's link
 * resolves to NULL, so the origin can never cross a tenant. Callers eager-load
 * `reusableInterviewLink:id,public_id,label` (see
 * `AdminParticipantReader::listQuery()`), so a list issues no query per row.
 */
final class ReusableLinkOrigin
{
    /**
     * The relation constraint every admin read loads: just the columns this
     * class reads, so no other link column is ever selected.
     */
    public const EAGER_LOAD = 'reusableInterviewLink:id,public_id,label';

    /**
     * @return array{id: string, label: string|null}|null
     */
    public static function of(Participant $participant): ?array
    {
        $link = $participant->reusableInterviewLink;

        if ($link === null) {
            return null;
        }

        return [
            'id' => PublicId::encode($link),
            'label' => $link->label,
        ];
    }
}
