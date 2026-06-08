# File Chunk Tagging — Follow-up Plan

> Deferred from the pipeline context enhancement work.

## Problem
File chunks in Qdrant only have basic metadata (file_id, file_name, content, chunk_index). When QU identifies concepts like "modification, rules" and entities like "Unit", we can't use those to filter or boost file search results. We rely purely on embedding similarity, which gives borderline scores (0.39-0.41) for legal documents.

## Proposed Solution
1. During file indexing (`FileProcessor`), extract tags/concepts from each chunk using a fast LLM call or keyword extraction
2. Store tags in the Qdrant payload: `tags: ["co-ownership", "modification", "walls", "common portions", "private portions"]`
3. During search, use Qdrant's filter capabilities to boost chunks matching QU-detected concepts
4. The enricher can then also use tags to better classify files (team rules vs provincial law)

## Why Deferred
- Requires changes to the indexing pipeline (FileProcessor, SemanticChunker)
- Requires re-indexing all files after the change
- The multi-query approach + lowered threshold is a workable interim solution
