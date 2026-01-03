# Module 05: QUERY_GENERATION - Checklist

## Core Files
- [ ] Read `src/Services/HasInternalModules.php` (understand pattern)
- [ ] Read `src/Services/QueryGenerator.php`
- [ ] Read `src/Services/SemanticPromptBuilder.php`
- [ ] Read `src/Services/PatternLibrary.php`

## PromptSections
- [ ] Read `BasePromptSection.php`
- [ ] Read `ProjectContextSection.php`
- [ ] Read `GenericContextSection.php`
- [ ] Read `CurrentUserContextSection.php`
- [ ] Read `SchemaSection.php`
- [ ] Read `RelationshipsSection.php`
- [ ] Read `ExampleEntitiesSection.php`
- [ ] Read `FileContextSection.php`
- [ ] Read `SimilarQueriesSection.php`
- [ ] Read `ConversationContextSection.php`
- [ ] Read `DetectedEntitiesSection.php`
- [ ] Read `DetectedScopesSection.php`
- [ ] Read `PatternLibrarySection.php`
- [ ] Read `QueryRulesSection.php`
- [ ] Read `QuestionSection.php`
- [ ] Read `TaskInstructionsSection.php`

## Analysis
- [ ] Document HasInternalModules pattern
- [ ] Trace section registration
- [ ] Verify section priorities
- [ ] Check shouldInclude() for each section
- [ ] Verify token limit handling
- [ ] Check LLM integration

## Issues
- [ ] Identify unused sections
- [ ] Check for duplicate logic
- [ ] Verify error handling
- [ ] Check extension points
