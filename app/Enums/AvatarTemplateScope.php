<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who owns an avatar template: one organization, or the platform itself
 * (`organization_id IS NULL`). The wire vocabulary for the scope marker on
 * template resources and picker options.
 */
enum AvatarTemplateScope: string
{
    case Organization = 'organization';
    case Platform = 'platform';
}
