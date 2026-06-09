<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class QueryUnderstandingResult
{
    public function __construct(
        /** @var string[] Entity labels relevant to the question */
        public readonly array $entityTypes = [],
        /** @var string[] Person/contact names mentioned */
        public readonly array $personNames = [],
        /** @var string[] Property concepts ("insurance", "parking") */
        public readonly array $propertyConcepts = [],
        /** Rewritten query optimized for semantic search */
        public readonly string $searchQuery = '',
        /** Whether user is asking about their own data */
        public readonly bool $isPossessive = false,
        /** count|list|lookup|aggregate|compare|other */
        public readonly string $intent = 'other',
        /** data_query|knowledge_query|hybrid */
        public readonly string $questionType = 'hybrid',
        /** Clarified underlying question for better search/prompting */
        public readonly string $clarifiedQuestion = '',
        /** @var string[] Broad keyword tags for document/file search (5-10 terms covering the semantic space) */
        public readonly array $searchTags = [],
        /**
         * The user's question translated into each configured corpus language,
         * keyed by language code (e.g. ['fr' => '...', 'en' => '...']). Empty when
         * no corpus languages are configured. Each value is a natural, pure
         * single-language phrasing — used as an extra file-search fan-out angle so
         * a question in one language can retrieve documents written in another.
         *
         * @var array<string,string>
         */
        public readonly array $corpusTranslations = [],
    ) {}

    public static function empty(string $question = ''): self
    {
        return new self(
            isPossessive: $question !== '' && self::looksLikePossessive($question),
        );
    }

    private static function looksLikePossessive(string $question): bool
    {
        return (bool) preg_match('/\b(my|mine|i\s+have|i\s+own|belong.*to\s+me)\b/i', $question);
    }
}
