# Page-section anchors for navigation buttons — design and implementation plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** a navigation button the assistant attaches can land on the exact section of a page (the card or tab the answer talks
about), not only on the page. Sections are found by a planner, given stable ids, reviewed once, and declared in setup like pages.

**Architecture:** the host (Coolecto) gives sections stable ids at their source (one helper, a few base classes), an artisan
planner scans the page komponents and writes a reviewed registry, the registry feeds `ai.navigation.pages[*].sections`, the
package's navigation call may pick one section of the chosen page, and the link carries it as `?section=<id>`. One global script
scrolls to it on every page load (full or Kompo turbo), opening the tab that contains it.

**Tech stack:** Laravel 11, Kompo (PHP komponents rendered by Vue), condoedge/ai (`NavigationPages`, `ActionAttacher`,
`LinkAction`), headless Chrome over CDP for the runtime check.

**Inputs:** research report `research-page-sections.md` (scratchpad, 2026-10-22; findings are restated here where they decide
something). Owner principles that bind this design: buttons are actions declared in setup (articles never produce buttons);
code decides, the model only picks among declared options; simple, not dumb; the package stays host-agnostic.

---

## 1. What the research established (the constraints)

| Fact | Evidence | Consequence |
|---|---|---|
| A `#hash` scrolls on a full page load, but Kompo's turbo navigation drops it (`axios.get` + `pushState(responseURL)`, no fragment) | `node_modules/vue-kompo/index.js:195-229`, `TurboClick.js:44-106`; headless test on `/` | Do not rely on `#hash`. Use a **query parameter**, which survives turbo. |
| Tabs are selected only by **index** (`?tab_number=N`), and indexes drift (tabs are dropped when empty, conditional first tabs) | `VlSwipeableTabs.vue:375-434`; `CampaignPageSettings.php:25`; `CampaignPageRecipients.php:47`; `SupplierFormPage.php:35` | Never store tab indexes. Find the tab that **contains** the section in the DOM and click its label. |
| A precedent exists: `?section=entities` + an `onLoad` scroll | `TeamSettingsPage.php:11-22`, link `DashboardTicketsView.php:144` | Generalise it; one global helper instead of per-page code. |
| Ids today are hand-made; `campaign-form-panel` is reused by every settings step; `_Tab()->id()` lands on the label **and** the panel | `BaseCampaignForm.php:13,70`; `FormTabs.vue:9-17` (inferred) | A section id goes on the **content root**, never on `_Tab`; one helper makes ids; uniqueness is tested. |
| Section sets are conditional (type, role, flags, `isAdvanced()`); `dashboard` is a dispatcher | `CampaignStepsTrait.php:83-219`; `DashboardView.php:77-117` | The registry lists the **superset**; a section absent for this user simply does not scroll (the button still opens the page). Conditional sections are marked so the chooser prefers unconditional ones. |
| Lazy tabs/panels arrive after load (`_LazyTab` fires on load; `?tab_number` renders the chosen one eagerly) | `LazyComponent.php:40-58`; `lazy-components.php:189-213` | The script waits (bounded) for the id to appear. |
| Fixed navbar and fixed settings tab bar | `DashboardNavbar.php:16`; `_Utils.scss:109-117` | Scroll with an offset (`scroll-margin-top` on section roots). |
| `chemin:` segments are real UI titles 87% of the time (segment 2 = page, 3 = tab/card) | `scratchpad/chemin.py` over 320 articles | The planner can **check** its titles against the articles (coverage report), but articles never pick a section: setup does. |
| Pages with real sections: settings, recipients, communications, promote, account statement, org.manage, supplier info, supplier statistics, dashboard cards | research §7 q.7 | v1 scope: these 9. The other 18 pages are one table or one form. |

## 2. Design

### 2.1 Section ids (host, Coolecto)
- One helper, `App\Kompo\Support\SectionId::for(string $titleKey): string` → `'sec-' . Str::slug(str_replace('.', '-', $titleKey))`
  (e.g. `campaign.campaign-name` → `sec-campaign-campaign-name`). Deterministic from the **translation key** of the section's
  title, so it never changes with the language or the wording, and two sections cannot share an id unless they share a title key
  (the uniqueness test catches that).
