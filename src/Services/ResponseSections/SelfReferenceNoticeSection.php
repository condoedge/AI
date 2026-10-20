<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Response\PiiMasker;

/**
 * SelfReferenceNoticeSection
 *
 * MECHANISM (not prose) for the "contact == you" failure: the response model kept
 * ignoring the general insider-voice rule and presented the current user's own
 * email back to them as "how to reach your team". Prose rules are skippable; this
 * section DETECTS the condition and injects an explicit, unmissable, per-value
 * note only when it actually holds.
 *
 * Detection is fully generic — no hardcoded strings or host semantics: each scalar
 * string in the execution-result data sample (the same first rows ResultsDataSection
 * shows the model) is compared against the authenticated user's own email
 * (case-insensitive exact) and full name (case-insensitive exact). On a match, the
 * note names the exact value and states that it IS the current user ("that's you"),
 * never a third party or a contact route.
 *
 * PII note: this section only ever quotes values that are ALREADY in the results
 * data the model sees — it introduces no new personal data into the prompt. A
 * masked identifying field (ai.access_control.identifying_fields) is quoted as its
 * per-turn token ([email#1]); detection itself always runs on the REAL values.
 *
 * Priority 52: immediately after ResultsDataSection (50), so the note sits right
 * under the data it corrects, before entity actions (55).
 */
class SelfReferenceNoticeSection extends BaseResponseSection
{
    protected string $name = 'self_reference_notice';
    protected int $priority = 52;

    /**
     * The canonical "that's-you" marker wordings this section instructs the model
     * to use. SINGLE SOURCE: the ResponseGenerator's self-reference guard and the
     * eval harness's no_self_contact gate both detect compliance against these
     * exact phrases (plus the localized wordings in resources/lang via
     * markerPhrases()) — never a parallel list of their own.
     */
    public const THATS_YOU_MARKERS = ["that's you", "that's your own email"];

    /**
     * Rows scanned — mirrors ResultsDataSection's default sample size so the note
     * only fires for values the model can actually see in the results block.
     */
    private const SAMPLE_ROWS = 10;

    /** Defensive bound on recursion into nested result structures. */
    private const MAX_DEPTH = 6;

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return $this->matches($context) !== [];
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        $matches = $this->matches($context);

        if ($matches === []) {
            return '';
        }

        $output = $this->header('SELF-REFERENCE IN THE RESULTS — READ BEFORE ANSWERING');
        $output .= "IMPORTANT: some values in the results ARE the current user's own identity — "
            . "they describe the very person you are answering:\n";

        [$primaryMarker, $emailMarker] = [self::THATS_YOU_MARKERS[0], self::THATS_YOU_MARKERS[1]];

        // Detection ran on the REAL values (above); the note quotes them the way the
        // model sees them — masked identifying fields appear as their turn token.
        $masker = PiiMasker::for($context);

        foreach ($matches as $match) {
            $value = $match['kind'] === 'email'
                ? (string) $masker->maskField('email', $match['value'])
                : $masker->maskText($match['value']);

            if ($match['kind'] === 'email') {
                $output .= "- The email '{$value}' in the data IS the current user's own email address — "
                    . "that is THEM. NEVER present it as contact information, as \"how to reach\" their team, "
                    . "or as belonging to someone else. If it is relevant, say it is their own (e.g. \"{$primaryMarker}\" "
                    . "/ \"{$emailMarker}\").\n";
            } else {
                $output .= "- The name '{$value}' in the data IS the current user's own name — "
                    . "that record is about THEM. NEVER describe this person as a third party to contact; "
                    . "if it is relevant, acknowledge it is the user themself (e.g. \"{$primaryMarker}\").\n";
            }
        }

        $output .= "Present these values as the user's own data. Never tell the user to contact themselves.\n\n";

