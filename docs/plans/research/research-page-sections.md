# Research: page sections for AI navigation buttons (route + anchor)

Read-only. Paths relative to the repo root unless stated. "INFERRED" = deduced from source, not observed in a DOM.
"VERIFIED" = observed with headless Chrome on this machine.

## 0. TL;DR

1. Pages are composed of conditional pieces. A given route does not have one fixed section list. It depends on role, campaign type, flags and `isAdvanced()`. Example: `campaign.page.settings` builds its cards from `Campaign::getSettingsSteps()` (`app/Models/Traits/CampaignStepsTrait.php:83-219`). `dashboard` is a dispatcher to 8 different komponents (`app/Kompo/Dashboard/DashboardView.php:77-117`).
2. Tab selection already exists, as a query param and not a hash: `?tab_number=N` (index, 0-based). It is read in the tab Vue `created()` and written back with `replaceState` (`node_modules/js-kompo-utils/components/VlSwipeableTabs.vue:375-392,423-434`). There is no hash support.
3. A hash can scroll on a full page load (VERIFIED on the public home page, section 2.3). It is lost on Kompo SPA (turbo) navigation. `v-turbo-click` does `axios.get(el.href)` and `pushState(responseURL)`, and XHR `responseURL` has no fragment. Nothing scrolls afterwards (`node_modules/vue-kompo/index.js:195-229`, `node_modules/vue-kompo/js/core/TurboClick.js:44-107`). Query params survive turbo.
4. The repo already has two precedents. (a) `?section=entities` plus an `onLoad` scroll (`app/Kompo/Teams/TeamSettingsPage.php:11-22`, link at `app/Kompo/Ticketing/DashboardTicketsView.php:144`). (b) a tutorial/tour system with per-Komponent JSON registries of `#intro-*` element ids (`resources/_tutorials/*.json`, 14 files). It is an explicit registry that is already maintained.
5. `chemin:` segments match real FR translation values 87% of the time (910 of 1045 segments over the 320 organisateur articles). The unmatched ones are gestures or lookups ("cliquer sur...", "menu a trois points", "bandeau de mise en place"), not sections. Segment 2 (page) and segment 3 (tab or card) are the machine-usable ones.

## 1. The ~27 `navigation.pages` routes -> komponent -> composition

Config: `config/ai.php:125-186`. There are 27 entries. The chat builds a `link` invocation with `['route' => ..., 'style' => 'button']` (`vendor/condoedge/ai/src/Services/Actions/Attach/ActionAttacher.php:335-337`). `LinkAction` calls `route($route, $params)` or falls back to `url` (`vendor/condoedge/ai/src/Services/Actions/BuiltIn/LinkAction.php:76-93`). Extra `$params` become a query string for free.

Layouts: `Route::layout('layouts.dashboard'...)` groups, `routes/web.php:251,259,275,385,418`. `routes/kompo/account.php:5`, `routes/kompo/teams.php:5`. The dashboard layout has a FIXED navbar (`app/Kompo/Menus/DashboardNavbar.php:16` `$fixed = true`). It also renders the chat (`resources/views/kompo/content-bottom.blade.php`).

| nav key | route | komponent | route line |
|---|---|---|---|
| campaign_dashboard | dashboard | `Dashboard\DashboardView` | web.php:252 |
| campaign_settings | campaign.page.settings | `Settings\CampaignPageSettings` | web.php:304 |
| campaign_products | campaign.page.products | `Campaigns\CampaignPageProducts` | web.php:290 |
| campaign_participants | campaign.page.recipients | `Campaigns\CampaignPageRecipients` | web.php:291 |
| campaign_communications | campaign.page.communications | `Campaigns\CampaignPageCommunications` | web.php:288 |
| campaign_promote | campaign.page.promote | `Campaigns\CampaignPagePromote` | web.php:286 |
| campaign_qr_codes | campaign.qr-codes | `Ticketing\QrCodesCampaignPage` | web.php:369 |
| campaign_reports | campaign.orders.groups | `Reports\CampaignOrdersGroups` | web.php:315 |
| campaign_transactions | campaign.transactions | `Finance\CampaignOrdersTransactions` | web.php:312 |
| account_statement | team.account-statement-new | `Finance\AccountStatementNew` | web.php:393 |
| tax_receipts | finance.tax-receipts | `Finance\TaxReceiptsTable` | web.php:398 |
| bank_account | finance.onboarding | `Finance\FinanceOnboardingPage` | web.php:400 |
| my_organisation | org.manage | `Teams\TeamOrganizationPage` | routes/kompo/teams.php:12 |
| new_campaign | new-campaign | `Onboarding\Step1InternalForm` | web.php:276 |
| supplier_catalogue | supplier.deliverables | `Market\SupplierDeliverablesQuery` | web.php:434 |
| supplier_business | supplier.info | `Market\SupplierFormPage` | web.php:423 |
| supplier_storefront | supplier.storefront | `Market\SupplierStorefrontPage` | web.php:424 |
| supplier_templates | supplier.template-campaigns | `Market\SupplierTemplateCampaignsPage` | web.php:426 |
| supplier_clients | supplier.clients | `Market\SupplierClientsQuery` | web.php:430 |
| supplier_deliveries | supplier.deliveries | `Market\SupplierDeliveriesPage` | web.php:431 |
| supplier_production | supplier.production | `Market\SupplierProductionMatrix` | web.php:435 |
| supplier_statement | supplier.account-statement | `Finance\AccountStatement\SupplierAccountStatement` | web.php:442 |
| supplier_statistics | supplier.statistics | `Market\SupplierStatisticsPage` | web.php:432 |
| my_p2p_campaigns | user.p2p-campaigns | `Profile\MyP2PCampaignsTable` | web.php:260 |
| my_transactions | user.transactions | `Profile\MyTransactionsPage` | web.php:256 |
| my_tax_receipts | user.tax-receipts | `Profile\MyTaxReceiptsPage` | web.php:254 |
| my_profile | profile.show | `Account\ProfileInformationForm` | routes/kompo/account.php:7 |

