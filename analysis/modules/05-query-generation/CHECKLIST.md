# Module 05: QUERY_GENERATION - Checklist

## Core Files
- [x] Read `src/Services/HasInternalModules.php` (understand pattern)
- [x] Read `src/Services/QueryGenerator.php`
- [x] Read `src/Services/SemanticPromptBuilder.php`
- [x] Read `src/Services/PatternLibrary.php`

## PromptSections
- [x] Read `BasePromptSection.php`
- [x] Read `ProjectContextSection.php`
- [x] Read `GenericContextSection.php`
- [x] Read `CurrentUserContextSection.php`
- [x] Read `SchemaSection.php`
- [x] Read `RelationshipsSection.php`
- [x] Read `ExampleEntitiesSection.php`
- [x] Read `FileContextSection.php`
- [x] Read `SimilarQueriesSection.php`
- [x] Read `ConversationContextSection.php`
- [x] Read `DetectedEntitiesSection.php`
- [x] Read `DetectedScopesSection.php`
- [x] Read `PatternLibrarySection.php`
- [x] Read `QueryRulesSection.php`
- [x] Read `QuestionSection.php`
- [x] Read `TaskInstructionsSection.php`

## Analysis
- [x] Document HasInternalModules pattern
- [x] Trace section registration
- [x] Verify section priorities
- [x] Check shouldInclude() for each section
- [x] Verify token limit handling
- [x] Check LLM integration

## Issues
- [x] Identify unused sections
- [x] Check for duplicate logic
- [x] Verify error handling
- [x] Check extension points
