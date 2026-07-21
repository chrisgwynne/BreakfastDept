<?php

declare(strict_types=1);

namespace Breakfast\Platform\Hermes;

/**
 * Loads Hermes credentials from the environment.
 *
 * Each credential is defined by an env var of the form:
 *     HERMES_KEY_<id>=<scopes>|<base64-secret>
 * where <scopes> is a comma-separated scope list. Secrets live ONLY in the
 * environment; Kirby content stores at most a credential id and an
 * enabled/disabled toggle.
 */
final class CredentialStore
{
    /** @var array<string,Credential>|null */
    private ?array $credentials = null;

    /** @param array<string,string>|null $env inject for testing */
    public function __construct(private readonly ?array $env = null)
    {
    }

    public function find(string $id): ?Credential
    {
        return $this->load()[$id] ?? null;
    }

    /** @return array<string,Credential> */
    public function all(): array
    {
        return $this->load();
    }

    /** @return array<string,Credential> */
    private function load(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $source = $this->env ?? $this->readEnv();
        $out    = [];

        foreach ($source as $key => $value) {
            if (str_starts_with($key, 'HERMES_KEY_') === false || $value === '') {
                continue;
            }

            $id = substr($key, strlen('HERMES_KEY_'));

            if (str_contains($value, '|') === false) {
                continue;
            }

            [$scopeStr, $secretB64] = explode('|', $value, 2);

            $scopes = array_values(array_filter(array_map(
                static fn (string $s): string => trim($s),
                explode(',', $scopeStr)
            )));
            $scopes = array_values(array_filter($scopes, [Scopes::class, 'isValid']));

            $secret = base64_decode(trim($secretB64), true);

            if ($secret === false || $secret === '' || $scopes === []) {
                continue;
            }

            $out[$id] = new Credential($id, $scopes, $secret);
        }

        return $this->credentials = $out;
    }

    /** @return array<string,string> */
    private function readEnv(): array
    {
        $out = [];
        foreach (array_merge($_ENV, $_SERVER) as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'HERMES_KEY_') && is_string($v)) {
                $out[$k] = $v;
            }
        }

        // getenv fallback for keys not surfaced in the superglobals.
        foreach (array_keys($out) as $k) {
            $env = getenv($k);
            if ($env !== false) {
                $out[$k] = $env;
            }
        }

        return $out;
    }
}