All komponents are under `app/Kompo/`. Nearly all extend `PageAdf` or `App\Kompo\Common\Form` (`app/Kompo/Common/PageAdf.php`).

### 1.1 Per page composition (what exists today)

**dashboard** (`DashboardView.php`). `render()` is a dispatcher: no team -> `NoCurrentTeamForm` (:77); customer mode -> `MyProfileDashboard` (:83); scanner -> `QrCodeScannerView` (:87); supplier -> `Market\DashboardSupplierView` (:91); no campaign -> `Step1InternalForm` (:95); main campaign -> `MainCampaignDashboardView` (:99); phase SETUP -> `TeamUnlockCard` + `CampaignPhaseGuidanceCard` only (:107). Otherwise it composes the cards below, with no ids on them (:111-147):
- Cards: `CampaignProgressCard`, `CampaignJourneyCard`, `CampaignRhythmCard`, `CampaignTeamResultsCard`, `CampaignMailsCard`, `CampaignBenchmarkCard`, `CampaignPayoutCard`, `DashboardTransactions`, `TeamProducts`, `FeedbackRequest`.
- Titles are `_Html('key')->class('card-title')` (e.g. `DashboardTransactions.php:52` `campaign.transactions`; `TeamProducts.php:35`; `CampaignMailsCard.php:34` `campaign.dash-your-emails`), or `->miniTitle()` (`CampaignFunnelCard.php:41`).
- `CampaignPhaseGuidanceCard` titles are built from `$this->vocab(...)` (runtime, `:562,:621`).
- Root id is `campaign-dashboard-view` (`DashboardView.php:13-14`).

**campaign.page.settings** (`Settings/CampaignPageSettings.php`):
- Root `$id = 'page-settings'` (:11). Tabs come from `Campaign::getTabs()` (`CampaignStepsTrait.php:11-25`). The tab labels are the constants `Campaign::TAB_*` (`app/Models/Campaign.php:159-170`), e.g. `campaign.basics` = "Bases", `campaign.payment-behavior` = "Paramètres paiement".
- A tab exists only if `getCampaignSettingClasses($tab)` returns steps (:25). The "create experience" tab is added if `isAdvanced()` (:29-31). So `tab_number` for a given tab varies per campaign.
- Tab built as `_Tab(...)->label(__($tab))->id($this->getTabIntroId($tab))` (:25-27), with an id map at :65-79 (`intro-basics-tab`, `intro-payment-behavior-tab`, ...). Wrapper `_AdfResponsiveTabs(...)->id('intro-settings-tabs-container')` (:35). The live-preview panel id is `campaign-card-preview-panel` (:16,:38).
- Each tab holds step komponents (`Dashboard\Step*` and `Messaging\CampaignEndSmsReminderCard`, `Dashboard\DeleteCampaignCard`). The tab body is `Campaign::campaignSettingsEls()` (`Campaign.php:3344-3359`).
- Each step declares `protected $pageTitle = 'campaign.xxx'` (55 files, grep `pageTitle = ` in `app/Kompo`) and `public static $tabGroup = Campaign::TAB_*` (`BaseCampaignForm.php:15`; default BASICS). The title renders in `BaseCampaignForm::cardHeader()` (:78-86) as `_Html($this->pageTitle)->class('font-medium text-lg mb-4')`.
- The step list is a function of campaign type and flags (`CampaignStepsTrait.php:83-219`). Examples: ResultsRegistrationType only if `has_grades` (:113); EncanSettings and the SMS reminder only if encan (:121-123); `StepCampaignLinks` only if advanced and more than 1 future campaign (:213-215); Leaderboard only if recipients (:171).
- Existing id quirk: every step's inner `_Panel` is given the same id `campaign-form-panel` (`BaseCampaignForm.php:13,70`). The settings page therefore already has many duplicate DOM ids.
- Steps are inline (not lazy), so their DOM exists at first render.

