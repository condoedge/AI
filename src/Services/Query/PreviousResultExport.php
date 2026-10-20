<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Query;

use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Support\AliasNormalizer;

/**
 * PreviousResultExport — does an export follow-up ask for the recorded result AS IT IS?
 *
 * "Ok, can you export that members list to excel?" needs no new query: the file is
 * the recorded result, re-executed through the scoped executor up to the document
 * row limit (AiManager::previousResultExportRows). The pipeline nevertheless paid
 * for query generation on every such turn — 6-8k input tokens and ~2 s (eval p18:
 * B resilience t3, B french t1, A pdf t1) — and then discarded the generated query
 * in favour of the recorded result, because a fresh query only ever wins when it
 * ADDS columns to it (the "…with their contact info" extension).
 *
 * The turn asks for nothing new when ALL of these hold — Query Understanding's own
 * judgments compared with what the conversation recorded, by structure only:
 *
 *  1. QU judged it an export (export_format) that refers back to what was shown
 *     (follow_up_reference), and the QU call did not fail;
 *  2. the conversation recorded a query AND rows (last_cypher_query,
 *     last_result_count, last_result_sample) — a digest turn records no query and
 *     keeps its own route;
 *  3. the turn's SUBJECT is the recorded result's: QU's subject for this turn
 *     (its label, else its search subject) is a spelling of the recorded
 *     `last_subject` ({@see AliasNormalizer::sameVariant()}). QU re-labels the
 *     subject when the ask changes what is shown, including the asks for which it
 *     names no property concept (measured live: "add their last login to it").
 *     A RE-LABEL ALONE does not force a query, though — eval p19-A / p20-A roster
 *     t1, "give me that as an Excel with their contact info": the label became
 *     "Team Members Contact Information" while the attributes QU named (email,
 *     role) were both columns of the recorded roster, and the turn paid ~7k tokens
 *     for the recorded query again. A re-labelled subject is the recorded result's
 *     when ({@see self::sameResultUnderAnotherLabel()}) QU NAMED the attributes it
 *     wants — at least one that is a recorded column — and, by condition 4, every
 *     concept it named is carried; and
 *     the turn's primary entity (QU's first entity type) is a node label of the
 *     recorded query, so it is not another population with the same column names.
 *     And a turn that NAMES NOTHING ({@see self::namesNothing()}; eval p21-B french
 *     t1, "Parfait — exporte-la en Excel.": a new label, no entity, no concept —
 *     generation ran, 6.5k tokens, and returned an EMPTY query) is the recorded
 *     result's when its label brings no word the conversation did not already hold.
 *     The bare "no entity, no concept" signal is NOT used alone: it is also what QU
 *     reports for "add their last login to it" (measured, every stored run);
 *  4. every PROPERTY CONCEPT QU named is one the recorded result already carries:
 *     it is mentioned, in any spelling, by a column of the recorded rows or by a
 *     property the recorded query projects ("balance" after a query returning
 *     `… AS balance`; "contact" after name / email / role is NEW) — or it names an
 *     entity the recorded query holds ("members" after a roster of users: the
 *     population the rows already are, {@see self::namesEntityOf()}).
 *
 * Person names and filters QU may also report are deliberately NOT conditions: a
 * fresh query that only narrows the recorded rows (same columns) was never the
 * export source — the recorded result was — so skipping it changes nothing, and QU
 * was measured live to echo the current user's own name from the rows it was shown.
 *
 * Anything else — and anything unknown (no recorded subject, a turn without a
 * subject) — is NOT proven to be the same ask: query generation runs as before.
 * No word list anywhere: both comparisons are identifier / spelling normalization.
 *
 * DELIBERATELY NOT RESOLVED — a concept through the host's property DESCRIPTIONS
 * ("contact" → the e-mail column; eval p21-A roster t1, "…as an Excel with their
 * contact info": generation ran and returned the recorded RETURN, as in all seven
 * stored runs of that turn). Measured 2026-10-07 on the host configuration: no
 * entity declares per-property aliases; "contact" is mentioned by 30 properties —
 * 12 by name, 18 only in the prose of a description, among them 7 foreign keys and
 * the role column's enum legend — so a mention is no declaration; the recorded
 * roster query matches an entity with two contact properties the result does not
 * hold; and "contact" is the host's own name for an ENTITY the recorded query does
 * not match (condition 4: that stays new). Skipping is not provably right, so that
 * turn keeps its query. A host that declares the mapping on the column itself
 * (a column or property NAME the concept is a spelling of) is already carried.
 */
