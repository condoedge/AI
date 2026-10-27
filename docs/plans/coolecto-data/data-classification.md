# Coolecto data classification for graph ingestion

Research date: 2026-10-26. Read-only. Source: local DB `financement` (a copy of prod). Column fill rates come from one aggregate query per table: `SUM(col IS NOT NULL AND col <> '' / <> 0)`. No values were read.
"filled=N" = rows where the column is non-null, and non-empty for strings or non-zero for numbers. A `0`/`false` flag therefore counts as empty.
Metric ids (M1…M30) refer to `metrics.md` in this folder. Code paths are relative to `ActiviteDeFinancement/`.

Classes:
- **(a) NEEDED**: required for a metric or a likely staff question.
- **(b) SENSITIVE**: never ingest. Personal data, minors' data, free-text answers, secrets, tokens, payment/Stripe identifiers.
- **(c) NOT NEEDED**: settings, UI, plumbing. Harmless but no use case. Leave out until a question needs it ("no capability without a use case").

A foreign key to a person table (`customer_id`, `user_id`, `payer_customer_id`) is classed (a) **only as an opaque id** for "distinct donors" counts. It must never be joined to a person node that carries (b) columns.

---

## 0. Volumes, tenant paths, graph implications

| table | rows | tenant path to `team_id` | notes |
|---|---|---|---|
| campaigns | 4,116 (1,379 teams, max 284/team) | `campaigns.team_id` (100 % filled) | + `users.restricted_campaigns[team]` limits campaigns per user (HasRestrictedCampaignsTrait.php:14-23); network parents see children (`Team::getReportTeamIds`, Team.php:638-641); suppliers reach other teams' campaigns through `products → deliverables.supplier_id` |
| orders | 201,764 (paid statuses 189,594; max 8,530/team) | `orders.campaign_id → campaigns.team_id` | no `team_id` column; 0 orphan orders; `landing_campaign_id ≠ campaign_id` on 22,179 (multi-campaign checkout): scope by `campaign_id` |
| order_details | 501,434 (499,960 on paid orders) | `order_details.order_id → orders.campaign_id → campaigns.team_id` | 69 orphan lines (no order) |
| products | 49,021 | `products.campaign_id → campaigns.team_id` | 15,832 are variants (`product_id` set, hidden by `WithoutVariantsScope`, Product.php:85-88); supplier side: `products.supplier_id`/`deliverable_id` |
| recipients | 169,509 | `recipients.campaign_id → campaigns.team_id` | `recipients.team_id` filled on only 73,781 and differs from the campaign's team on 3: **don't use it**; 319 recipients point to a missing campaign |
| groups | 13,542 | `groups.campaign_id → campaigns.team_id` | `groups.team_id` filled on 3,598, 28 mismatches: **don't use it**. Table name needs backticks in MySQL (`groups` is reserved) |
| customers | 125,197 (11,658 soft-deleted) | `customers.team_id` (124,349 filled) | 23 orders link a customer of another team than the campaign's |
| tax_receipts | 7,846 | `tax_receipts.team_id` (100 %, 0 mismatches with order→campaign team) | `forThisCampaign` goes through `order_id` |
| transfers | 4,269 | **`transfers.team_id`** | ≠ campaign team on 613 rows (supplier payouts), `destination_team_id ≠ team_id` on 788: **never scope transfers via campaign** |
| reservations (tickets, draw numbers) | 236,574 | `reservations.campaign_id → campaigns.team_id` | |
| seats | 12,064 | `seats.row_id → rows → sections → venue_plans → venues` (venue-level, not team) | not needed |
| pledges (auction bidders) | 1,857 (55 soft-deleted) | `pledges.campaign_id → campaigns.team_id` | |
| pledge_amounts (bids) | 3,872 | `pledge_amounts.pledge_id → pledges.campaign_id` (or `product_id → products.campaign_id`) | |
| prizes / draws | 168 / 48 | `prizes.campaign_id`; `draws.prize_id → prizes.campaign_id` | `drawings` = 0 rows, `tickets` = 0 rows (draw tickets are reservations) |
| draw_logs | 3,230 | `draw_logs.campaign_id` | regulatory audit log |
| answers / answer_polls / polls (forms) | 48,614 / 148,081 / 104,096 | **no team key**: `answers.answerable_type` = order 42,583, recipient 2,960, donor_registration 761, customer 53, unknown 2,257; `answers.order_id` is 0 % filled | forms are out of scope (see §10) |
| donors (invitations) | 266,069 | `donors.campaign_id` | emails of invited people; not needed |
| main_campaigns | 187 | via either campaign | needed for rollups (M25) |

