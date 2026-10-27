# Grill Me Decision Register — P2P Fund Transfer & Transaction Settlement System

**Status:** Recovered from Puku session history. Not a specification amendment.
**Authoritative spec:** `../../../P2P_SPECIFICATION.md` (v1.0, "Design frozen — all 13 architectural decisions settled")
**Recovery date:** 2026-10-26
**Recovered from:**
- Plan: `C:\Users\BS00865\.puku-cli\plans\synthetic-sniffing-beacon.md`
- Session: `~/.puku-cli/projects/C--Users-BS00865-Downloads-my-P2P-Project-p2p-platform/7f524c6a-66c4-4add-947f-24433aad4546.jsonl` (slug `synthetic-sniffing-beacon`, branch `setup/laravel-react`)

> **READ FIRST — scope of this register.**
> This file records what was *actually decided* in the Grill Me session and what was left open.
> It does **not** amend `P2P_SPECIFICATION.md`. No gap below is finally approved unless its
> status reads **Approved**. The Grill Me session ended at a stop-for-review point with
> **no final approval granted** (see §6, "Session End State").
> `PRD_SRS_Document.pdf` was **not** used as a requirements source — it describes a different
> micro-lending product and was explicitly excluded by the user.

---

## 1. Approval Status Legend

| Status | Meaning |
|---|---|
| 🟡 **Provisional** | Explicitly approved by the user *provisionally*. Not final. May still change. |
| 🟠 **Proposed** | Recommended and drafted by the Grill Me agent, **not** approved by the user. Awaiting review. |
| ⚪ **Open** | No answer exists. Blocks safe completion. |
| ⏸️ **Deferred** | Deliberately postponed; safe to defer past data modeling. |
| ✅ **Technical default** | Recommended as a safe technical default, **unconfirmed** by the user. |
| ❌ **Withdrawn** | Raised earlier, later found to be a false positive. |

---

## 2. Gap Inventory (20 gaps, all IDs as originally raised)

The original gap taxonomy came from the prior Puku analysis recorded in the
`synthetic-sniffing-beacon.md` plan. The Grill Me session then reconciled it against the spec.

| Severity | IDs |
|---|---|
| 🔴 CRITICAL | C-1, C-2, C-3, C-4 |
| 🟠 HIGH | H-1, H-2, H-3, H-4, H-5, H-6, H-7, H-8 |
| 🟡 MEDIUM | M-1, M-2, M-3, M-4, M-5, M-6, M-7, M-8 |

---

## 3. Decisions Confirmed (Carried Forward)

### 🟡 C-1 — Duplicate wallet debit between §7.2 and §7.3
**Status: PROVISIONAL** — Option A3, plus the user's mandatory design conditions.

**Question:** §7.2 Phase 2 already performs `UPDATE wallets SET balance = balance - :amount`.
Phase 3 step 1 performs the *same* debit again. Both precede entry creation. As written, a payer
is debited **twice**. Either Phase 2 is a provisional escrow released by Phase 3, or it double-spends.

**Options considered:**
- **A1** — Separate mutable reservation counter on wallet.
- **A2** — Pure: derive reservations from the ledger itself.
- **A3** — Reservation counter column + ledger-authoritative reconciliation *(chosen)*.

**Answer (verbatim):**
> "For C-1, I provisionally approve **Option A3**, including the fourth reconciliation invariant for reservations."

**Rationale for A3 (from session):**
- A1 "quietly violates the spec's central principle — it introduces authoritative mutable money
  state outside the ledger with no defined drift detection."
- A3 "keeps A1's speed but **subordinates the counter to the ledger the same way
  `transactions.status` already is**," consistent with §4.3.
- A2 is "the most 'pure,' but at 1,000 TPS budget it fights the §12.3 capacity cliff."
  Reserved as a documented future option rather than paid for now.

**Mandatory conditions attached to the approval:**
1. `balance_minor` = posted balance; `reserved_minor` = active reserved funds;
   available balance = posted − reserved.
