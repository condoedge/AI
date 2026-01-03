# Module 21: DOMAIN_CONTRACTS - Analysis Plan

> **Module Slug:** domain-contracts
> **Priority:** HIGH (Core abstractions)
> **Estimated Files:** 24

## Responsibility
- Define core interfaces
- Define value objects
- Define DTOs
- Pure contracts with no implementation

## Files

### Contracts (17 files)
- `ChunkStoreInterface.php`
- `ContextRetrieverInterface.php`
- `DataIngestionServiceInterface.php`
- `EmbeddingProviderInterface.php`
- `FileAccessResolverInterface.php`
- `FileChunkerInterface.php`
- `FileExtractorInterface.php`
- `FileProcessorInterface.php`
- `GraphStoreInterface.php`
- `LlmProviderInterface.php`
- `PromptSectionInterface.php`
- `QueryExecutorInterface.php`
- `QueryGeneratorInterface.php`
- `ResponseGeneratorInterface.php`
- `ResponseSectionInterface.php`
- `SectionModuleInterface.php`
- `VectorStoreInterface.php`

### Domain (5 files)
- `Domain/Contracts/Nodeable.php`
- `Domain/Traits/HasNodeableConfig.php`
- `Domain/ValueObjects/GraphConfig.php`
- `Domain/ValueObjects/NodeableConfig.php`
- `Domain/ValueObjects/RelationshipConfig.php`
- `Domain/ValueObjects/VectorConfig.php`

### DTOs (2 files)
- `DTOs/FileChunk.php`
- `DTOs/ProcessingResult.php`