**campaign.page.products** (`Campaigns/CampaignPageProducts.php`). Branches by campaign type: inscriptions -> `Inscriptions\Setup\InscriptionActivitiesList` (:21-23). Tickets (not draw) -> `_Tabs` with 3 tabs `campaign.product-and-venue-settings` / `campaign.event-dates` / `campaign.tickets` (:27-52), no ids. Otherwise a plain `_Rows`: optional toggle card, `CampaignProductsQuery`, optional recurring query, pledge custom-amount form, `CampaignSuppliersQuery` (:56-91). No ids, no titles in the page itself.

**campaign.page.recipients** (`Campaigns/CampaignPageRecipients.php`):
- Header block `participantsHeader()` (:116-130), id `intro-participants-mode`, title `campaign.how-participants-arrive` ("Comment vos participants s'inscrivent à votre campagne"). Sub-block id `intro-force-orders-toggle` (:157).
- `_AdfResponsiveTabs` (:47-82) with `_LazyTab`s: `campaign.inscription` (conditional on `hasRegistrationLinkCard()`, no id), `campaign.recipients` (`intro-recipients-tab`), `groupsLabel()` (`intro-groups-tab`; label is dynamic: `campaign.p2p-groups` or `campaign.groups`, `Campaign.php:1630`), `campaign.campaign-leaders`, `campaign.ambassadors`, `campaign.unsubscribed-donors`. Wrapper id `intro-recipients-tabs-container` (:82).
- Sub-campaign branch replaces everything with a message card (:25-34). The first tab's presence shifts every `tab_number`.

**campaign.page.communications** (`Campaigns/CampaignPageCommunications.php:27-53`). `_AdfResponsiveTabs` with 3 `_LazyTab`s, labels `campaign.communications` / `campaign.scheduled-sendings` / `campaign.sendings`, ids `intro-communications-tab` / `intro-scheduled-sendings-tab` / `intro-sendings-tab`, container `intro-communications-tabs-container`.

**campaign.page.promote** (`Campaigns/CampaignPagePromote.php`). A stack of cards, each titled `_Html('key')->miniTitle()` (:36,51,60,69,84,100,110,126): `campaign.my-campaign`, `campaign.share-on-social-networks`, `campaign.custom-login-page-for-participants`, `campaign.promote-my-campaign`, `campaign.single-page`, `campaign.show-on-my-website`, `campaign.featured-campaign-show-on-my-website`, `team.newsletter-subscription`. Page title `campaign.promote-my-campaign` via `medTitle` (:32). No ids. Several blocks are conditional (QR, featured campaign, customLoginUrl).

**campaign.qr-codes** (`Ticketing/QrCodesCampaignPage.php:51,54,77,81,86`). Titles `campaign.your-campaign-qr-code`, `campaign.pick-the-qr-code-design`, `campaign.your-campaign-general-qr-code`; subcomponents `QrCodeCampaignSingle`, `QrCodesRecipientsQuery`. No ids.

**campaign.orders.groups** (`Reports/CampaignOrdersGroups.php` -> `CampaignOrdersBase`). `top()` (:50-83) renders title `campaign.reports` (:59), a report-picker `_Select` whose options link to OTHER routes (`reportRoutes()` :186-219; the list is conditional on campaign type), date filter, export buttons (`pdf-button` id :51), spinner (`report-spinner`), KPI cards, and `_Panel(...)->id('report-summary')` (:82). The chat can only land on this one report route. "Choisissez un rapport" is the picker, not a section.

**campaign.transactions** (`Finance/CampaignOrdersTransactions.php:56`). Table with title `campaign.transactions`. Row actions (three dots) are modals (:206-240). No sections.

**team.account-statement-new** (`Finance/AccountStatementNew.php`):
- Layout: filters (:73-89), header with campaign info and `renderStatisticsWidgets` (tiles `finance.total-transactions`, `finance.net-profits` = "Profits nets cumulés", `finance.account-balance` = "Solde du compte", :219-252). Left column: `renderAmountExplanations` (`finance.amount-explanations`, :328-418) and `renderPaymentMethodBreakdown` (`finance.sales-by-payment-method`, :434). Right column: tabs (:256-308).
- Tabs: `finance.transactions`, `finance.product-costs`, `finance.deposits`, `finance.invoices`, `finance.journal-ledger`, `finance.refunds-and-disputes`, plus one tab per supplier labelled with the supplier NAME (:298-303). Container id `account-statement-tabs` (:308). This page already switches tabs by script: `[role=tab]` click by index (`AccountStatementElementsTrait.php:62-64`).

**finance.tax-receipts** (`Finance/TaxReceiptsTable.php:45`). Table titled `campaign.tax-receipts`; row actions are modals (:131-141).

**finance.onboarding** (`Finance/FinanceOnboardingPage.php:26-42`). `BankForm` (permission-gated) plus an impersonator-only block. Disabled wrapper if the parent team captures transfers. One real section.

