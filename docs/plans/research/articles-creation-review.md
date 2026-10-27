# Review sheet: campaign-creation help articles (Phase 4, batch 4a)

For the KB owner. The articles are in French in `ActiviteDeFinancement/resources/ai-help/aide/organisateur/`; this sheet
says in English what each one claims. Every claim is traced to code in `articles-creation-facts.md` (same folder).
Nothing is ingested yet: no Qdrant, no eval until you approve.

**Result:** of the 10 planned articles, 8 files written, 1 merged, 1 dropped. No existing article was edited.
No fee rate, percentage or price appears in any of them.

---

## 1. `choisir-le-type-de-campagne` — "Choosing the campaign type for your project" — NEW

- The type is picked once and cannot be changed later (a copy keeps the same type), so start from what you offer people.
- A situation → type list: selling products → Product sale; a meal on one or several dates → Benefit meal; entry to a
  show, dinner or evening → Ticket sale; giving with nothing in return → Donations; each participant raises money from
  their circle on their own page → Peer-to-peer; donors sponsor a measured performance → Effort incentive; questions →
  Form; courses/activities with groups and schedules → Registrations; lots auctioned → Auction; several campaigns toward
  one goal → Campaign group.
- Raffle and Membership are not offered at creation (raffle is set up with the Coolecto team); a supplier-reserved account
  sees only Product sale or padlocks; Campaign group is absent when creating a sub-campaign.
- What the type decides: starting questions, silent defaults, tax-receipt eligibility, setup cards.
- FAQ: changing the type (no), combining activities (Campaign group), Coco recommends but you choose.

Why new: `demarrage-types-de-campagnes` lists the types and their questions but never says which one fits a situation.

## 2. `comparer-dons-pair-a-pair-incitation` — "Donations, Peer-to-peer or Effort incentive: which one" — NEW

- All three collect gifts; they differ by the participants' role and when the donor pays.
- Donations: pays at once; three suggested amounts created; no participant list at creation (can be added later);
  asks the campaign's reason, which is shown to donors and used to write the emails; monthly recurring gifts possible.
- Peer-to-peer: each participant or team raises money on its own page; registration with fees per team type,
  pre-registration/registration periods and an event date; "Compléter ma campagne" also needs team types and team sizes.
- Effort incentive: donors pledge and pay later; results entered afterwards; born with a participant list, results
  entry and personal-link-only access.
- In common: tax-receiptable by default, "suggested amounts" menu, no added-donation card, cover fees offered.

## 3. `comparer-repas-benefice-et-billets` — "Benefit meal or Ticket sale: which one" — NEW

- Meal: people order a meal; three choices quoted with their sub-texts; "single date with tickets" actually creates a
  Ticket sale; the two "with list" choices create a participant list; multi-date adds the important-dates card.
- Tickets: General admission or Assigned seats (sub-texts quoted); scanning menus; added donation is on from creation.
- Differences: ordering per date (meal only), QR entry (tickets only), Tap-to-Pay (meal and general admission, not
  assigned seats), sport receipts (tickets only), per-item tax-receipt amount (both).
- FAQ: weekly pizza lunches → Meal, several dates; "why is my meal a ticket sale"; choosing seats → Assigned seats.

## 4. `comparer-formulaire-et-inscriptions` — "Form or Registrations: which one" — NEW

- Form: questions built in the Form menu, payments through paid choices, optional tax-deductible setting.
  **States that a Form is created with a participant list, so the add-participants card is required** (see question 1).
- Suggests adding a form to an existing campaign instead when you already sell something.
- Registrations: the five creation questions quoted; created at once with a guided setup; registrants declared by the
  family; instalments; never goes live alone ("Mettre en ligne").
- Differences: groups/schedules/places, added donation and cover fees (Form yes, Registrations never), sport receipts,
  how the tax receipt is triggered.

## 5. `comparer-encan-et-autres` — "Auction, raffle, membership and campaign group: the special types" — NEW

- Auction: lots, bids, winners pay at the end (payment request or saved card); creation questions; settings card;
  added donation and per-lot tax amount available.
- Auction vs raffle: best bid wins vs numbers drawn at random; raffle is staff-only, needs an RACJ licence.
- Membership: not offered at creation, contact the Coolecto team.
- Campaign group: the four kinds with their sub-texts; "Event" also creates two ticket sub-campaigns ("Premier versement",
  "Paiement final"); participant goal and link registration for Event/Travel/School year.
- FAQ: Coco cannot create a raffle or membership; a gala mixing activities → Campaign group.

## 6. `consequences-par-type-recus-d-impot` — DROPPED

