# Module 07: RESPONSE_GENERATION - Analysis Plan

> **Module Slug:** response-generation
> **Priority:** CRITICAL (Extensible pipeline - mirrors query-generation)
> **Estimated Files:** 13

---

## 1. Responsibility
- Generate natural language responses from query results
- Use extensible section pipeline (same pattern as QueryGenerator)
- Extract insights from data
- Suggest visualizations

## 2. Files to Analyze

### Core Files
| File | Purpose |
|------|---------|
| `src/Services/ResponseGenerator.php` | Main generator with section pipeline |
| `src/Services/Response/ResponseFileEnricher.php` | File context enrichment |

### ResponseSections (11 files)
| File | Priority |
|------|----------|
| `BaseResponseSection.php` | Base class |
| `SystemPromptSection.php` | 10 |
| `PrivacyAndSecurityGuidelinesSection.php` | 15 |
| `ResponseProjectContextSection.php` | 20 |
| `OriginalQuestionSection.php` | 30 |
| `QueryInfoSection.php` | 40 |
| `FileContextSection.php` | 45 |
| `ResultsDataSection.php` | 50 |
| `StatisticsSection.php` | 60 |
| `GuidelinesSection.php` | 70 |
| `ResponseTaskSection.php` | 80 |

## 3. Key Questions
- How does section pipeline differ from QueryGenerator?
- How are insights extracted?
- How are visualizations suggested?
- Is FileContextSection same as in PromptSections? (Different classes, same name)

## 4. Risk Areas
| Risk | Severity | Check |
|------|----------|-------|
| Unused sections | Medium | Trace usage |
| Data not consumed | Medium | Verify data flow |
| Response too long | Medium | Check limits |