**org.manage** (`Teams/TeamOrganizationPage.php:12-34`). The best-structured page: a `_Rows` of `_Panel(new XxxForm())->id('intro-...')`. Ids: `intro-organization-info` (TeamInfoForm, `_Title = register.organization`, `TeamInfoForm.php:14`), `intro-team-invitation`, `intro-team-invitations-view`, `intro-team-members`, `intro-tax-receipt-form`, `intro-tax-settings`, `intro-finance-reports`, `intro-analytics-form`, `intro-api-settings`, `intro-audit-report`, `intro-login-attempts`. `AdvancedSettingsForm` has no id (:27). Two panels are permission-gated (:14-19).

**new-campaign** (`Onboarding/Step1InternalForm.php:25-52`). A wizard in `_Panel(...)->id(BaseOnboardingForm::CARD_PANEL_ID)`. The card swaps by state (supplier, copy, locked catalogue, default). Sections are steps, not scrollable anchors. The wizard already scrolls itself (`BaseOnboardingForm.php:32`).

**Supplier pages** (all `role:supplier`, `routes/web.php:418-444`, layout `layouts.dashboard` WITHOUT `$localLayout`):
- `supplier.info` (`SupplierFormPage.php:20-50`): `_AdfResponsiveTabs` with `supplier.identity` / `supplier.team` / `supplier.delivery` (omitted when `delivered_by_email`) / `supplier.payment` / `supplier.integrations`. No ids. Precedent for index drift: `DeliveryTermsStep.php:66` links `tab_number => 2`, which is wrong when the Delivery tab is hidden.
- `supplier.storefront` (`SupplierStorefrontPage.php:22-52`): 3 cards (`SupplierInfoForm`, `SupplierRegisterPageForm`, `SupplierSalesBriefForm`), a status banner, no ids.
- `supplier.deliverables` (`SupplierDeliverablesQuery.php:48-81`): a Query with `top()` title `campaign.offered-products`; a column header row.
- `supplier.template-campaigns`, `supplier.deliveries`, `supplier.clients`: a single grid or table each.
- `supplier.production` (`SupplierProductionMatrix.php:117-346`): `_Panel` id `PANEL_ID`, title `supplier.production-title`.
- `supplier.statistics` (`SupplierStatisticsPage.php:36-67`): 4 `_Html` section titles (`supplier.monthly-sales`, `supplier.sales-by-org-type`, `supplier.best-sellers`, `supplier.order-size-distribution`), container id `stats-content`, charts loaded via `selfGet` (:202-217).
- `supplier.account-statement` (`SupplierAccountStatement.php:191-206`): 3 tabs `finance.account-statement` / `finance.deposits` / `finance.invoices`, id `supplier-statement-tabs`.
- The supplier dashboard is `DashboardSupplierView` (reached via `dashboard`; tabs with ids `intro-supplier-*`, `:56-64`).

**Participant / buyer pages**: `user.p2p-campaigns` is a Table with `intro-p2p-title`. `user.transactions` and `user.tax-receipts` are a single table with a `title` prop (`MyTransactionsPage.php`, `MyTaxReceiptsPage.php`). `profile.show` is `ProfileInformationForm` (`_Title = campaign.edit-your-profile`, :17). None of these are real section pages.

### 1.2 Async parts and modals (matters for anchors)

- `_LazyTab` / `_LazyComponent` use `_Hidden()->onLoad->post('_execute-lazy')->inPanel($panelId)`. Every lazy tab fires on page load, not on tab click (`vendor/condoedge/utils/src/Kompo/Elements/LazyComponent.php:40-58`). The content ids exist only after the XHR returns.
- `_LazyTabs` (used by `_AdfResponsiveTabs`, `app/Kompo/Helpers/elements.php:353-361`) renders the tab picked by `request('tab_number')` EAGERLY on the server (`vendor/condoedge/utils/src/Helpers/elements/lazy-components.php:189-213`). So `?tab_number=N` also removes the lazy delay for that tab.
- Modals open from `selfGet(...)->inModal()`. They have no URL and cannot be anchors (account statement help modal, tax receipt creation, order preview, ...).
- Charts (supplier statistics) and dashboard cards load via panels.

## 2. Ids, DOM rendering, hash and SPA navigation