final class PreviousResultExport
{
    /**
     * @param array<string, mixed> $conversation the pipeline-stamped conversation context
     */
    public static function asksNothingNew(QueryUnderstandingResult $qu, array $conversation): bool
    {
        if ($qu->exportFormat === null || !$qu->followUpReference || $qu->failed) {
            return false;
        }

        $cypher = $conversation['last_cypher_query'] ?? null;
        $sample = $conversation['last_result_sample'] ?? null;
        if (!is_string($cypher) || trim($cypher) === ''
            || !is_array($sample) || $sample === []
            || (int) ($conversation['last_result_count'] ?? 0) <= 0) {
            return false;
        }

        $recordedSubject = $conversation['last_subject'] ?? null;
        if (!is_string($recordedSubject) || trim($recordedSubject) === '') {
            return false;
        }

        $labels = self::nodeLabels($cypher);
        $carried = self::carriedNames($cypher, $sample);
        $attributes = 0;
        foreach ($qu->propertyConcepts as $concept) {
            if (!is_string($concept) || trim($concept) === '') {
                continue;
            }
            foreach ($carried as $name) {
                if (AliasNormalizer::mentions($name, $concept)) {
                    $attributes++;
                    continue 2;
                }
            }
            if (self::namesEntityOf($concept, $labels)) {
                continue; // the population the recorded rows already are
            }

            return false; // a concept the recorded result does not carry: something new is asked
        }

        return AliasNormalizer::sameVariant($qu->displaySubject(), $recordedSubject)
            || self::sameResultUnderAnotherLabel($qu, $attributes, $labels)
            || self::namesNothing($qu, $conversation, $recordedSubject, $labels, $carried);
    }