2. Reservations must have **durable, uniquely identifiable records** — not solely transaction
   status or a mutable wallet counter.
3. Reserve funds and update the reservation record **atomically**, with concurrency-safe
   conditional updates.
4. Consume reservation, debit payer **exactly once**, credit payee, insert balanced ledger
   entries — all within **one ACID transaction**.
5. Reaper expiry/release and posting must be **mutually exclusive** (locking + fencing). A
   reservation must never be released twice, nor released after successful posting.
6. **Add a fourth reconciliation invariant:** wallet `reserved_minor` must equal the sum of valid
   active reservations. Safe incident handling defined; **no silent auto-repair of financial state**.
7. Ledger remains authority for **posted** money; reservation records are authority for **in-flight holds**.
8. Enforce `0 <= reserved_minor <= balance_minor`; prevent negative available balances.
9. Explicitly document crash recovery, retry, and idempotency behavior.

**Recorded as:** "a requirements-level decision, not permission to implement."

---

### 🟡 C-2 — Undefined `account_id` vs `wallet_id` relationship
**Status: PROVISIONAL** — Option B2, plus Q5 (immutable mapping).

**Question:** Ledger entries reference `account_id`; per-account sequences are per `account_id`;
Invariant 3 reconstructs per account. But §3.1 defines only `User`, `Wallet`, `Transaction`,
`Ledger Entry`, `Chain Head`, `Idempotency`, `Chain Close`, `Audit Log`. Is `account_id` ==
`wallet_id`, or a separate ledger account (1:N with wallet)?

**Options considered:**
- **B1** — `account_id` = `wallet_id`, no separate table.
- **B2** — Separate `accounts` entity, 1:1 with wallet today *(chosen)*.
- **B3** — Separate entity, 1:N from the start (available / escrow / fee accounts).

**Answer (verbatim):**
> "For C-2, I provisionally approve **Option B2: a separate accounts table with a strict 1:1
> wallet-to-ledger-account relationship for the current scope**."

**Rationale for B2 (from session):**
- "**B1 is the trap.** … `account_id` is **inside the hashed body** (§5.1). You cannot rename it,
  you cannot re-point it, you cannot migrate it without invalidating every hash in the chain.
  **The hashed field name is permanent. Design it as if you cannot change it — because you can't.**"
- B2 costs one FK join on a path that already does `SELECT ... FOR UPDATE` and two ledger inserts.
- **B3 actively rejected** — forces rewriting §10.3, a financial invariant, for flexibility with
  no requirement. §1.4 puts multi-currency out of scope.

**Mandatory conditions attached to the approval:**
1. Wallet-to-account mapping is **immutable** *(from Q5)*.
2. Authorization and wallet-to-account resolution must be validated safely **within the posting
   transaction**.
3. Preserve the existing gapless per-account sequence and ledger hash-chain invariants.
4. Keep a clearly defined wallet-to-account mapping — **do not assume `wallet_id` and `account_id`
   have identical numeric values**.
5. `wallets.balance_minor` is a materialized **posted-balance projection**, reconstructed and
   reconciled from ledger entries belonging to its mapped account.
6. `wallets.reserved_minor` is a **separate derived reservation counter**, reconciled against
   durable reservation records as decided in C-1.
7. **Do not create a second independently mutable balance field in `accounts`.**
8. Multi-account-per-wallet is **out of scope** for the current version. Future expansion requires
   a separately reviewed architecture decision and must not rewrite historical ledger entries or
   invalidate existing hashes.
9. Document the precise relationship between account ownership, wallet ownership, posted-balance
   reconstruction, and reservation reconciliation.
10. **Preserve historical hash meanings.**

**Known unresolved architectural risk (flagged in session, explicitly not closed):**
> "**C-2 R-2**" — the session flagged that H-4 (marketplace scope) depends on C-2's ownership and
> mapping safeguards. Ownership/mapping safeguard details remain **unresolved**; they were carried
> as an open blocker, not a settled decision.

---