### 2.1 How ids reach the DOM
- `->id('x')` stores `$id` on the element (`vendor/kompo/kompo/src/Elements/Traits/HasId.php:24-33`). On a Komponent it also registers a KompoId (`:28-30`).
- Vue adds `id` into the wrapper attributes: `$_defaultElementAttributes` (`node_modules/vue-kompo/js/element/mixins/BaseElement.js:46-51`). It is used by layouts, fields and others (`js/form/mixins/Layout.js:14`, `Other.js:10`, `Field.js:44`). So a layout `_Rows/_Div/_Panel/_Tab` renders `id="x"` on its root element.
- Existing ids: about 330 `->id('...')` in `app/Kompo`, 79 of them `intro-*` (the tutorial convention). The ids are hand-written, and only some elements have them.
- INFERRED duplicate id: for `_Tab()->id('x')`, both the tab label `<a role="tab">` (`FormTabLabel` uses `Other` mixin with `:vkompo="element"`, `FormTabs.vue:9-17`) and the panel `<section>` (`FormTab.vue` / `VlSwipeableTab.vue:2-9`) get `id="x"`. The tutorial runs `querySelector('#intro-basics-tab')` and gets the label first. A hash on a tab id would therefore scroll to the label, and would not select the tab.
- Known duplicate: `campaign-form-panel` on every step (section 1.1).

### 2.2 Tabs
- Global `_Tabs`/`_Tab` are overridden to the swipeable versions (`app/Kompo/Helpers/elements.php:718-731`: `SwipeableTabs::form`, `SwipeableTab::form`). `_ResponsiveTabs` -> a `Rows` that builds `_SwipeableTabs` plus a mobile `_Select`, id `responsive-tabs-<uniqid>` (`vendor/condoedge/utils/src/Kompo/Elements/ResponsiveTabs.php:46-52,118-134`). `_AdfResponsiveTabs` = `_LazyTabs` + app classes (`elements.php:353-361`).
- Active tab: `data.activeTab` 0 by default; `created()` takes `?tab_number` first, then the `activeTab(n)` config; out-of-range falls to 0 (`VlSwipeableTabs.vue:423-434`). Changing tab calls `persistActiveTab()`: `history.replaceState` with `tab_number` (:375-381, called from `selectTab`).
- Param key is configurable (`tabParamKey`, `SwipeableTabs.php:33-38`; `ResponsiveTabs.php:109-114`), default `tab_number`. All tab groups on one page share the same key unless it is overridden. On a page with 2 groups they would collide (not checked per page).
- Index only. There is no tab-by-id or tab-by-label lookup in the tab code. Tab indexes drift with conditional tabs (examples in 1.1).
- The mobile select (`tabs-select-<uniqid>`) mirrors the active tab (`syncSelect`, :411-433).
- Core Kompo `FormTabs` (non-swipeable) has `activeTab` but no URL handling (`vue-kompo/js/form/form-layouts/FormTabs.vue`).
- For swipeable tabs inactive panels stay in the DOM (translated/collapsed). For plain tabs `v-show` hides them (`FormTab.vue:4`). So an element inside an inactive tab is findable by id but not scrollable-to until its tab is selected.
- Programmatic select precedents: `[role=tab]` click by index (`AccountStatementElementsTrait.php:62-64`); the tutorial engine has mobile tab-dropdown handling (`js-kompo-utils/tutorials/modules/Mobile.js`).

### 2.3 Does `#hash` scroll?
- No app code reads `location.hash`. `grep` over `app/`, `resources/js`, `resources/views` finds only `scrollIntoView` calls tied to specific features: `app-scripts.blade.php:142,378`, `P2PPortalFirstSteps.php:76`, `BaseOnboardingForm.php:32`, and the `?section=` handler in `TeamSettingsPage.php:13-21`.
- Full page load: VERIFIED. `scripts/hash-test.mjs` (headless Chrome over CDP, `https://coolecto.local/`, 1280x800) navigated to `/#facts-title` (an element rendered by Vue at y=2573). Result: scrollY 0 at 300 ms, 907 at 1 s, 2550 at 3 s and 6 s. So the browser's native fragment scroll did work on a Kompo-rendered page (it lands ~23 px above, probably smooth scrolling). Setting `location.hash` after load also scrolled (2572). This was the public home layout (`layouts.home`), NOT the dashboard layout. Not tested: an authenticated dashboard page, or elements inside lazy panels.
- Not applicable to anything that arrives later than the load event: lazy tabs, panels, `selfGet` content. The browser gives up on a missing fragment.
- Fixed navbar: `DashboardNavbar` is `$fixed`; settings tab bar is `position: fixed` (`resources/scss/_Utils.scss:109-117`). A native hash scroll would put the target under them. No `scroll-margin-top` / `scroll-padding` rule exists in `resources/` (grep: none).
- Kompo SPA navigation: an `href('route')` to a route that has a layout is turbo-enabled automatically (`vendor/kompo/kompo/src/Elements/Traits/DoesTurboRefresh.php:24-36`). `v-turbo-click` does `new TurboClick(vnode, el.href).trigger()` (`vue-kompo/index.js:195-229`). Special case: if the link is `currentUrl#hash`, it returns and lets the browser scroll (:211-216). Otherwise `trigger()` runs `axios.get(url)`, replaces `body.innerHTML`, boots a new Vue, then `pushState(…, r.request.responseURL)` (`TurboClick.js:44,60-62,106`). `responseURL` carries no fragment, so the hash is dropped, and there is no scroll call. Query params stay (they are in the response URL).
- INFERRED, not tested: after a turbo nav the scroll position is whatever the replaced body gives.
- `noTurbo()` forces a full page load (`InscriptionCatalogPage.php:251` uses it with `->href(...)`), which restores native hash behaviour.
- How the chat renders its `link` chips (turbo or not) was not read (`vendor/condoedge/ai/src/Kompo/Support/MessageBubbleRenderer.php` presents them). OPEN QUESTION.
- Entry points that survive both modes: `?section=<id>` plus an `onLoad` JS (existing pattern) or `?tab_number=N`.

