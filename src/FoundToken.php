<?php

namespace Depman\Client;

/**
 * A token and where it came from.
 *
 * The source is the useful half. Four mechanisms can supply a token, and when
 * the wrong one wins the symptom is a 401 with no clue which of them produced
 * the credential — so `depman doctor` prints this rather than a bare "found".
 */
final class FoundToken
{
    public function __construct(
        public readonly string $value,
        public readonly string $source,
    ) {}
}