## 4. Proposed This Round — NOT APPROVED (🟠)

The user issued a consolidated response explicitly framed as:
> "Record the following as **PROPOSED DECISIONS pending my final approval**"

The user also instructed: *"Do not automatically mark my proposals as finally approved."*

| Gap | Proposed decision (user's own selection) | Class | Status |
|---|---|---|---|
| **H-1** `opening_balance` undefined | **Choose B.** Introduce explicitly typed **SYSTEM ledger accounts** as an explicit exception to the strict user-wallet/account 1:1 mapping. All funding must have balanced, auditable ledger entries. Define funding source and reconciliation responsibilities; **no money should appear from an unexplained opening balance**. | 🟠 Financial | 🟠 Proposed |
| **H-6** Entry/close status lifecycles | **Choose B.** Ledger entries remain immutable. Use durable, unique, immutable chain-close records as evidence of finality. Transaction status remains a rebuildable projection. *(Agent elaboration: remove `status` from `ledger_entries`.)* | 🟢 Technical | 🟠 Proposed |
| **M-4** Idempotency cleanup | **Choose C, conditionally.** Separate idempotency-key validity, duplicate-financial-operation prevention, failed/in-flight retry handling, and audit-record retention. **No unconditional nightly deletion.** Define safe behavior for retries after expiry. | 🟠 Financial | 🟠 Proposed |
| **M-2** Canonical JSON | Accept the **direction** of a deterministic byte-level hashing contract, but **do NOT finalize** until exact canonicalization rules, field order, integer encoding, timestamp precision, null policy, prev_hash encoding, and cross-language SHA-256 test vectors are fully specified. **Do not call a custom fixed-key-order format RFC 8785 unless it actually conforms to RFC 8785.** | 🟢 Technical | ⚪ **BLOCKED** |
| **C-2 (addendum)** Mapping immutability | **Choose A.** Wallet-to-account mapping is immutable. Authorization and wallet-to-account resolution validated safely within the posting transaction. Preserve historical hash meanings. | 🟠 Financial | 🟠 Proposed |
| **M-1** Partitioning | **Choose A.** No partitioning now. Document MySQL InnoDB partitioning and foreign-key limitations. **Do not claim partitioning inherently destroys sequence uniqueness in all possible architectures.** Revisit storage architecture after benchmarks without weakening account sequence, FK, or chain invariants. | 🟢 Technical | 🟠 Proposed |
| **C-3** Business limit | **Accept A+B+C.** Require a configurable positive per-transaction limit, but leave the **numeric limit, daily cumulative limits, and applicable regulatory ceilings OPEN**. Do not invent Bangladesh compliance rules. **Do not enable real-money production transfers until these controls are explicitly approved.** | 🟠 Business | 🟠 Proposed (values open) |

### Rationale preserved from the session
- **H-1:** §10.3 reconstruction starts from `opening_balance`, but no entity provides it. A seeded
  number makes Invariant 3 unverifiable. Balanced ledger entries from SYSTEM accounts make funding
  auditable.
- **M-2:** Risk recorded as "⚠️ **Hash mismatch = chain fork**." Does **not** block table/column
  design, but **does** block writing `canonical_json()` or any verifier.
- **M-1:** Risk of premature partitioning at 0 rows; FK limitations in MySQL InnoDB noted.
- **C-3:** Blast radius unbounded. The user explicitly forbade inventing regulatory figures, so
  ceiling value, daily cumulative cap, and regulatory applicability are recorded as genuinely open.

---

## 5. Open / Undecided — No Answer Exists (⚪)

> **Critically: H-2, H-4, and H-5 were never grilled.** The session states this verbatim:
> "**H-4, H-5 have never actually been grilled.** They're listed in my matrix but I haven't put the
> real options in front of you. Reconciling them isn't possible yet, because you've never been asked."

### 🟡 H-2 — Reversal constraints and duplicate reversals
**Status: PROVISIONALLY APPROVED — Option A+ (MVP). NOT final approval.**
Approved by the user after an explicit stress-test review. Not amended into `P2P_SPECIFICATION.md`.

- **Original question:** §6.4 allows `POSTED→REVERSED` but doesn't specify: max one reversal per
  transaction? May a reversal itself be reversed (double reversal)? Fee/amount rules?
- **Approved decision (Option A+):**
  1. **Exactly one full-amount reversal.** No partial reversal, and a reversal may never itself be
     reversed. `REVERSED` remains terminal.
  2. **Insufficient funds at reversal time → reject the automatic reversal** and route to manual
     dispute review. **No negative user wallet.** **No automatic system-funded reimbursement.**
  3. **Enforce:** database uniqueness on the reversal record, concurrency locking, idempotency,
     atomic posting (one ACID transaction), and immutable compensating ledger entries. Original
     entries are never modified or removed (§4.2, §6.4).
  4. **Dedicated reversal authorization** — a distinct permission, separate from transfer
     authority and balance-read. Mandatory reason code from a closed set, plus an immutable audit
     record for every attempt (granted *and* rejected), in the Audit Service's independent chain
     (§11.5). Rejected attempts raise security alerts (§11.4).
  5. **Separate reversal lifecycle** tracks reversal posting and chain finality, so a committed but
     not-yet-chain-closed reversal is distinguishable from a fully final one. Reversal entries
     flow through the existing singleton Chain Closer (§7.4) — no new close machinery.
- **Rationale:** one-shot + full-amount + terminal is the only option that keeps `REVERSED`
  truthful and enforceable as a database-level constraint (§6.2). The shortfall rule (2) avoids
  breaking C-1 condition #8 (`0 <= reserved_minor <= balance_minor`) and avoids creating money with
  no funding source. Reusing C-1's reservation machinery is explicitly **not** done — a reversal is
  a clawback of already-credited funds, not a hold.
- **Explicitly OPEN (policy values, not yet decided):**
  - Exact reversal time window (mechanism specified; value not set).
  - Fee policy for reversals.
  - Higher-value approval thresholds (e.g. second-approval / four-eyes threshold).
- **Constraint:** production execution of reversals is disabled until the required policies above
  are approved.
- **Blocks:** data modeling — this blocker is now cleared for the reversal lifecycle and the
  reversal record's uniqueness/locking design.

### 🟡 H-4 — Marketplace entities and MVP scope
**Status: PROVISIONALLY APPROVED. NOT final approval.**
Approved by the user after an explicit decision review. Not amended into `P2P_SPECIFICATION.md`.

- **Original question:** §1.3 places a P2P marketplace in scope, but **no entities** (listing,
  offer, negotiation, counterparty terms) are defined, and §3.1 omits them entirely. Phase 1 or
  deferred?
- **Approved decision:**
  1. **Phase 1 is core P2P fund transfer and settlement only.**
  2. **Defer to Phase 2:** marketplace listings, offers, and negotiation.
  3. **The marketplace is NOT deleted from future product scope** — it is deferred, with a named
     Phase 2 scope item and a **separate, later architecture decision**. It remains recorded in
     §1.3 as in-scope for the product; only its Phase 1 delivery is deferred.
- **Rationale:** §1.4 already excludes lending underwriting logic while stating that "the
  marketplace facilitates" — so deferring the marketplace does not require amending §1.4. It also
  keeps C-2's strict 1:1 wallet↔ledger-account model clean: no marketplace entities are introduced
  that would need an account-ownership rule C-2 did not grant.
- **Spec consequence (for the PRD delta, not applied here):** §1.3's Phase 1 bullet list and the
  §1.2 sentence listing "browse listings" require amendment; §3.1's domain model needs no
  marketplace entities for Phase 1.
- **Blocks:** data modeling — cleared. No `listings` / `offers` / `negotiations` tables are created
  in Phase 1.

### 🟡 H-5 — KYC state definitions and transitions
**Status: PROVISIONALLY APPROVED. NOT final approval.**
Approved by the user after an explicit decision review. Not amended into `P2P_SPECIFICATION.md`.

- **Original question:** Only `KYC_VERIFIED` appears. What are the other values, and who
  transitions them?
- **Approved decision:**
  1. **KYC states are `PENDING`, `VERIFIED`, and `REJECTED`.**
  2. **New users begin in `PENDING`.**
  3. An **authorized KYC admin** may transition `PENDING → VERIFIED` or `PENDING → REJECTED`, with a
     **recorded reason and an audit trail** (Audit Service independent chain, §11.5).
  4. **`REJECTED` users may resubmit and return to `PENDING`.**
  5. **Only `VERIFIED` users may initiate transfers** (§7.1 step 2, §11.1, §14.7).
  6. **Users cannot self-verify.**
  7. **External KYC provider callbacks are deferred.**
- **Rationale:** the §7.1 gate compares against a literal `KYC_VERIFIED` string, so the enum must
  contain `VERIFIED` exactly. `REJECTED` must be distinguishable from `PENDING`, otherwise a
  rejected applicant is indistinguishable from an unreviewed one and there is no re-submission or
  support-triage path. Admin-only transition keeps self-verification impossible.
- **Spec consequence (for the PRD delta, not applied here):** §3.1's `kyc_status` field needs an
  enum definition; the §7.1 check literal `KYC_VERIFIED` needs reconciling with the enum's stored
  representation.
- **Blocks:** data modeling — cleared. The `kyc_status` enum is now determined.

---

## 6. Session End State

**The Grill Me session ended at a stop-for-review point with no final approval.**

The assistant's closing message offered two options:
> "Say the word and I'll ask H-2, H-4, H-5 in one round. Or tell me to finalize the checklist
> as-is with those three marked explicitly OPEN. No files touched; no decisions approved on your behalf."

The subsequent user turns were a `/grill-me` invocation with a final consolidated-review prompt,
a `/bmad-help` skill invocation (which failed with "Unknown skill: bmad-help"), and no approval
message. **No gap was moved to final approval.**

---

## 7. Unresolved-Blocker Checklist (as recorded in the session)

**🔴 Blocks data modeling (3):**
1. **H-4 marketplace scope** — decides whether `listings`/`offers`/`negotiations` tables exist.
2. **H-5 KYC states** — drives the `kyc_status` enum; a wrong enum = wrong gate.
3. **H-2 reversal rule** — without a "once" constraint the `REVERSED` state is unenforceable.

**🟠 Blocks some modeling (1):**
4. **M-2 hashing contract** — does *not* block table/column design, but **does** block writing
   `canonical_json()` or any verifier. Ledger tables can be designed now; hashing code cannot.

**🟢 Deferrable until deployment (5):**
C-3 numeric value · M-3 genesis literal · H-3 lease TTL value · M-6 archive mechanism ·
M-5 409 (contract, not schema)

---

## 8. Remaining Items With No Decision Recorded

These appear in the original gap taxonomy but received **no recorded user decision or agent
recommendation** in the recovered session. They are listed for completeness and are **not** silently
approved.

| Gap | Original question | State in session |
|---|---|---|
| **C-4** | `balance_minor` (§3.1) vs `balance` (§7.2/§7.3) — same column or two? | Matrix recommendation: **`balance_minor` is canonical**, deprecate bare `balance`. Marked "✅ Naming only" / technical default — **not individually approved**. |
| **H-1** | `opening_balance` undefined (§10.3) | Covered by Q1 proposal above (🟠 Proposed). |
| **H-3** | Reservation lease TTL value; Reaper interval | Matrix recommendation: config value, > typical Phase 3 latency, < user-visible SLA; **default 30s**. Marked "✅ Default only" — **not individually approved**. |
| **H-7** | `lock_epoch` scope — per transaction or global? | Matrix recommendation: **per-transaction monotonic counter**, incremented on each acquisition. Marked "✅ Approve" — **unconfirmed**. |
| **H-8** | Audit hash-chain structure; separate genesis? | Matrix recommendation: **separate canonical form + separate genesis**, never share ledger genesis. Marked "✅ Approve" — **unconfirmed**. |
| **M-3** | Ledger genesis hash constant literal | Needs a published literal constant. **Open.** |
| **M-5** | `request_fingerprint` semantics; mismatch behavior | Matrix recommendation: SHA-256 of canonical request payload; mismatch → **409**. Marked "🟠 New client-visible contract — unconfirmed." |
| **M-6** | 7-year retention / cold archive mechanism | Matrix: defer; MySQL cold partition now; **no deletion before legal review**. Class 🟠 Regulatory. **Deferred.** |
| **M-7** | Sweeper TTL vs `OLDEST_CHAIN_PENDING_AGE` threshold margin | ❌ **WITHDRAWN — false positive.** The 30s sweeper TTL and 30s alert threshold are both intentional; no margin defect exists. |
| **M-8** | Currency representation — enum or free string? | Matrix recommendation: `CHAR(3)` ISO-4217, default `'BDT'`, CHECK constraint. Marked "✅" technical default — **unconfirmed**. |

---

## 9. What Could NOT Be Recovered

| Item | Status |
|---|---|
| **Final approval of any gap** | ❌ Not recoverable — never granted. The session ended at stop-for-review. |
| **H-2, H-4, H-5 answers** | ❌ Not recoverable — never asked. Agent recommendation exists only in the unapproved matrix. |
| **C-3 numeric ceiling / daily cap / regulatory ceiling** | ❌ Not recoverable — user explicitly refused to invent these and left them open. |
| **M-2 canonicalization spec + test vectors** | ❌ Not recoverable — direction accepted, exact rules pending. |
| **M-3 genesis literal** | ❌ Not recoverable — value never chosen. |
| **H-3 lease TTL / Reaper interval** | ❌ Only a recommended default of 30s exists; not approved. |
| **Any Grill Me rounds for H-2/H-4/H-5** | ❌ Do not exist in session history. |
| **Grill Me session for the `PRD_SRS_Document.pdf` (HydroGrid)** | ⚠️ Found in `fc104558` session, but that is a **different product**. It was explicitly excluded from requirements and is **not** reflected in this register. |
| **Session `a4939b5f`, `24a93c00`, `23dd9065`, `753aa27c`, `0c139fdf`, `22fe23eb`** | ℹ️ Contain Grill Me usage for **other projects** (agentic engineering, p2p project with spaces/testimonials/Stripe). Not applicable to this spec. |

### Searches performed for recovery
- `~/.puku-cli/plans/` — found `synthetic-sniffing-beacon.md` (gap taxonomy + BMAD recommendation).
- `~/.puku-cli/history.jsonl` — mapped all 26 Grill Me prompts to session IDs.
- `~/.puku-cli/projects/**` — searched all 10 project session files for gap IDs C-1…M-8.
- Session `7f524c6a` — confirmed as the authoritative source (all 20 gaps, both approvals, both
  proposal rounds).

---

## 10. Recommended Next Step (per the session's own recorded advice)

The session's closing recommendation was to close H-2, H-4, H-5 in **one short round**, then
produce the complete consolidated register for final approval — explicitly to avoid "gaps silently
assumed," which it identified as the thing that "would actually hurt."

Under BMAD, the equivalent is:
1. Create the initiative and seed it with this register as a source.
2. **`bmad-forge-idea`** to close H-2, H-4, H-5 (and M-2, C-3 values) with the user.
3. **`bmad-prd`** in update/validate mode to produce the approved PRD delta.
4. **`bmad-agent-po`** (Sofia) to write and slice the initiative ticket.
5. **`bmad-architecture`** for the spine and data model — which reads the ticket and cannot
   proceed until H-2/H-4/H-5 are closed.

---

*Register generated by recovering Puku session history. No specification file, application code,
database schema, environment file, or test configuration was modified. No migrations, tests, commits,
or pushes were performed.*
