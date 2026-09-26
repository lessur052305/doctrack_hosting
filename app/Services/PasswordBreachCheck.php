<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * "Has this exact password appeared in a known data breach?" — asked of the
 * free, keyless Pwned Passwords range API, the same source Laravel's own
 * `uncompromised()` validation rule uses at submit time. Only the first five
 * characters of the password's SHA-1 hash ever leave this server (k-anonymity).
 *
 * Unlike that rule, which silently reports "not leaked" whenever the lookup
 * fails, this reports three states — so the live hint on the Create Account /
 * Reset Password form can say honestly "couldn't check right now" instead of
 * looking like a failed requirement. The server-side gate at submit is still
 * `uncompromised()`; this backs the hint only.
 */
class PasswordBreachCheck
{
    public const CLEAN = 'clean';

    public const LEAKED = 'leaked';

    public const UNAVAILABLE = 'unavailable';

    /** @return 'clean'|'leaked'|'unavailable' */
    public function check(string $password): string
    {
        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = Http::withHeaders(['Add-Padding' => 'true'])
                ->timeout(5)
                ->get("https://api.pwnedpasswords.com/range/{$prefix}");
        } catch (Throwable $e) {
            report($e);

            return self::UNAVAILABLE;
        }

        if (! $response->successful()) {
            return self::UNAVAILABLE;
        }

        foreach (preg_split('/\r?\n/', trim($response->body())) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$lineSuffix, $count] = explode(':', trim($line), 2);

            // Add-Padding fills the response with fake zero-count entries so its
            // size doesn't leak information — a real match always has count > 0.
            if (strtoupper($lineSuffix) === $suffix && (int) $count > 0) {
                return self::LEAKED;
            }
        }

        return self::CLEAN;
    }
}
