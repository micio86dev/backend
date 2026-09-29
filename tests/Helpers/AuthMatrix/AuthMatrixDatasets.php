<?php

declare(strict_types=1);

namespace Tests\Helpers\AuthMatrix;

/**
 * Pest datasets over the catalogue: ONE named case per (route, actor), so a
 * failure reads `with data set "PATCH api/projects/{project} :: viewer"`.
 *
 * Datasets are resolved before the app boots, which is fine: they read only
 * the hand-written {@see AuthMatrixCatalogue::entries()} array, never the router.
 */
final class AuthMatrixDatasets
{
    /**
     * The credentials that are not a valid user JWT, presented to a user-JWT route.
     */
    public const REJECTED_CREDENTIALS = [
        AuthMatrix::UNAUTHENTICATED,
        AuthMatrix::CANDIDATE_JWT,
        AuthMatrix::API_KEY,
    ];

    /**
     * Domains whose full actor matrix is exercised (T4). Every other jwt-user
     * domain currently gets only the credential-rejection row: extending
     * coverage is a matter of adding its name here AND the route's fixture in
     * {@see AuthMatrixFixtures}, which the suite refuses to run without.
     *
     * @var list<string>
     */
    public const ORG_SCOPED_DOMAINS = ['projects', 'project-questions'];

    /**
     * Every jwt-user route x {no credential, candidate JWT, API key}.
     *
     * @return array<string, array{string, string}>
     */
    public static function rejectedCredentials(): array
    {
        $cases = [];

        foreach (AuthMatrixCatalogue::entries() as $key => $entry) {
            if ($entry['auth'] !== AuthMatrix::AUTH_JWT_USER) {
                continue;
            }

            foreach (self::REJECTED_CREDENTIALS as $actor) {
                $cases["{$key} :: {$actor}"] = [$key, $actor];
            }
        }

        return $cases;
    }

    /**
     * Every route of a fully covered domain x every user actor except the
     * credential-rejection ones (those live in {@see self::rejectedCredentials()}).
     *
     * @return array<string, array{string, string}>
     */
    public static function userActors(): array
    {
        $cases = [];

        foreach (AuthMatrixCatalogue::entries() as $key => $entry) {
            if ($entry['auth'] !== AuthMatrix::AUTH_JWT_USER || ! in_array($entry['domain'], self::ORG_SCOPED_DOMAINS, true)) {
                continue;
            }

            foreach ($entry['outcomes'] as $actor => $expected) {
                if (in_array($actor, self::REJECTED_CREDENTIALS, true)) {
                    continue;
                }

                $cases["{$key} :: {$actor}"] = [$key, $actor];
            }
        }

        return $cases;
    }
}