    /**
     * Whether an export follow-up NAMES NOTHING (condition 3, last case of the class doc):
     * QU reported no entity and no property concept, and the label it wrote for the turn
     * holds no word of its own — every word of it (two characters or more; an elided
     * article is none) is a word of
     *  - the recorded subject,
     *  - a column or a projected property of the recorded result, or a node label of its query,
     *  - a question of the conversation that was already ANSWERED ({@see self::answeredQuestions()}).
     * {@see AliasNormalizer::tokens()} words: case, accents and plural marks aside.
     *
     * The label check is what makes the conjunction safe. "No entity, no concept" is how QU
     * reports "Parfait — exporte-la en Excel." (eval p21-B french t1) — and also how it
     * reported "Ok, but could you add their last login to it?" in every stored run (p16–p21,
     * seven of seven), a turn that asks for a new column. What QU still says about a new
     * column is the label: "Team Members Last Login" brings two words the conversation never
     * held; "Liste des membres de l'équipe" brings none (the roster question asked for "la
     * liste des membres de mon équipe"). A turn without a subject is not judged: unknown.
     *
     * @param array<string, mixed> $conversation
     * @param list<string> $labels the node labels of the recorded query
     * @param list<string> $carried the columns and projected properties of the recorded result
     */
    private static function namesNothing(QueryUnderstandingResult $qu, array $conversation, string $recordedSubject, array $labels, array $carried): bool
    {
        $named = array_filter(
            [...$qu->entityTypes, ...$qu->propertyConcepts],
            static fn (mixed $name): bool => is_string($name) && trim($name) !== ''
        );
        $words = self::words($qu->displaySubject());
        if ($named !== [] || $words === []) {
            return false;
        }

        $known = array_fill_keys(self::words(implode("\n", [
            $recordedSubject,
            ...$labels,
            ...$carried,
            ...self::answeredQuestions($conversation),
        ])), true);

        foreach ($words as $word) {
            if (!isset($known[$word])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The user's questions of the conversation that an answer FOLLOWS (`recent_exchanges`,
     * {@see \Condoedge\Ai\Services\Context\ConversationContextManager::buildPromptContext()}).
     * A question still without its answer is THIS turn's own — the regenerate path records
     * the user message before the pipeline runs — and its words are what is being asked,
     * never what the conversation already holds. Answers are not read: a word the
     * assistant wrote ("their last login is not shown") is not something the user was given.
     *
     * @param array<string, mixed> $conversation
     * @return list<string>
     */
    private static function answeredQuestions(array $conversation): array
    {
        $answered = [];
        $pending = [];
        foreach ((array) ($conversation['recent_exchanges'] ?? []) as $exchange) {
            if (!is_array($exchange)) {
                continue;
            }
            $question = $exchange['question'] ?? null;
            if (is_string($question) && trim($question) !== '') {
                $pending[] = $question;
            } elseif (is_string($exchange['answer_summary'] ?? null) && trim($exchange['answer_summary']) !== '') {
                $answered = [...$answered, ...$pending];
                $pending = [];
            }
        }

        return $answered;
    }

    /**
     * The words of a text as compared: {@see AliasNormalizer::tokens()} with an apostrophe
     * read as a word boundary ("l'équipe" is "équipe"), single characters left out.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $tokens = AliasNormalizer::tokens(str_replace(["'", "\u{2019}", "\u{2018}", '`'], ' ', $text));

        return array_values(array_unique(array_filter($tokens, static fn (string $token): bool => mb_strlen($token) > 1)));
    }

    /**
     * Whether a turn whose subject QU RE-LABELLED may still be the recorded result
     * (condition 3 of the class doc) — the label alone is not evidence of a new ask:
     *  - QU named at least one property concept that is a COLUMN of the recorded
     *    result: what the turn wants shown is then stated (the caller has checked that
     *    every concept is carried). With none named — or only the population's own
     *    name — the re-label is the only sign of what changed: a query is needed;
     *  - the turn has a subject at all, and its PRIMARY entity — QU's first entity
     *    type — is a node label of the recorded query: the same records, not another
     *    population that happens to share column names. (QU's other entity types are
     *    not compared: it lists related entities the recorded query never matched.)
     *
     * @param int $attributes how many of the concepts QU named are attributes the recorded result carries
     * @param list<string> $labels the node labels of the recorded query
     */
    private static function sameResultUnderAnotherLabel(QueryUnderstandingResult $qu, int $attributes, array $labels): bool
    {
        if ($attributes < 1 || trim($qu->displaySubject()) === '') {
            return false;
        }

        $primary = $qu->entityTypes[0] ?? null;
        if (!is_string($primary) || self::identifier($primary) === '') {
            return false;
        }

        foreach ($labels as $label) {
            if (self::identifier($label) === self::identifier($primary)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a concept NAMES AN ENTITY the recorded query holds — the host's own word for
     * one of its node labels (the label, its config name or a declared alias, in any
     * spelling: {@see EntityLabelIndex::labelForTerm()}). Live 2026-10-07: after a roster,
     * "Ok, export that to Excel." came back with the roster turn's own concepts,
     * ["members", "roles"] — "members" is the population the rows already are, never a
     * column to add. An entity the recorded query does not match stays new.
     *
     * @param list<string> $labels the node labels of the recorded query
     */
    private static function namesEntityOf(string $concept, array $labels): bool
    {
        try {
            $label = EntityLabelIndex::shared()->labelForTerm(trim($concept));
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_string($label) || $label === '') {
            return false;
        }

        foreach ($labels as $recorded) {
            if (self::identifier($recorded) === self::identifier($label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The node labels of a query, in order of appearance (string literals masked).
     *
     * @return list<string>
     */
    private static function nodeLabels(string $cypher): array
    {
        preg_match_all('/\(\s*[A-Za-z_]?\w*\s*:\s*`?([A-Za-z_]\w*)`?/', FanOutCollapse::mask($cypher), $nodes);

        return array_values(array_unique($nodes[1] ?? []));
    }

    /** An entity name reduced to its letters and digits, lower-cased (identifier normalization). */
    private static function identifier(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
    }

    /**
     * What the recorded result carries: the column names of its rows, the column names
     * its query returns and the properties those columns project (`u.last_logged_at AS
     * last_login` → "last_login", "last_logged_at"). A concept is matched against ONE
     * name at a time, never across two.
     *
     * @param array<int, mixed> $sample
     * @return list<string>
     */
    private static function carriedNames(string $cypher, array $sample): array
    {
        $names = [];
        foreach ($sample as $row) {
            foreach (is_array($row) ? array_keys($row) : [] as $column) {
                $names[(string) $column] = true;
            }
        }
        foreach (FanOutCollapse::returnItems($cypher) as $item) {
            $names[$item['column']] = true;
            if (preg_match_all('/\.\s*`?([A-Za-z_]\w*)`?/', FanOutCollapse::mask($item['expression']), $properties)) {
                foreach ($properties[1] as $property) {
                    $names[$property] = true;
                }
            }
        }

        return array_map('strval', array_keys($names));
    }
}