`recus-envoi-automatique-des-recus` already says, per type, what is tax-receiptable (its section "Ce qui donne droit au
reçu d'impôt" matches the code), and `recus-activer-les-recus-d-impot` covers the organisation prerequisites.

## 7. `consequences-par-type-frais-et-don-supplementaire` — MERGED into 8

Two short per-type facts (which types have the added-donation card, which have cover fees) sit better next to the
creation defaults; the details already live in `frais-couverture-des-frais-par-les-acheteurs` and
`dons-don-supplementaire-et-don-sans-achat`.

## 8. `valeurs-par-defaut-a-la-creation` — "What creation sets automatically per campaign type" — NEW (includes 7)

- Every new campaign: offline; no dates (except Registrations); no emails until the emails card is opened; platform rate
  comes from the organisation, not editable; cover fees off; automatic receipts on; profit share off unless "yes".
- Per type: own-product sale gets added donation on and orders blocked one day before delivery; supplier product sale
  copies products, prices and emails; tickets get added donation on; meal "with list" and Form get a participant list;
  Donations/P2P/Incitation get three suggested amounts; Incitation also list + results + personal links; Registrations
  ask the payer's phone (and school level when a parent fills); Event group creates two sub-campaigns; Auction nothing more.
- Added donation: which types have the card; it starts with a default message and no amounts, hence the optional card.
- Cover fees: every type but Registrations; absent on a tips-only campaign (set by Coolecto staff); disabled by a network.

## 9. `regles-de-reseau-a-la-creation` — "Creating a campaign when your organisation belongs to a network" — NEW

- What a parent can impose: approval (required card, campaign stays offline), budget number (optional card, not on a
  main campaign), cover fees disabled, deposits to the parent (bank card hidden).
- Parent templates: copied with sub-campaigns from the type screen; Coco does not copy them.
- Organisation opened by a travel agency: one main campaign, new campaigns (except Forms) go under it, a second group refused.
- Coco knows the network's rules, does not ask what is imposed, and marks it "Imposé par votre réseau" in the summary.

Why new: `compte-politiques-du-reseau` is written for the parent's settings page; nothing tells the child organisation
what to expect when it creates a campaign.

## 10. `creer-sa-campagne-avec-coco` — "Creating your campaign with Coco" — NEW

- Who: the "Créer ma campagne avec Coco" chip, when the feature is on; not for Member or Ticket scanner roles, a suspended
  organisation, or supplier mode.
- What Coco asks: the fundraiser's goal (not saved), the type (recommended), the type's wizard questions, the name, then
  one offer of the optional settings (dates, goal, donation amounts, cover fees, receipts, contact email, use of profits,
  leaderboard), each only when it applies; supplier template path with the supplier's conditions.
- What it never sets: platform rate or supplier fees, going live, bank, emails, participants, product prices, raffle,
  membership, copies or parent templates, network rules.
- Summary: source per row; "Modifier"; money and legal rows each need "Je confirme cette valeur" before "Confirmer"
  (lists which rows); "Annuler" creates nothing; role, rules and limits re-checked at confirmation.
- After: the campaign is created offline; the setup band puts it live (Registrations: button).
- Limits: 3 campaigns per person and 10 per organisation per 24 h; a conversation stops after 24 h or 40 exchanges; then
  the usual form. Every step is recorded.

---

## Slug changes for `CreateCampaignTask::knowledge()` (controller)

`CreateCampaignTask::ARTICLES` (`app/Ai/Tasks/CreateCampaignTask.php:45-56`):
- remove `consequences-par-type-recus-d-impot`, add `recus-envoi-automatique-des-recus`;
- remove `consequences-par-type-frais-et-don-supplementaire`, add `frais-couverture-des-frais-par-les-acheteurs` and
  `dons-don-supplementaire-et-don-sans-achat`;
- the docblock above it ("not written yet") is now stale.

Suggested, your call: the task's retrieval only sees these slugs, so Coco cannot cite `demarrage-types-de-campagnes`
(the per-type questions), `billets-types-de-campagnes-de-billets`, `catalogue-choisir-un-fournisseur-a-la-creation` or
`demarrage-mise-en-ligne` during a creation. Adding them would ground answers to "what does this question mean?".

## Proposed changes to existing articles (not made)

1. `encan-parametres-de-l-encan`: the card is « Paramètres de l'encan » (`campaign.auction-settings`), not « Paramêtres »
   (title line, body and `chemin`).
2. `formulaires-creer-un-formulaire`, step 3: the "closed group" answer is never read (`closed-campaign` is not mapped in
   `Onboarding::campaignFromAnswers`), and every Form is created with a participant list, so the participants card is
   required. Depends on question 1.
3. `demarrage-types-de-campagnes`: for Auction and Ticket sale the wizard also asks profit sharing (when there are
   participants) and the goal; the Form's closed-group question is the one from point 2.
4. `produits-parametres-de-produits-par-date`: "Valeur par défaut : 0" is true for meals; a product sale created with
   "+ Campagne" or Coco starts at 1 day.
5. `dons-don-supplementaire-et-don-sans-achat`: the card is also absent for Peer-to-peer and Effort incentive, not only
   Donations.
6. `recus-envoi-automatique-des-recus`: per-item tax amounts apply to tickets except raffles ("billets (hors tirage)").

Outside the KB, FYI: the public type guides (`lang/fr/website.php`, `guide-type-*`) were used only for situation examples
("spectacle, souper, soirée", "commandite une performance"). Two of their step claims look contradicted by the code: the
P2P guide says the wizard asks who absorbs the fees (only asked for sub-campaigns of a group) and calls the participants
card critical for P2P (P2P is created without a participant mode).

## Open questions for you

1. Form created with a participant list (required card) while the wizard asks "closed group?" and ignores the answer:
   intended, or a bug to fix? Article 4 describes today's behaviour; one sentence to remove if the code changes.
2. May the articles state the default suggested amounts (they are prices of default donation products, not fees)? I left
   the figures out.
3. Is quoting the public website guides as a source for situation examples acceptable?
4. Article 10 does not mention the 60-model-call cap (internal). Keep it out?