        return $output;
    }

    /**
     * Every wording that counts as a compliant "that's-you" marker: the canonical
     * English phrases this section instructs the model to use, plus the localized
     * marker/patch wordings shipped in resources/lang (en + fr, and the caller's
     * question locale when given). SINGLE SOURCE for the response guard and the
     * eval gate — translator failures (no container, plain PHPUnit) degrade to the
     * constant wordings.
     *
     * @return list<string> lowercased marker phrases
     */
    public static function markerPhrases(?string $locale = null): array
    {
        $phrases = self::THATS_YOU_MARKERS;

        // 'en' / 'fr' are the package's own shipped locales (resources/lang);
        // null = the host app locale so host-added translations count too.
        foreach (array_unique([null, 'en', 'fr', $locale]) as $loc) {
            foreach (['ai.response.self_reference_marker', 'ai.response.self_reference_patch'] as $key) {
                try {
                    if (!function_exists('trans')) {
                        continue;
                    }
                    $translated = trans($key, [], $loc);
                    if (is_string($translated) && trim($translated) !== '' && $translated !== $key) {
                        $phrases[] = trim($translated);
                    }
                } catch (\Throwable $e) {
                    // No translator available — the constant wordings suffice.
                }
            }
        }

        return array_values(array_unique(array_map(
            static fn (string $phrase) => self::normalizeMarkerText($phrase),
            $phrases
        )));
    }

    /**
     * TYPOGRAPHIC normalization for marker comparison — the single source used
     * by markerPhrases(), the ResponseGenerator's self-reference guard and the
     * eval no_self_contact gate: lowercase + fold typographic apostrophes
     * (U+2019/U+2018/U+02BC/U+FF07 — the default in much LLM output, especially
     * French "c’est vous") onto the straight apostrophe the wordings carry.
     * Character-for-character (lengths preserved), never lexical.
     */
    public static function normalizeMarkerText(string $text): string
    {
        return mb_strtolower(strtr($text, [
            "\u{2019}" => "'",
            "\u{2018}" => "'",
            "\u{02BC}" => "'",
            "\u{FF07}" => "'",
        ]));
    }

    /**
     * Identity values of the authenticated user found verbatim (case-insensitive
     * exact, trimmed) in the visible result sample.
     *
     * @return list<array{kind: 'email'|'name', value: string}>
     */
    private function matches(PipelineContext $context): array
    {
        $data = $context->executionResult->data;
        if (!is_array($data) || $data === []) {
            return [];
        }

        return self::selfValuesIn(array_slice($data, 0, self::SAMPLE_ROWS), $this->authUser());
    }

    /**
     * The user's own identity values (email / full name, case-insensitive exact) found
     * in $rows — THE self-reference detection, shared with the action attacher (B2: the
     * user's own row in a result attaches their profile link).
     *
     * @return list<array{kind: 'email'|'name', value: string}>
     */
    public static function selfValuesIn(array $rows, mixed $user): array
    {
        if (!is_object($user) || $rows === []) {
            return [];
        }

        $needles = [];
        $email = self::scalarString($user->email ?? null);
        if ($email !== null) {
            $needles['email'] = mb_strtolower($email);
        }
        $name = self::scalarString($user->name ?? null);
        if ($name !== null) {
            $needles['name'] = mb_strtolower($name);
        }

        if ($needles === []) {
            return [];
        }

        $found = [];
        self::scan($rows, $needles, $found, 0);

        return array_values($found);
    }

    /**
     * Depth-first scan collecting the first data value matching each needle.
     *
     * @param array<string, string> $needles kind => lowercased identity value
     * @param array<string, array{kind: string, value: string}> $found kind => match
     */
    private static function scan(mixed $node, array $needles, array &$found, int $depth): void
    {
        if (count($found) === count($needles) || $depth > self::MAX_DEPTH) {
            return;
        }

        if (is_array($node)) {
            foreach ($node as $child) {
                self::scan($child, $needles, $found, $depth + 1);
                if (count($found) === count($needles)) {
                    return;
                }
            }

            return;
        }

        $value = self::scalarString($node);
        if ($value === null) {
            return;
        }

        $lower = mb_strtolower($value);
        foreach ($needles as $kind => $needle) {
            if (!isset($found[$kind]) && $lower === $needle) {
                $found[$kind] = ['kind' => $kind, 'value' => $value];
            }
        }
    }

    /**
     * Trimmed non-empty string form of a scalar, else null.
     */
    private static function scalarString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Container-tolerant auth user (mirrors ResponseCurrentUserContextSection).
     */
    private function authUser(): mixed
    {
        try {
            if (!function_exists('auth') || !auth()->check()) {
                return null;
            }

            return auth()->user();
        } catch (\Throwable $e) {
            return null; // no auth context (console/queue) — render nothing
        }
    }
}
