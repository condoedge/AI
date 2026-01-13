# Documentation Audit and Update Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Comprehensively audit and update the resources/docs/1.0 documentation to match the current codebase, ensuring accuracy, completeness, and developer-friendly presentation with proper diagrams.

**Architecture:** Phase-locked execution using analysis artifacts for tracking. Code is the source of truth; docs must match code.

**Tech Stack:** Laravel package, Markdown documentation, Mermaid diagrams, LaRecipe-compatible format.

---

## Overview

This plan updates documentation for a Laravel AI package that provides:
- Chat UI components (AiChatModal, AiChatFloating)
- Natural language to Cypher query generation
- Neo4j graph database integration
- Qdrant vector database for semantic search
- Extensible LLM and embedding provider system
- File processing and search capabilities

---

## Task 1: Audit and Fix commands.md

**Files:**
- Modify: resources/docs/1.0/reference/commands.md
- Read: src/Console/Commands/*.php (all 9 command files)

**Step 1: Read all command source files to understand actual commands**

Read each command file to extract:
- Command signature
- Description
- Options and arguments

Commands to read:
1. DiagnoseCommand.php
2. DiscoverEntitiesCommand.php
3. IngestEntitiesCommand.php
4. SyncRelationshipsCommand.php
5. ProcessFilesCommand.php
6. IndexSemanticCommand.php
7. IndexScopesCommand.php
8. IndexContextCommand.php
9. ValidateConfigCommand.php

**Step 2: Compare with current commands.md**

Identify:
- Commands documented but do not exist in code
- Commands in code but not documented

**Step 3: Rewrite commands.md with accurate information**

Remove fictional commands: ai:clear, ai:status, ai:test, ai:query, ai:config, ai:publish, ai:ingest-eager

**Step 4: Verify documentation accuracy**

Run: php artisan list ai
Expected: List matches documented commands

---

## Task 2: Document Entity Actions System

**Files:**
- Create: resources/docs/1.0/configuration/entity-actions.md
- Modify: resources/docs/1.0/index.md (add to navigation)
- Read: config/ai.php (entity_actions, generic_actions sections)
- Read: src/Services/Response/ActionLinkHandler.php

**Step 1: Read the entity actions config structure**

Understand the entity_actions and generic_actions config format from config/ai.php.

**Step 2: Read ActionLinkHandler.php to understand processing**

Document how entity:// and action:// links are processed.

**Step 3: Create entity-actions.md**

Include:
- What entity actions are
- Configuration structure with examples
- How AI generates action links
- How to define custom actions
- How to define generic (non-entity) actions
- Security considerations

**Step 4: Update index.md navigation**

Add entry under Configuration section.

---

## Task 3: Document File Context System

**Files:**
- Update: resources/docs/1.0/chat/file-context-system.md
- Read: config/ai.php (file_context section)
- Read: src/Services/Context/FileContextProvider.php
- Read: src/Services/Context/FileAccessResolver.php

**Step 1: Read file_context configuration**

Document physical_paths, supported_extensions, security settings, access_scope.

**Step 2: Read FileContextProvider and FileAccessResolver**

Understand how files are indexed, access-controlled, and retrieved.

**Step 3: Update file-context-system.md**

Include:
- Two file sources: physical docs and database files
- Security model (access scopes)
- Indexing physical files
- How file context is injected into prompts
- File citations in responses

---

## Task 4: Document Chat Theming System

**Files:**
- Create: resources/docs/1.0/chat/theming.md
- Read: config/ai.php (ui section)
- Read: src/Services/UI/ChatThemeInterface.php
- Read: src/Services/UI/AbstractChatTheme.php
- Read: src/Services/UI/ConfigChatThemeFactory.php
- Read: src/Services/UI/Themes/*.php

**Step 1: Read theming interfaces and implementations**

Understand ChatThemeInterface, AbstractChatTheme, theme factories, built-in themes.

**Step 2: Read user settings integration**

Read ChatSettingsInterface.php and UserChatSettings.php.

**Step 3: Create theming.md**

Include:
- Built-in themes
- Configuration-based theming
- User preference-based theming
- Creating custom themes
- Color customization

---

## Task 5: Document Prompt and Response Section Pipeline

**Files:**
- Update: resources/docs/1.0/extending/prompt-sections.md
- Read: src/Contracts/PromptSectionInterface.php
- Read: src/Contracts/ResponseSectionInterface.php
- Read: src/Services/PromptSections/BasePromptSection.php
- Read: config/ai.php (query_generator_sections, response_generator_sections)

**Step 1: Read section interfaces**

Document contracts for PromptSectionInterface and ResponseSectionInterface.

**Step 2: Inventory all built-in sections**

List all sections with their purpose and priority.

**Step 3: Update prompt-sections.md**

Include:
- What prompt sections are
- How the pipeline works
- Priority ordering
- Built-in sections reference
- Creating custom sections
- Response sections documentation

---

## Task 6: Update architecture.md with New Components

**Files:**
- Modify: resources/docs/1.0/internals/architecture.md
- Read: src/AiServiceProvider.php (provides() method)

**Step 1: Extract all registered services from ServiceProvider**

Create comprehensive list of all services registered.

**Step 2: Update architecture diagram**

Add missing components:
- FileContextProvider
- ResponseFileEnricher
- ResponseEntityEnricher
- ContentLinkProcessor
- ChatThemeFactory
- ChatSettings

**Step 3: Update component descriptions**

Add sections for:
- Response Processing (enrichers, link handlers)
- UI Services (themes, settings)
- Context Services (file context, conversation context)

---

## Task 7: Document Conversation Context Management

**Files:**
- Update: resources/docs/1.0/chat/conversation-context-management.md
- Read: src/Services/Context/ConversationContextManager.php
- Read: src/Services/Context/EntityExtractor.php
- Read: src/Services/Context/ReferenceResolver.php

**Step 1: Read ConversationContextManager and dependencies**

Understand context extraction, storage, and injection.

**Step 2: Update conversation-context-management.md**

Include:
- How context accumulates across messages
- Entity extraction from user queries
- Reference resolution
- How context affects query generation

---

## Task 8: Document Conversation Export

**Files:**
- Create: resources/docs/1.0/chat/conversation-export.md
- Read: src/Services/Chat/Exporter/*.php

**Step 1: Read export service implementations**

Understand export formats and options.

**Step 2: Create conversation-export.md**

Include:
- Available export formats
- How to trigger export
- Creating custom exporters

---

## Task 9: Update Configuration Reference

**Files:**
- Modify: resources/docs/1.0/foundations/configuration.md
- Read: config/ai.php (full file)

**Step 1: Audit all config sections**

Create list of all 30+ config sections.

**Step 2: Identify undocumented sections**

Compare with current configuration.md.

**Step 3: Add missing sections documentation**

Missing sections likely include:
- rate_limits
- relationship_weights
- file_context (detailed)
- ui
- entity_actions
- generic_actions
- query_generator_sections
- response_generator_sections

---

## Task 10: Create Mermaid Diagrams

**Files:**
- Modify: resources/docs/1.0/internals/architecture.md
- Modify: resources/docs/1.0/internals/data-flows.md
- Modify: resources/docs/1.0/chat/module-pipeline.md

**Step 1: Create chat message flow diagram**

Show: User -> AiChatModal -> SendMessageService -> AiChatService -> AI facade -> Response

**Step 2: Create prompt building pipeline diagram**

**Step 3: Create response processing pipeline diagram**

**Step 4: Update existing diagrams for accuracy**

---

## Task 11: Document Security Features

**Files:**
- Create: resources/docs/1.0/advanced/security.md
- Read: src/Services/Security/*.php

**Step 1: Read security service implementations**

Document InputSanitizer, QueryResultFilter, AccessLevelResolver, CypherSanitizer.

**Step 2: Create security.md**

Include:
- Input sanitization (prompt injection prevention)
- Cypher injection prevention
- Access level system
- Query result filtering
- File access control
- Best practices

---

## Task 12: Final Review and Cross-Reference Check

**Files:**
- Read: resources/docs/1.0/index.md
- Read: All documentation files

**Step 1: Verify all navigation links work**

**Step 2: Verify cross-references**

**Step 3: Verify code examples work**

**Step 4: Update analysis/STATUS.md with completion**

---

## Execution Notes

- Execute tasks sequentially
- Read code files before writing documentation
- Preserve existing good documentation
- Follow existing documentation style
- Use Mermaid for all diagrams
- Include practical examples
- Reference related documentation sections