### 2.4 Running JS on page load
- `->onLoad(fn($e) => $e->run('() => {...}'))` (`TeamSettingsPage.php:13`, `BaseOnboardingForm.php:32`). Inline `<script>` in rendered Kompo does not execute (memory note). `app-scripts.blade.php` is a global script included by `layouts/app.blade.php`; it is loaded once per full page load, so global functions defined there are available after turbo too.
- So one global "scroll to `?section=`/hash and select its tab" helper in `app-scripts.blade.php` plus a single `onLoad` on the base page class is feasible. Not prototyped.

## 3. How tabs are used in these pages (summary)

| page | tab group | ids | lazy | index-drift risk |
|---|---|---|---|---|
| campaign.page.settings | `Campaign::getTabs()` (8 groups + experience) | `intro-*-tab` | no (inline) | high: groups with no steps are dropped |
| campaign.page.recipients | 6 | 5 of 6 | yes | yes: first tab conditional |
| campaign.page.communications | 3 | 3 | yes | no |
| campaign.page.products (tickets) | 3 | none | no | low |
| team.account-statement-new | 6 + N suppliers | container only | no | supplier tabs at the end |
| supplier.info | 5 | none | no | yes: Delivery tab conditional |
| supplier.account-statement | 3 | container only | no | no |
| dashboard (supplier) | tabs with `intro-supplier-*` | yes | partly | - |

## 4. Options to enumerate sections

Constraint recap: titles are mostly translation keys, but section sets are conditional on user and campaign.

### (a) Static scan of komponent source
Evidence:
- Steps on the settings page: 55 files with `$pageTitle = 'key'` and a `$tabGroup`, trivially regexable. Tab labels: `->label('key')` on `_Tab/_LazyTab` (62 `_Tab(` calls in 22 files). Page tables: `'title' => 'key'` props.
- 198 `miniTitle()/medTitle()/pageTitle()` calls and 17 `card-title` classes mark headings by style, not by contract. `_Html('key')->class('font-semibold text-lg')` is also a heading with no marker.

Pros: no runtime or credentials, fast, runs in CI, produces the superset (all steps). Cons: cannot tell which sections a user sees (conditions in `getSettingsSteps`, role, `isAdvanced`); dynamic titles are invisible (`groupsLabel()`, supplier names `AccountStatementNew.php:303`, `$this->vocab(...)` `CampaignPhaseGuidanceCard.php:562`); `render()` dispatch (DashboardView) needs manual knowledge; tab indexes are not computable (need the conditional logic). Heading detection by style is heuristic. Fits best: settings steps, recipients/communications tab labels, org.manage panels.

### (b) Runtime render
Two flavours:
- Instantiate komponents in PHP. Cost: constructors/`created()` call `currentTeam()`/`currentCampaignId()`, `abort(403)` for guests (memory: `currentTeam()` aborts for guests), `Campaign::findOrFail(currentCampaignId())` (`CampaignPageSettings.php:21`, `CampaignPageRecipients.php:17-21`), and `new Komponent()` runs `created()` only with `app('bootFlag')` on. It needs a fixture team/campaign per type, and lazy parts need extra `_execute-lazy` calls.
- Headless Chrome (works on this machine; `https://coolecto.local`, verified above for a public page): needs a logged-in session per audience (organisateur / fournisseur / participant / acheteur) and a current campaign per type, then reads headings and `[role=tab]` labels with their index, after waiting for the lazy XHRs. Gives exactly what the user sees including index drift. Cons: slow, flaky (lazy waits), needs seed data and credentials locally, produces a snapshot per fixture, and the local DB is the dev database (memory: tests run on it).

### (c) Explicit registry
Precedent in this repo: `resources/_tutorials/*.json`, one file per Komponent (`dashboard-view`, `campaign-page-settings`, `campaign-page-recipients`, `campaign-page-communications`, `team-organization-page1`, `supplier-dashboard-view`, `my-p2p-campaigns-table`, `step-1-internal-form`, ... 14 files), mapping selectors like `#intro-payment-behavior-tab` to text. And `config/ai.php` already is a registry for pages. Pros: author decides the anchor, covers dynamic titles, deterministic. Cons: drifts when pages change, someone must maintain it; ids on dynamic children still need to be in the markup.

