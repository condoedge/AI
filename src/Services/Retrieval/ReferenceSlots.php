<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\Support\DocumentScopeLabel;

/**
 * ReferenceSlots — one slot of the reference budget for each KIND of document.
 *
 * A turn's passages can come from documents of two kinds: a GENERAL document (a
 * project file: it applies to everyone) and a record's OWN document (a file a
 * user uploaded and filed on a record, or on the organisation). The reference
 * budget ranks every passage together by similarity — and a long own document
 * written in the user's words can take every slot.
 *
 * Measured live (2026-10-08, a building's declaration beside the general law):
 * on "the seller has not paid his charges — do I, the buyer, pay?" the five slots
 * went to the declaration and the general law's article on that very point,
 * admitted by the search, never reached the answer; and the map reader, which
 * reads the documents of the selected passages, was shown one document only.
 *
 * The rule is structural — the kind of a passage's document and its rank: when a
 * kind that has candidates holds no slot, its best-ranked candidate takes the
 * LAST slot of a kind that holds several. Which of two documents prevails is for
 * the documents (and the answer) to say; the budget only keeps both in sight.
 */
final class ReferenceSlots
{
    public const GENERAL = 'general';
    public const OWN = 'own';

    /**
     * The kind of a passage's document, from whose document it is
     * ({@see DocumentScopeLabel}); null for a passage that says nothing about it.
     *
     * @param array<string, mixed> $reference
     */
    public static function kindOf(array $reference): ?string
    {
        $scope = DocumentScopeLabel::of($reference);
        if ($scope === null) {
            return null;
        }

        return DocumentScopeLabel::isGeneral($scope) ? self::GENERAL : self::OWN;
    }

    /**
     * @param list<int> $top The candidates the budget kept, best first.
     * @param list<int> $order EVERY candidate, best first ($top is its head).
     * @param array<int, string|null> $kinds candidate => its kind ({@see self::kindOf()}).
     * @param array<int, bool> $protected Candidates never given up (an article the user named).
     * @return list<int> $top, with one slot for each kind that has a candidate —
     *         unchanged when nothing can be given up for it.
     */
    public static function reserveOnePerKind(array $top, array $order, array $kinds, array $protected = []): array
    {
        $top = array_values($top);

        $present = [];
        foreach ($order as $candidate) {
            $kind = $kinds[$candidate] ?? null;
            if ($kind !== null) {
                $present[$kind] ??= $candidate; // the best-ranked candidate of the kind
            }
        }

        foreach ($present as $kind => $best) {
            $held = array_count_values(array_filter(array_map(static fn (int $i): ?string => $kinds[$i] ?? null, $top)));
            if (($held[$kind] ?? 0) > 0) {
                continue;
            }

            // Given up: the lowest-ranked kept passage of a kind that keeps another.
            for ($position = count($top) - 1; $position >= 0; $position--) {
                $victim = $top[$position];
                $victimKind = $kinds[$victim] ?? null;
                if ($victimKind === null || !empty($protected[$victim]) || ($held[$victimKind] ?? 0) < 2) {
                    continue;
                }
                array_splice($top, $position, 1);
                $top[] = $best;
                break;
            }
        }

        return $top;
    }
}