- Applied at the source, not page by page where a base class exists:
  - `BaseCampaignForm` (≈55 settings cards): the card root gets `->id(SectionId::for($this->pageTitle))` and the class
    `ai-section` (stop reusing `campaign-form-panel` for the root; keep it on the inner `_Panel` only if something targets it —
    grep first).
  - Tab bodies: the first element **inside** each `_Tab`/`_LazyTab` of the 9 pages gets `->id(SectionId::for($tabLabelKey))`
    (not the `_Tab` itself).
  - Card titles on promote, account statement, supplier statistics, dashboard cards: wrap the card root, id from its title key.
  - `org.manage`: panels already carry `intro-*` ids used by tutorials. Keep them; add the `sec-*` id on the inner form root.
- CSS: `.ai-section { scroll-margin-top: 96px; }` (navbar height + settings tab bar; measured in Task 9) in `resources/scss`.

### 2.2 The planner (host, Coolecto): `php artisan ai:sections:plan`
- Input: the routes of `ai.navigation.pages` that are in the v1 scope list (config `ai.navigation.section_pages`), each mapped to
  its komponent class by reading the route's action.
- Static scan of each komponent and its children it composes (followed through `new X()`, `_Panel(new X)`, `getSettingsSteps()`
  classes via a reflection call on a **fixture** campaign-less list: the step classes are listed statically in
  `CampaignStepsTrait`, so the scan reads that file's class constants and `$tabGroup`):
  - `$pageTitle = 'key'` (settings cards) + `public static $tabGroup` (their tab),
  - `->label('key')` on `_Tab(`/`_LazyTab(`,
  - `_Html('key')->miniTitle()|medTitle()|class('card-title')` card titles,
  - existing `->id('...')` on the same element (kept as an alias).
- Output: `resources/ai-help/sections.php` (committed, reviewed), one entry per section:
  `['route' => ..., 'id' => 'sec-…', 'title_key' => 'campaign.campaign-name', 'tab_key' => 'campaign.basics'|null,
    'conditional' => true|false, 'source' => 'app/Kompo/Dashboard/StepCampaignName.php']`.
  `conditional` is true when the class is added under a condition in `getSettingsSteps()` or the tab is conditional.
- A `--check-articles` report: for each article `chemin`, whether segments 2–3 resolve to a registered section of a page in its
  `pages:` list (FR value → key, reverse lookup in `resources/lang/fr/*.php`). Output: coverage %, unmatched chemins (most are
  gestures; listed for the KB owner, never auto-fixed).
- The planner never edits komponents: ids are added by the code in Tasks 2–5; the planner only lists and verifies.

### 2.3 Setup and the package
- Coolecto `config/ai.php`: each in-scope page gets `'sections' => require resource_path('ai-help/sections.php')[$route]`
  shaped as `[id => label_key]` (label = the section title key, translated at runtime like page labels).
- Package `NavigationPages`: the existing navigation call returns `page` keys from an enum of offered pages. Extend the strict
  schema with an optional `section` (enum = the chosen page's section ids, `null` allowed). Only sections of a page that is
  offered (authorized) can be chosen; a section id that is not in the page's list is dropped by code. Pages without `sections`
  keep today's prompt byte-identical (CondoEdge declares none → cassette replay 0).
- Package `ActionAttacher::withNavigation`: the `link` invocation params become `['route' => ..., 'params' => ['section' => id],
  'style' => 'button']`; `LinkAction` already turns extra params into a query string (`LinkAction.php:76-93`).
- Label of the chip stays the page label; when a section is chosen the label becomes "Page › Section" (both translated).

### 2.4 Landing (host, Coolecto): one global helper
- `resources/js/ai-sections.js` (Mix bundle, loaded by `layouts/app.blade.php`) defines `window.CoolectoSections.land()`:
  1. read `section` from `location.search`; stop if absent or not `^sec-[a-z0-9-]+$`;
  2. wait up to 4 s (MutationObserver + timeout) for `document.getElementById(id)`;
  3. if the element is inside a tab panel that is not active, find that panel's tab label (`[role=tab][aria-controls=<panel id>]`
     or the label at the same index within the same tab group, resolved **from the DOM**, never from config) and click it;
  4. `element.scrollIntoView({behavior: 'smooth', block: 'start'})`, add a 2 s highlight class `ai-section--flash`;
  5. remove `section` from the URL with `history.replaceState` (a reload does not re-scroll).
- Run on every page: full load (`DOMContentLoaded`) and after Kompo turbo. Turbo boots a new Vue on the replaced body: hook the
  same registry the home bundles use (memory: `window.HomeMarketing.register`) or, for the dashboard, an `onLoad` on
  `PageAdf` base class calling `CoolectoSections.land()` (verify which fires after turbo in Task 8).
- A section the user does not have (condition false) → nothing to find → the page simply opens at the top (no error).

## 3. Decisions to confirm with the owner (before Task 1) — all 4 confirmed as written, 2026-10-23
1. v1 page scope = the 9 pages above (others keep page-level buttons).
2. `?section=` as the transport (not `#`), and the URL is cleaned after landing.
3. Ids derived from title translation keys (`sec-…`); existing `intro-*` tutorial ids are kept untouched.
4. The registry is a committed, reviewed file regenerated by the planner (not built at runtime).

## 4. Tasks

### Task 1: SectionId helper + uniqueness rule (host)
**Files:** Create `app/Kompo/Support/SectionId.php`; Test `tests/Unit/Ai/SectionIdTest.php`.
- Step 1 (test): `SectionId::for('campaign.campaign-name') === 'sec-campaign-campaign-name'`; keys with dots/underscores/accents
  slug identically across calls; empty key throws `InvalidArgumentException`.
- Step 2: run `php artisan test tests/Unit/Ai/SectionIdTest.php` → FAIL (class missing).
- Step 3: implement (`Str::slug(str_replace(['.', '_'], '-', $key))`, prefix `sec-`).
- Step 4: run → PASS.

### Task 2: settings cards carry their section id
**Files:** Modify `app/Kompo/Dashboard/BaseCampaignForm.php` (`render()`/`cardHeader()`, ~57-86); Test
`tests/Feature/Ai/SettingsSectionIdsTest.php`.
- Step 1 (test): for each class listed in `CampaignStepsTrait::getSettingsSteps()` source (parse the file for `::class`), a new
  instance (with `app()->instance('bootFlag', true)` and a fixture campaign of each type, transactions only, dev DB rules) renders
  an element whose root id is `SectionId::for($pageTitle)`; no two steps of one campaign share an id.
- Step 2: run → FAIL.
- Step 3: set the root id and class `ai-section` in `BaseCampaignForm`; move `campaign-form-panel` to the inner panel only after a
  grep shows what reads it (`grep -rn "campaign-form-panel" app resources`).
- Step 4: run → PASS; open `campaign.page.settings` locally (headless) and assert the ids are in the DOM (Task 9 script).

### Task 3: tab bodies on the 9 pages carry section ids
**Files:** Modify `CampaignPageRecipients.php:47-82`, `CampaignPageCommunications.php:27-53`, `AccountStatementNew.php:256-308`,
`SupplierFormPage.php:20-50`, `SupplierAccountStatement.php:191-206`, `CampaignPageProducts.php:27-52` (tickets tabs). Test:
extend `SettingsSectionIdsTest` → `PageSectionIdsTest` (render each page komponent for a fixture user/campaign; collect ids).
- TDD as above: the first child of each tab gets `->id(SectionId::for($labelKey))->class('ai-section')`; `_Tab` ids untouched.
- Dynamic labels (`groupsLabel()`, supplier-name tabs): id from the **key** behind the label (`campaign.p2p-groups` /
  `campaign.groups`), supplier tabs get none (not a stable section).

### Task 4: card sections on promote, statistics, account statement, dashboard
**Files:** `CampaignPagePromote.php:36-126`, `SupplierStatisticsPage.php:36-67`, `AccountStatementNew.php:328-434`, dashboard
cards listed in research §1.1 (`DashboardTransactions`, `TeamProducts`, `CampaignMailsCard`, …). Same TDD loop.

### Task 5: org.manage inner ids
**Files:** `app/Kompo/Teams/TeamOrganizationPage.php:12-34` (keep `intro-*`, add `sec-*` on the inner form root). Same loop.

### Task 6: the planner command
**Files:** Create `app/Console/Commands/AiSectionsPlanCommand.php`, `app/Ai/Sections/SectionScanner.php`; Test
`tests/Unit/Ai/SectionScannerTest.php` with fixture komponent source strings (a `$pageTitle` card, a `_Tab(...)->label('k')`,
a `miniTitle()` card, a conditional step).
- Scanner returns entries `{route,id,title_key,tab_key,conditional,source}`; the command writes `resources/ai-help/sections.php`
  (`var_export`, sorted, stable diff) and prints a table + counts per page.
- `--check-articles`: reverse FR lookup (`resources/lang/fr/*.php` flattened value → keys), coverage of chemin segments 2–3,
  unmatched list. Test with two fixture articles (one matching, one gesture).
- Acceptance: running it on the repo lists the ≈55 settings cards + tabs/cards of the 9 pages; every listed id exists in the
  render tests of Tasks 2–5.

### Task 7: package — sections in the navigation choice
**Files (package `ai/`):** Modify `src/Services/Actions/Attach/NavigationPages.php` (schema + prompt lines only when a page has
`sections`), `src/Services/Actions/Attach/ActionAttacher.php:311-357` (params), `config/ai.php` (document `sections` on pages).
Tests: `tests/Unit/Services/Actions/Attach/NavigationSectionsTest.php`:
- a page without `sections`: prompt and schema byte-identical to today (snapshot of the messages);
- a page with sections: the schema's `section` enum = that page's ids + null; a section of an unoffered page or an unknown id is
  dropped; the `link` invocation carries `params.section`; label "Page › Section".
- Run the package filter + **CondoEdge cassette replay (must be 0)** + Coolecto 6-chunk suite (no regression in navigation gates).

### Task 8: landing script
**Files:** Create `resources/js/ai-sections.js`; Modify `webpack.mix.js`, `resources/views/layouts/app.blade.php` (load once),
`app/Kompo/Common/PageAdf.php` (`onLoad` → `CoolectoSections.land()` if turbo needs it), `resources/scss/...` (`.ai-section`,
`.ai-section--flash`).
- Test with the headless script (Task 9): full load and turbo click both land; a section in an inactive tab opens the tab; a
  lazy tab section lands within 4 s; an unknown or absent id leaves the page at the top; the URL no longer has `section`.

### Task 9: runtime verification script
**Files:** `scripts/ai/verify-sections.mjs` (dev-only, not shipped): logs in with a signed URL per audience
(`enhanceRouteWithLoginSignature`), opens each registry entry `route?section=id` for a fixture campaign per type, records
found / scrolled-into-view (target top within 0–150 px of the viewport top) / tab opened. Output table + failures. This is how
the scroll offset in §2.1 is measured.

### Task 10: config wiring + eval
- Coolecto `config/ai.php`: `sections` on the 9 pages from the registry.
- Add 8 turns to the Coolecto suite (`storage/app/eval/coolecto/`) whose gold names a section (e.g. "Où je change le nom de ma
  campagne?" → `campaign.page.settings` + `sec-campaign-campaign-name`), with a new ai-eval gate `link_section_in` (list of ids;
  vacuous when no link). Run the affected chunks; log in `coolecto-progress.md`.

## 5. Risks and how the plan handles them
- **Index drift / hidden tabs** → DOM-relative tab opening, never indexes.
- **Turbo** → query param + global helper; verified by Task 9 for both load kinds.
- **Duplicate ids** → ids from title keys + uniqueness tests per page.
- **Conditional sections** → superset registry, `conditional` flag in the prompt line ("may not exist for every campaign"),
  graceful no-op landing.
- **CondoEdge** → no `sections` declared → byte-identical prompts (replay 0, Task 7).
- **Model picking a wrong section** → enum-bound choice, page must be offered, the button still opens the right page.