### Hybrid suggestion (not decided)
Static scan proposes `(route, key, title_key, tab_key, conditional?)`. A committed registry file stores the reviewed result. A runtime check (headless or a test) validates that each id exists. Step 1 of any plan is a stable-id convention, because today ids are hand-made and can collide. See open questions.

## 5. `chemin:` vs real titles

Method: `scratchpad/chemin.py` (reads `resources/ai-help/aide/organisateur/*.md` frontmatter `chemin:`, splits on ` > `, strips `onglet|bouton|carte|menu` prefixes, exact case-insensitive match against all FR lang values `resources/lang/fr/*.php`). Result for all 320 organisateur articles:
- 1045 segments, 910 match some FR value (87%). By position: seg1 298/320, seg2 296/318, seg3 194/257, seg4 95/120, seg5 23/26.
- Caveat: "matches some value" does not mean "is shown as a heading on that page". Many values map to several keys (e.g. "Campagne" -> 3 keys). The table below checks the key against the page.
- Unmatched segments are gestures or hints: "cliquer sur un fournisseur", "menu à trois points", "bandeau de mise en place", "(un billet)", "Site de Coolecto", "colonne Statut", "ouvrir un courriel". Some contain several alternatives separated by " / " or " ou " (`Autres paramètres / Paramètres paiement`), which cannot be matched literally.
- Depth: 121 articles have 2 segments, 178 have 3, 113 have 4, 26 have 5, 4 have 6.
- Segment 1 is the sidebar group (Campagne / Finances / Mon organisme / Tableau de bord), segment 2 the page (`DashboardSidebar` link labels, `DashboardSidebar.php:321-348`), segment 3 a tab or card.

15-article sample (chosen across areas; keys and files verified):