**Graph volume.** Nodes for every order line (≈ 500k) plus every order (≈ 200k) and their edges come to about 1.4M relationships for numbers the app aggregates anyway. Options for the owner:
- **(1) Order-only (recommended to discuss).** Order nodes carry precomputed signed sums per line kind (`net_amount`, `covered_fees_amount`, `donation_amount`, `voucher_amount`, `items_qty`) and an edge to Campaign. Campaign nodes carry the app-computed metrics through `graph.computed`. About 200k nodes; line-level questions ("how many units of product X") go through a pre-aggregated `(:Campaign)-[:SOLD {qty, amount}]->(:Product)` edge.
- **(2) Full lines.** Order lines as nodes with `product`/`recipient` edges. Most flexible, about 3× the size, and every sum must repeat the status/flag/valid rules in Cypher.
- **(3) Campaign-only facts.** No orders at all: only "per campaign / per participant / per group" questions.

Participants (169k) and groups (13.5k) are small enough as nodes. Their totals should be computed by the app (`total_donated`, `goal`, `pctGoal()`), not re-derived (the group-donation split, M22-M23).

---

## 1. campaigns (4,116 rows, 201 columns)

**(a) NEEDED**
- Identity/scope: `id` (4116), `team_id` (4116; tenant), `campaign_type_id` (4116; 1 deliveries, 2 donation, 3 product, 4 form, 5 tickets, 6 cheer-up, 7 auction, 8 P2P, 9 membership, 10 main, 11 registrations, Campaign.php:90-100), `name` (4116; translatable; may contain a person's name in memorial campaigns, see note), `supplier_id` (1362; supplier-built campaign), `survey_campaign_id` (570; B(C)/R(C) scope, §1.5), `main_campaign_id` (39; legacy, the rollup uses table `main_campaigns`), `from_campaign_id` (1882; copied from/template), `is_template` (60), `owner_team_id` (2424), `redirect_campaign_id` (42), `main_type` (72; event/travel/wrapper/syncable).
- Status and dates (the status is computed, there is no status column; Campaign.php:856-869; phases CampaignChecksTrait.php:37-60): `enabled` (2937), `paused` (33), `start_date` (2762), `end_date` (2762), `created_at` (4116), `accounting_closed` (161), `registration_start_date`/`registration_end_date` (6).
- Money rules (M1-M12, §1.11): `rate` (3722), `cover_fees` (537), `forced_cover_fees` (78), `pay_fixed_fees` (32), `fees_paid_by_recipients` (18), `profit_share` (431; varchar %), `precalculated_profits` (1191; cache written by reading `profits`, may be stale), `count_inscriptions_amount_in_totals` (0; P2P registrations in totals), `sub_pays_balance` (28), `participants_metric` (17).
- Goals (M21-M23): `goal` (1403), `goal_type` (4112; 1 total, 2 profit), `show_goal` (1102), `group_goal` (21), `participant_goal` (91), `default_group_goal` (4), `default_recipient_goal` (4), `force_group_goal` (0), `force_recipient_goal` (0), `allow_personal_goal` (0), `add_participant_and_group_goals` (77), `results_type` (23; per group / per participant).
- Type-specific: `ticketing_type` (544; 1 draw, 2 QR, 3 venue), `drawing_type_id` (161; 1 prize pool, 2 50/50), `max_prize_amount` (2), `has_recipients` (1079), `delivery_type` (4116), `has_seats` (60), `venue_id` (52), `multiple_dates` (292), `current_campaign_date_id` (69).

**(b) SENSITIVE**
- `contact_email` (2304) and `contact_info` (1683): an organiser's personal email/phone.
- `receipt_copy_email` (172).
- `delivery_contact_name` (462), `delivery_contact_phone` (452), `delivery_address` (420; JSON).
- `venue_address` (392) and `event_address` (3): can be a private home.
- `scanner_attach_token` (8): **secret** that attaches a scanner device.
- `qr_code` (3814): public-link token.
- `draw_licence_number` (12): regulatory licence, not personal but not needed. Kept in (b) to be safe.

**(c) NOT NEEDED**
- Text/UI: `description` (2586), `donation_text` (1602), `donation_options` (1197), `button_label_page`/`button_label_order` (1809), `custom_amount_label`/`custom_amount_icon` (6), `instructions_text` (102), `membership_text` (5), `participants_message` (19), `paused_message` (18), `badge_text` (77), `donation_without_purchase_btn_text`/`_modal_text` (843), `rules_text` (9), `calendar_details` (3), `description_details` (387), `direction_details` (2), `invoice_comment` (140), `reminder_end_message` (14), `reason_description` (1336), `personalized_participant_register_page_message` (19), `registration_sidebar_text` (4), `delivery_more_info` (285).
- Look: `main_color` (2605), `secondary_color`/`tertiary_color` (992), `custom_logo` (1133), `custom_photo` (2114), `photo_id` (431), `alternative_products_design` (84), `products_sort_by` (185), `is_visible` (3644), `display_donors` (24), `show_latest_donations` (4098), `show_total_donated_only` (126), `show_product_cost` (20), `display_name_of_highest_bidder` (30), `mute_groups` (81).
- Behaviour switches: `user_id` (4116; creator), `language` (4116), `no_contact_info` (77), `max_days_before` (437), `remind_before` (20), `onboarding_completion_seen_at` (328), `welcome_seen_at` (4062), `welcome_completed_at` (4057), `dismissed_image_suggestion` (0), `updated_at`, `referal_email` (3231), `referal_fb` (3219), `max_per_recipient` (127), `venue_date` (6), `has_qr_code` (732), `onboarding_id` (2997), `add_donation` (1603), `has_grades` (27), `has_venue` (426), `has_qr_code_campaign` (1207), `has_qr_code_recipients` (1269), `is_demo` (0), `no_communications` (893), `address_needed` (261), `ask_in_honors_of` (76), `open_ordering` (93), `enable_order_bump` (10), `upsell_minimum_cart_amount` (0), `donation_without_purchase` (1101), `seat_selection` (52), `on_hold` (0), `reserve_many_dates` (6), `phone_needed` (34), `single_outcome_per_recipient` (13), `is_p2p` (0), `draw_licence_expires_at` (7), `send_ics` (99), `multi_products` (19), `only_with_invitation_code` (831), `minimum_age` (132), `membership_ask_info` (4), `membership_ask_renewal` (6), `one_choice_all_dates` (13), `mandatory_all_dates` (12), `ask_donation_is_in_the_name_of_company` (213), `ask_donor_be_anonymous` (94), `ask_donor_amount_anonymous` (22), `ask_donor_want_to_subscribe_to_newsletter` (164), `cover_fees_default_toggle_on` (93), `max_total` (137), `round_up_ad` (534), `round_up_value_ad` (537), `locked_dates_and_prices` (85), `send_tax_receipt_automatically` (4093), `ask_donor_phone` (302), `ask_credit_card` (23), `accept_donations_from_someone_else` (111), `import_id` (0), `send_receipt_automatically` (4088), `allow_bids_automation` (24), `max_groups_allowed` (2), `max_recipients_allowed_per_group` (4), `min_recipients_allowed_per_group` (4), `group_registration_fee`/`recipient_registration_fee` (1), `preregistration_start_date`/`_end_date` (3), `send_qr_automatically` (4034), `enable_terminal` (85), `allow_draw_date_to_be_manually` (53), `reminder_end_minutes_before` (14), `reminder_end_job_id` (14), `custom_donation_enabled` (4115), `custom_recurring_donation_enabled` (4115), `preferred_date_of_delivery` (538), `show_message_tax_sending_later` (1), `max_quantity_per_date` (23), `accept_zero_qty` (30), `dont_open_keyboard_in_select` (9), `accelerate_ttp_transaction` (37), `recipient_registration_sent_at` (198), `min_age_to_p2p_inscription` (1), `ask_link_to_recipient` (68), `do_not_allow_closed_groups` (2), `force_donation_to_groups_or_recipients` (1), `ask_for_additional_message` (25), `min_start_date`/`max_start_date`/`max_end_date` (41), `emit_sport_receipts` (0), `onboarding_without_groups` (1), `communications_reviewed_at` (11), `ai_comm_generation_plan_already_executed` (4), `ai_comm_generation_plan_executing` (1), `ai_comm_generation_plan_started_at` (5).
- Accounting plumbing: `account_name` (2271), `account_code` (2452).
- Dead or unused: `currency_id` (0; single currency CAD), `fees_covered_by_supplier` (17; **disconnected column**, migration 2022_10_28_113253:14), `opening_balance` (3; written, never read, M29).

Note on `name`: campaign names are needed for every answer. They are organisation-chosen titles but can name a person (e.g. a memorial fund). The owner should accept that risk explicitly.

---

## 2. orders (201,764 rows, 83 columns)

Status distribution and meaning: metrics.md §1.2. `manual_payment` holds a payment-method id (§1.6).

**(a) NEEDED**
- `id` (201764), `campaign_id` (201764; tenant path), `status_id` (201764), `created_at` (201764), `effective_date` (189886; payment date, M30/§1.8).
- Amounts: `final_amount` (163478), `val` (163551), `fees` (149887; card fee charged), `real_fees` (148263), `net` (160544).
- Links: `refunded_id` (4224; refund → original), `disputed_id` (277), `main_order_id` (186644; experience/recurrence chain), `prev_order_id` (22095), `subs_order_id` (323), `tip_id` (1052), `landing_campaign_id` (183519), `pledge_id` (1215; auction), `voucher_id` (1677), `bill_id` (13425), `order_schedule_id` (0), `replacement_order_id` (0).
- Flags: `manual_payment` (10677; method id, M2/M6), `covered_fees` (20373; buyer choice 1 fees / 2 tips), `recurrence` (424; monthly/yearly), `p2p_registration` (1224; M20), `deliver_to_donor` (121), `mark_delivered` (170), `want_membership` (73), `expires_at` (243), `start_from` (1), `canceled_at` (5) (membership M19), `tickets_created` (28308), `sharing_src` (48045; share channel enum, for "where do sales come from"), `payment_provider` (201764), `terminal_id` (109) and `scanner_id` (769) (in-person sales).
- Opaque person keys (distinct counts only, never joined to person data): `customer_id` (185668), `payer_customer_id` (131776), `user_id` (1426).
- Anonymity flags, needed to honour donor anonymity in answers: `donor_be_anonymous` (680), `donor_amount_anonymous` (17).

**(b) SENSITIVE**
- `email` (187989), `phone` (23004), `ip` (192485), `card` (159835; card brand/last digits), `company_name` (2564; buyer's company).
- Stripe and payment ids: `stripe_id` (167412), `stripe_pmt_id` (168924), `stripe_payment_intent_id` (131689), `subscription_id` (372).
- Tokens: `signature` (192465; order access token), `verification_code` (2567), `invitation_id` (138143; links the buyer to a donor/participant invitation).
- Someone else's details: `is_order_for_someone_else` (1086), `someone_else_name` (1085), `someone_else_company` (0), `someone_else_email` (1085), `someone_else_address` (781).
- Free text: `honors_choice` (65), `honors_of` (65), `honors_message` (63) (in-memory-of names), `given_recipient` (1359; typed participant name), `additional_message` (2124), `dispute_reason` (39), `refund_reason` (754), `manual_payment_note` (4).
- Age check: `age_verified` (0), `age_verified_at` (0), `age_verified_ip` (0).
- `sharing_src_code` (33231): a share code that identifies the sharer.

**(c) NOT NEEDED**
`updated_at`, `receipt_sent` (147335), `stripe_recon` (135287), `donor_want_to_subscribe_to_newsletter` (562), `fix_fee_sync_issue` (0), `donation_intent_id` (0), `import_id` (8566), `sms_notification_at` (325), `currency_id` (0), `reserved_until` (63655; cart lock), `conversion_tracked_at` (4), `locale` (4).

---

## 3. order_details (501,434 rows, 47 columns)

**(a) NEEDED**
- `id`, `order_id` (501365), `product_id` (429091), `recipient_id` (421069; participant credit), `group_id` (231; group-level donation, M22), `campaign_date_id` (374426).
- Line kind/state: `valid` (501295; §1.3), `donation_flag` (35148; §1.4).
- Amounts: `quantity` (500685), `total_amount` (468581), `discount_amount` (69), `custom_amount` (49843), `total_cost` (268502), `item_fees` (432153), `real_item_fees` (431014), `coolecto_amount` (455677), `od_rate` (260678), `fees_team_id` (500318), `supplier_responsability` (82207), `included_in_balance` (466901).
- Links: `voucher_id` (307), `product_voucher_id` (17), `applied_voucher_id` (63), `reservation_id` (15652), `answer_id`/`poll_id` (31749; only to apply the "survey line" rule of `products_sold`, M15; do not ingest the answers), `dimension1_id` (19931), `dimension2_id` (22877) (variant size/colour), `installment_of_id` (0), `replacement_od_id` (0), `created_at`.

**(b) SENSITIVE**
- `gift_to_name` (0), `gift_to_email` (0), `gift_message` (0): gift recipient.
- `reservation_qr` (630): ticket QR secret.
- `description` (501085): free text built from the product name plus the participant/group name ("… -> Team", `paymentLineName`, OrderDetail.php:773-783), and refund/dispute prefixes. It may embed minors' names. Use `product.name` instead.

**(c) NOT NEEDED**
`team_bill_item_id` (461217), `supplier_bill_item_id` (89236), `choice_id` (0), `old_reservation_id` (653), `discount_label` (75), `refunded_stock` (83), `import_id` (8566), `upsell_source` (38), `full_amount` (0), `at_eligible_price` (0), `updated_at`.

---

## 4. products (49,021 rows, 65 columns)

**(a) NEEDED**
- `id`, `campaign_id` (49021), `product_id` (15832; variant parent, exclude variants when listing products), `name` (49021), `price` (48233), `unit_cost` (42522), `supplier_id` (40849), `deliverable_id` (40849) (supplier catalogue item; fee coverage, M7).
- Type flags: `recurrence` (332), `equivalent_units` (948; tickets per unit, M16), `has_fixed_price` (76; auction), `minimum_bid` (0), `minimum_increment` (1538), `reserve_price` (226), `tax_receipt_amount` (191), `do_not_emit_tax_receipt` (29), `taxable_amount` (15), `ticket_type` (71).
- Stock: `stock` (459), `initial_stock` (345), `can_be_out_of_stock` (104).
- `is_gift_certificate` (0), `eligible_price` (0), `created_at`.

**(b) SENSITIVE**
- `contact_name` (0), `contact_email` (0), `contact_phone` (0) (registration product contact).
- `stripe_price_month` (20), `stripe_price_year` (1), `stripe_price_custom` (8): Stripe ids.

**(c) NOT NEEDED**
`description` (33140), `introduction` (22087), `order` (17159), `terms_accepted` (40847), `dimension1_type` (14403), `dimension2_type` (14614), `fee_type` (195), `fee_amount` (123), `has_many_prices` (0), `sponsor_id` (1211), `has_matrix` (4896), `advise_when_stock_lower_than` (17), `duration_in_days` (10), `start_from` (1), `stripe_price_interval_count` (41), `is_fixed_item` (20), `dimension1_id` (13135), `dimension2_id` (14792), `available_dimensions1_ids` (3754), `available_dimensions2_ids` (4487), `hide_for_public` (2823), `is_bump_product` (120), `do_not_generate_qr` (104), `max_product_per_recipient` (316), `hide_stock` (34), `min_level`/`max_level` (0), `duration_hours` (0), `prerequisites` (0), `external_registration_url` (0), `registration_opens_at`/`registration_closes_at` (0), `show_when_registration_closed` (49021), `on_own_page` (49021), `updated_at`.

---

## 5. recipients = participants (169,509 rows, 39 columns). Often minors.

**(a) NEEDED**
- `id`, `campaign_id` (169190), `group_id` (156198), `group2_id` (25659), `main_recipient_id` (18238; same person across sub-campaigns).
- Goals and status: `personal_goal` (0; M23), `was_disabled` (225; active participants), `inscription_order_detail_id` (780; P2P registration paid, `onlyRegistered`), `shares_profits` (0), `created_at` (168488).

**(b) SENSITIVE (minors)**
- Names: `first_name` (168991), `last_name` (168876). **Owner decision D9**: the app shows them to staff, but they are mostly minors.
- Minors' details: `date_of_birth` (918), `gender_id` (928), `school` (1115), `school_level` (1117), `declared_grade` (1115), `file_number` (1114), `personal_pic` (287), `personal_desc` (1326).
- Links to other person data: `primary_phone_id` (919), `primary_billing_address_id` (917), `primary_shipping_address_id` (917), `user_id` (932).
- Tokens and external ids: `invitation_id` (169509; signed-dashboard identity token), `ext_id` (1216; external system id).

**(c) NOT NEEDED**
`updated_at`, `import_id` (1656), `single_result` (0), `invited_at` (622), `same_person_as_id` (0), `team_id` (73781; unreliable, see §0), `survey_order_id` (836), `inscription_price` (0), `added_by` (42160), `modified_by` (43389), `group_blocked` (810), `order_index` (136845), `welcomed_at` (932).

Totals for participants must come from the app (`total_donated`, `getCampaignTransactionsInfo`, `goal`, `pctGoal()`): metrics.md M23.

---

## 6. groups (13,542 rows, 27 columns)

**(a) NEEDED**
- `id`, `campaign_id` (13409), `name` (13542; class/team name, usually not personal, but a family or child name is possible), `goal` (116), `participant_goal` (115), `group_type_number` (13538; group 1 vs group 2 axis), `main_group_id` (1209), `p2p_group_type_id` (118), `nb_participants` (145), `opened_inscriptions` (118), `created_at` (13437).

**(b) SENSITIVE**
- `leader_id` (109): the group leader is a person (Donor).
- `invitation_grp` (12923): group invitation token.
- `public_img` (91): uploaded photo.

**(c) NOT NEEDED**
`description` (408), `updated_at`, `opened_to_inscriptions` (0), `inscription_order_detail_id` (0), `team_id` (3598; unreliable), `display_p2p` (8), `added_by` (4242), `modified_by` (4287), `is_special_group` (1), `show_campaign_total_in_profile_p2p` (1), `ext_id` (34), `inscription_location_id` (0), `schedule_type` (0).

---

## 7. customers = buyers/donors (125,197 rows, 57 columns). Personal by nature.

**(a) NEEDED** (only for anonymous counting: "how many donors", "new vs returning")
- `id`, `team_id` (124349), `created_at` (123410), `deleted_at` (11658), `is_company` (2036).
- Opt-in state, only if a "subscribed donors" question is wanted: `join_newsletter` (3666), `is_subscribed` (6475), `unsubscribed` (1094).
- `nb_trx_ltd` (0) and `amount_trx_ltd` (0) would be needed but are **empty**; compute from orders.

**(b) SENSITIVE**
- Identity: `email` (119827), `name` (121690), `first_name` (85417), `last_name` (82561), `company_name` (6170), `function` (566), `website` (1).
- Contact: `phone` (20269), `phone_number` (1881).
- Address: `line1` (12716), `line2` (1607), `postal_code` (54098), `city` (12729), `state` (14765), `country` (55841), `lat` (36), `lng` (36).
- Demographics: `date_of_birth` (187), `gender_id` (572), `salutation_id` (2877).
- Tracking: `ip` (76940).
- Payment: `stripe_customer` (33001), `payment_method` (710), `pmt_method_last_checked` (496).
- External ids: `provider_id` (0), `external_id` (10495), `old_customer_no` (12374).
- Links to other person data: `user_id` (46172), `primary_email_id` (108480), `primary_phone_id` (25175), `primary_billing_address_id` (37446), `primary_shipping_address_id` (43089).
- `group_label` (1245; free text), `consentement` (3646; consent record).

**(c) NOT NEEDED**
`complete_later_at` (32), `source_knows_us` (33), `last_subscribed_at` (711), `preferred_language` (6080), `email_bounced` (1094), `marked_as_spam` (0), `language` (10299), `bid_notification_type` (714), `mean_of_comm` (125197), `accept_solicitation` (0), `import_id` (14179), `unsubscribed_at` (281), `updated_at`.

Recommendation: no Customer node with any (b) column. An opaque `customer_id` on Order is enough for distinct counts. Logged-in donors' customer rows are matched **across teams by email** (HasLinkedCustomers.php:12-29), so a donor-facing scope must not use `customers.team_id` alone.

---

## 8. tax_receipts (7,846 rows, 21 columns)

**(a) NEEDED**
- `id`, `team_id` (7846; tenant), `order_id` (7846; campaign path), `status` (7846; 1 created, 2 sent, 3 canceled), `issued_at` (7846; the date the table filters on), `contributed_at` (7846), `sent_at` (7096), `created_at`.
- Amounts: `donation_amount` (7845; eligible amount, shown in the table), `total_amount` (7234), `cost_amount` (764). **Ingest the accessor values, not the columns**: `total_amount` falls back to the order chain when NULL (612 rows; TaxReceipt.php:45-53, M28).
- `receipt_number` (7846): an org-issued serial, not personal by itself; needed for "find receipt N".
- `is_physical_product` (4).

**(b) SENSITIVE**
- `donor_name` (7846), `donor_address` (7842).
- `evaluator_name` (2), `evaluator_address` (4).
- `description` (7845): free text, may name the donor or the honoree.

**(c) NOT NEEDED**
`updated_at`, `physical_product_description` (2), `recipient_id` (0).

---

## 9. transfers = payouts (4,269 rows, 37 columns)

**(a) NEEDED**
- `id`, `team_id` (4269; tenant, see §0), `campaign_id` (4269), `destination_team_id` (4269), `bill_item_id` (4269; statement visibility goes through `bill_items.status_bi`/`plan_id`, M26), `bill_id` (269).
- Amounts and state: `amount` (4242), `old_amount` (19), `status` (4269; 1 ready, 10 confirmed, 20 sent), `sent_as_method` (4174; 1 Stripe, 2 Interac, 3 EFT, 4 cheque).
- Dates: `sent_at` (4161), `from_date`/`to_date` (4269; period; `to_date` = expected payout date on the dashboard), `effective_at` (1495), `deposited_at` (2), `created_at`.
- Reconciliation: `reconciled` (7), `reconciled_at` (2), `needs_eft_validation` (1).
- `is_interac` (130), `currency_id` (1998).

**(b) SENSITIVE**
- **`interac_password` (0 filled locally; secret in prod)**, `interac_recipient` (0).
- Stripe ids: `account_id` (4269; Stripe Connect account), `transfer_group` (4269), `stripe_transfer_id` (971), `payout_id` (0).
- Bank: `in_bank_account` (0), `bank_id` (46; points to bank account details).

**(c) NOT NEEDED**
`description` (41), `updated_at`, `mark_eft` (0), `loaded_eft` (3141), `eft_line_id` (3141), `eft_error_reason` (1), `eft_error_option` (1), `change_reason` (500 chars, 19; free text, keep out).

Related table `bill_items` (invoices/statements) is needed only to reproduce statement visibility and the account balance (M27). Ingest the computed balance per campaign instead.

---

## 10. Tickets, seats, bids, draws, memberships, forms

### reservations: tickets and draw numbers (236,574 rows)
- **(a)**: `id`, `campaign_id` (236574), `order_id` (227931), `product_id` (236545), `campaign_date_id` (215861), `seat_id` (20977), `status_id` (156866; 1 valid, 2 void; NULL for QR/venue tickets), `ticket_number` (156866; draw number), `scanned_at` (25753), `scanned_amount` (25753), `marked_as_out_at` (282), `sent_at` (58674), `is_blocked` (219), `created_at`.
- **(b)**: `qr_code` (221061; ticket secret), `reservation_email` (0), `custom_reservation` (0), `user_id` (4380), `storage_name` (58057; file name of the ticket PDF), `void_reason` (363; free text), `status_changed_by` (226; staff user).
- **(c)**: `scanner_session_id` (25752), `entity_id` (338), `status_changed_at` (227), `updated_at`.

### seats (12,064 rows; venue layout, no person data)
- **(a)**: `id`, `ticket_type` (11895), only if seat-type questions are wanted.
- **(c)**: `row_id`, `is_empty` (5116), `is_stage` (1393), `seat_color`, `seat_name` (5937), `description` (0), `seat_style` (918), `order`, timestamps.

### pledges: auction bidder sessions (1,857 rows, 55 soft-deleted)
- **(a)**: `id`, `campaign_id`, `deleted_at` (55), `created_at`, and `customer_id` (1857) as an opaque key only.
- **(b)**: `ip` (1839), `unique_id` (1857; token), `invitation_id` (870; token), `user_id` (1020).
- **(c)**: `max_amount` (9; auto-bid ceiling, a private commercial value: keep out), `email_sent` (836), `updated_at`.

### pledge_amounts: bids (3,872 rows)
- **(a)**: `id`, `pledge_id`, `product_id` (3632), `amount` (3850), `quantity` (3870), `istopbid` (1102), `has_been_paid` (1013), `recipient_id` (1138), `created_at`.
- **(c)**: `notified_at` (1717), `updated_at`.

### prizes (168) and draws (48): `drawings` (0) and `tickets` (0) are empty
- prizes **(a)**: `id`, `campaign_id`, `name`, `value`, `quantity`, `isCash` (14). **(c)**: `description`, `image` (64), timestamps.
- draws **(a)**: `id`, `prize_id`, `status_id`, `campaign_date_id`, `created_at`. **(b)**: `reservation_id` (it identifies the winner's ticket, hence the winning buyer, through order → customer; keep out unless the owner wants "was prize X claimed" answers). **(c)**: `isClaimed`/`isForfeited`/`isExpired` (0), `updated_at`.

### draw_logs (3,230 rows; regulatory draw audit)
- **(a)**: `campaign_id`, `event_type`, `date`, `draw_number` (494).
- **(b)**: `eventable_person_type`/`eventable_person_id` (3218; who), `unique_number` (2941; ticket/winner number).
- **(c)**: `data_element`, `old_value`, `new_value`, `reason` (all 0), timestamps.

### Memberships
No own table. A membership is an order on a type-9 campaign (`App\Models\OrderMembership` uses table `orders`, OrderMembership.php:9). Columns needed: `orders.expires_at`, `start_from`, `canceled_at`, `main_order_id`, `subs_order_id`, `recurrence`, `subscription_id` (b).

### Forms: answers / answer_polls / polls
- **answers (48,614)**:
  - **(b)**: **`content` (1488; free text, possibly health data)**, `answerer_type`/`answerer_id` (25783; who answered: recipient or customer), `answerable_type`/`answerable_id` (48602).
  - **(c)**: `order_id` (0), `customer_id` (53), `donor_id` (0), `recipient_id` (2960), `survey_id`, `poll_id` (5313), `answer_locked` (27693), `form_result` (2), `added_by`/`modified_by`, timestamps, `deleted_at` (57).
- **answer_polls (148,081)**: **(b)** `answer_text` (145353; the answers themselves); **(c)** everything else (`answer_id`, `poll_id`, `is_open_answer` (89575), audit columns, `deleted_at` (3101)).
- **polls (104,096)**: question definitions, not personal. **(c)** for now (`body_po` (104062) is the question text). Choice counts per question would need `answer_polls` joins and an owner decision.
- **Recommendation**: keep forms out of the graph entirely in v1. The only form-derived money is in order lines with `poll_id`/`answer_id`, which are already covered by `order_details`.

### donors: invitations (266,069 rows)
- **(b)**: `email` (265699), `first_name`/`last_name` (1130), `name` (50564), `postal_code` (49073), `phone` (1100), `address` (793), `invitation_id` (266068; token), `donor_link` (886), `ext_id` (0), `fb_referal_id`/`email_referal_id`.
- **(c)**: the rest (`campaign_id`, `group_id`, `team_id`, `unsubscribe`, `was_disabled`, timestamps).
- If "how many people were invited" is wanted, ingest a per-campaign count, not the rows.

---

## 11. Sensitive columns: consolidated "never ingest" list

- **campaigns**: contact_email, contact_info, receipt_copy_email, delivery_contact_name, delivery_contact_phone, delivery_address, venue_address, event_address, scanner_attach_token, qr_code, draw_licence_number.
- **orders**: email, phone, ip, card, company_name, stripe_id, stripe_pmt_id, stripe_payment_intent_id, subscription_id, signature, verification_code, invitation_id, sharing_src_code, is_order_for_someone_else, someone_else_name, someone_else_company, someone_else_email, someone_else_address, honors_choice, honors_of, honors_message, given_recipient, additional_message, dispute_reason, refund_reason, manual_payment_note, age_verified, age_verified_at, age_verified_ip.
- **order_details**: description, gift_to_name, gift_to_email, gift_message, reservation_qr.
- **products**: contact_name, contact_email, contact_phone, stripe_price_month, stripe_price_year, stripe_price_custom.
- **recipients**: first_name\*, last_name\*, date_of_birth, gender_id, school, school_level, declared_grade, file_number, personal_pic, personal_desc, primary_phone_id, primary_billing_address_id, primary_shipping_address_id, user_id, invitation_id, ext_id.
- **groups**: leader_id, invitation_grp, public_img.
- **customers**: every column except id, team_id, created_at, deleted_at, is_company and the opt-in flags.
- **tax_receipts**: donor_name, donor_address, evaluator_name, evaluator_address, description.
- **transfers**: interac_password, interac_recipient, account_id, transfer_group, stripe_transfer_id, payout_id, in_bank_account, bank_id.
- **reservations**: qr_code, reservation_email, custom_reservation, user_id, storage_name, void_reason, status_changed_by.
- **pledges**: ip, unique_id, invitation_id, user_id.
- **draws**: reservation_id. **draw_logs**: eventable_person_type, eventable_person_id, unique_number.
- **answers**: content, answerer_type, answerer_id, answerable_type, answerable_id. **answer_polls**: answer_text.
- **donors**: all identifying columns.
- **Outside the listed tables**: `suppliers.shipstation_password` (survey.md), `teams.tax_number`/`stripe_account`/bank fields, `users.*`.

\* participant names: owner decision D9 in metrics.md.