| article | chemin | verdict |
|---|---|---|
| campagne-gestion-supprimer-ou-modele | Campagne > Modifier la campagne > Bases > Gestion de la campagne | OK all. `campaign.edit-campaign` (sidebar `:329`), `campaign.basics` (tab), `campaign.campaign-management-options` = `DeleteCampaignCard.php:9` (step added at `CampaignStepsTrait.php:219`) |
| campagne-nom | ... > Bases > Nom de la campagne | OK. `campaign.campaign-name` = `StepCampaignName.php:7` |
| campagne-raison | ... > Bases > Raison de la campagne | OK. `StepCampaignReason.php:9` |
| campagne-resultats-par-participant-ou-groupe | ... > Bases > Est-ce que vos résultats sont comptabilisés par participant ou par groupe? | OK, but conditional: `StepCampaignResultsRegistrationType.php:9`, only if `has_grades` (`CampaignStepsTrait.php:113`) |
| livraison-date-de-livraison-souhaitee | ... > Bases > Dates d'activité de ma campagne | OK. `StepCampaignDates.php:11` (supplier variant `StepCampaignDatesSupplier.php:10` same key) |
| produits-quantite-maximale-atteinte | ... > Livraison > Paramètres de produits | OK. Tab `campaign.delivery-settings` = const `TAB_PRODUCT_SETTINGS` (`Campaign.php:164`); card `StepCampaignMaxDaysBefore.php:7`; conditional (`CampaignStepsTrait.php:~202`) |
| produits-suggestion-au-paiement | ... > Paramètres paiement > Suggestion de produits supplémentaires | OK. `StepCampaignOrderBump.php:7` (tab group PAYMENT_BEHAVIOR; only for product sales `CampaignStepsTrait.php:~190`) |
| recus-recus-d-inscription-sportive | ... > Paramètres paiement > Reçus et reçus d'impôt | OK. `StepReceiptsAndTaxReceipts.php:7` |
| dons-en-honneur-anonyme-et-questions-au-donateur | Modifier la campagne > Autres paramètres > Questions additionnelles au donateur | OK (no "Campagne >" prefix). `StepCampaignNeedsAddress.php:7` |
| encan-rappel-sms-de-fin | Modifier la campagne > Autres paramètres > Rappel de fin de campagne | OK, encan only. `Messaging/CampaignEndSmsReminderCard.php:9` |
| participants-objectifs-des-participants-et-des-groupes | ... > Bases > Objectif de ma campagne (ou Paramètres de l'événement > Paramètres des objectifs en pair-à-pair) | Partial. First part OK (`campaign.campaign-goal`, `StepCampaignShowGoal.php:9`). Second says "...en pair-à-pair", real label is "Paramètres des objectifs" (`campaign.p2p-goals-settings`, `StepP2PGoalsSettings.php:7`). Alternatives in one string |
| communications-envoyer-maintenant-ou-programmer | Campagne > Communications > onglet Envois (ou Envois programmés) | OK with "(ou ...)" noise. Tabs `campaign.sendings` / `campaign.scheduled-sendings` (`CampaignPageCommunications.php:44-50`) |
| participants-lien-d-inscription-et-trousse-de-partage | Campagne > Participants > onglet Inscription | OK. Tab `campaign.inscription`, conditional and without id (`CampaignPageRecipients.php:47-52`) |
| participants-choisir-comment-ils-s-inscrivent | Campagne > Participants > « Comment vos participants s'inscrivent à votre campagne » | OK. Header card `campaign.how-participants-arrive`, id `intro-participants-mode` (`CampaignPageRecipients.php:116-130`) |
| groupes-equipes-et-capitaines-en-pair-a-pair | Campagne > Participants > onglet Équipes | OK for P2P only. Label comes from `groupsLabel()` -> `campaign.p2p-groups` = "Équipes" (`Campaign.php:1630`, `CampaignPageRecipients.php:58-62`); non-P2P label is `campaign.groups`, dynamic |
| rapports-releve-de-compte-solde-du-compte | Finances > Relevé de compte > Solde du compte | Matches text but not a section: `finance.account-balance` is a stat tile (`AccountStatementNew.php:249`) and a line in the amount explanations (:392). Same for "Profits nets cumulés" (`frais-calcul-du-profit`, `finance.net-profits`, :234,:370) |
| paiements-argent-recu-hors-coolecto | Finances > Relevé de compte > Ventes par méthode de paiement | OK. `finance.sales-by-payment-method` = `AccountStatementNew.php:434` (heading, no id) |
| compte-infos-de-l-organisme | Mon organisme > Mon organisme > Organisme | Segment 3 matches `register.organization` (`TeamInfoForm.php:14`, panel id `intro-organization-info`, `TeamOrganizationPage.php:15`); segment 2 duplicates the menu label |
| produits-ordre-d-affichage | Campagne > Produits > Mettre en ordre | Not a section: "Mettre en ordre" is the placeholder of a select (`campaign.products-order-by`, `OrderByColTrait.php:27`) |
| demarrage-bandeau-de-mise-en-place | Campagne > Tableau de bord > Phase 1 · Configuration | `campaign.phase-setup-eyebrow` (`NewOnboardingCard.php:80`), dynamic phase text; the card is `CampaignPhaseGuidanceCard` without id |
| recus-annuler-un-recu-d-impot | Finances > Reçus d'impôt > menu à trois points > Canceller ce reçu | Segments 1-2 are a page; 3-4 are a row action in a table (not a section; modal) |

Take-aways:
- Segment 3 is a heading when the page is a stack of cards (settings steps, org panels, recipients header, account statement) and a tab label when the page uses tabs. Both are translation keys that exist in code.
- Roughly half of the sample ends in something that is not an anchorable section (row action, select placeholder, stat tile, gesture).
- `chemin` text and UI can drift (see "Paramètres des objectifs en pair-à-pair"). Matching by exact string would miss those. The `Campagne >` prefix is sometimes dropped.
- One `chemin` may be an alternative list (" / ", " ou ", "(ou ...)"), which needs a splitting convention.
- A planner can resolve a `chemin` to a key by reversing FR (and EN) translations, since the same keys are used in the page code. Ambiguity exists (values mapping to several keys).

## 6. Facts that constrain a design

- A section id must be unique per page. The current practice breaks that (`campaign-form-panel` on every step; tab label + panel).
- Ids on settings steps could be generated from one place (`BaseCampaignForm::render()`/`cardHeader()` at `:57-86`), derived from the class or `$pageTitle`, covering about 55 step cards with a single edit. Not so for pages built ad hoc (promote, account statement, products).
- Tab selection from an anchor needs a lookup by id/label to index. Today: index only, from the query string.
- Landing under a fixed navbar needs a `scroll-margin-top`.
- Conditional sections: the button must not point to a section the user does not have. The existing `authorize` gate in `config/ai.php` is page-level only (`NavigationGates`).
- The existing `?section=` + `onLoad` pattern and `?tab_number=` survive turbo navigation; `#hash` does not (without `noTurbo()`).

## 7. Open questions

1. Does the chat's `link` chip render as a turbo link or a full load? (decides whether `#hash` is enough). Check `MessageBubbleRenderer` / the presenter for `href`.
2. Does `#hash` scroll correctly in the authenticated dashboard layout (inner containers, fixed navbar offset)? Verified only on `layouts.home`.
3. Are duplicate ids actually present for `_Tab()->id()` (label + panel)? Inferred from source; needs a DOM check.
4. Which pages have two tab groups sharing `tab_number`? Not enumerated.
5. How should alternative-list `chemin` values be split, and should `chemin` be machine-readable going forward (a second frontmatter key such as `section:`)?
6. What audiences/fixtures would a runtime planner need (4 audiences x campaign types), and is the dev database acceptable for it?
7. Which of the 27 pages are worth anchors? The ones with real sections: settings, recipients, communications, promote, account_statement, org.manage, supplier_statistics, supplier_business, dashboard (cards). The rest are one table or one form.

## Files produced during research
- `scratchpad/chemin.py` (chemin vs FR lang matcher). `scratchpad/hash-test.mjs` (headless hash-scroll test). `scratchpad/chrome-prof/` (Chrome profile, can be deleted).
