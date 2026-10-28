# Release History

**Project:** P2P Fund Transfer & Transaction Settlement System
**Date of record:** 2026-10-28
**Status:** Lesson 11 continuous-delivery exercise completed

---

## Table of Contents

1. [Scope and Caveats](#1-scope-and-caveats)
2. [Release Summary](#2-release-summary)
3. [v1.0.0 — First CI-Verified Version](#3-v100--first-ci-verified-version)
4. [Deploy #2 — Successful Simulated Deployment](#4-deploy-2--successful-simulated-deployment)
5. [v1.1.0 — Deliberately Broken Pre-Release](#5-v110--deliberately-broken-pre-release)
6. [Deploy #3 — Blocked by Failing Tests](#6-deploy-3--blocked-by-failing-tests)
7. [Deploy #4 — Rollback Practice Redeployment](#7-deploy-4--rollback-practice-redeployment)
8. [What the Exercise Demonstrated](#8-what-the-exercise-demonstrated)

---

## 1. Scope and Caveats

**All deployments described in this document were SIMULATED.**

The Deploy workflow copies the tested release artifact into a temporary folder on
the GitHub Actions runner and runs Laravel smoke checks against that copy. No
Docker image is built, no application is packaged for hosting, and nothing is
uploaded to any server or hosting service.

**The application is not hosted live.** It is not running in production, it is
not serving real users, and no real user data was ever involved. The GitHub
Environments, the production approval gate, the deployment history and the
audit trail are real GitHub features and were exercised for real; only the
act of "deploying" is a simulation.

**v1.1.0 never reached production.** Its Deploy run failed during the
test/build stage, so neither the staging nor the production job ran for it.
The rollback practice in Deploy #4 deliberately re-deployed **v1.0.0**, the
working version, rather than v1.1.0.

> This document records what was observed in the named workflow runs. Where a
> run's output was not inspected directly, this document says so rather than
> asserting a result. Test counts, warning counts and log details are not
> reproduced here, because they are not recorded in this document's source.

---

## 2. Release Summary

| Item | Version / Run | Commit | Outcome |
|---|---|---|---|
| Baseline release | `v1.0.0` | `27239e6` | CI Run #8 passed |
| Simulated deployment | Deploy #2 | `v1.0.0` / `27239e6` | Staging and production deployed, manual approval granted |
| Deliberate defect | `v1.1.0` | `f06f4f1` | Pre-release, deliberately broken |
| Blocked deployment | Deploy #3 | `v1.1.0` / `f06f4f1` | Tests failed; staging and production skipped |
| Rollback practice | Deploy #4 | `v1.0.0` / `27239e6` | Successful redeployment of the working version |

### Deployment runs

| Run | URL |
|---|---|
| Deploy #2 | <https://github.com/BrishtyDas22/brain-station_p2p-project/actions/runs/40679082778> |
| Deploy #3 | <https://github.com/BrishtyDas22/brain-station_p2p-project/actions/runs/40689535794> |
| Deploy #4 | <https://github.com/BrishtyDas22/brain-station_p2p-project/actions/runs/40690858126> |

---

## 3. v1.0.0 — First CI-Verified Version

| Field | Value |
|---|---|
| Tag | `v1.0.0` (annotated) |
| Commit | `27239e6` |
| Tag message | `First CI-verified version of the P2P platform` |
| Date | 2026-10-28 |

This is the baseline release of the project. The commit was verified by CI
before the tag was created, and **CI Run #8 passed** on this version — the
CI workflow run numbered 8, on commit `27239e6`, concluded `success`. The tag
was created on a commit that already had a green CI run behind it; the tag was
never moved or re-created afterwards.

---

## 4. Deploy #2 — Successful Simulated Deployment

**Run:** <https://github.com/BrishtyDas22/brain-station_p2p-project/actions/runs/40679082778>

**Result: successful simulated deployment to both staging and production,
with the manual production approval granted.**

All four jobs in this run concluded `success`: "Resolve version to a
commit", "Test and build release artifact", "SIMULATED deploy to staging",
and "SIMULATED deploy to production". The run was manually triggered, and
the `production` environment deployment was approved by **BrishtyDas22** on
2026-10-28 with the comment:

> Staging smoke checks passed. Approving simulated deployment of v1.0.0.

This run exercised the full pipeline for `v1.0.0`:

1. **test/build** — the proven CI setup ran against the tagged commit:
   PHP 8.3 with the required extensions, Node for the frontend toolchain, the
   writable storage directories, the isolated MySQL 8.0 service, and the real
   append-only ledger triggers enabled over an authenticated admin connection.
   The frontend production build ran **before** the TypeScript type check,
   because the build is what generates the Wayfinder modules the type check
   resolves. The Laravel test suite ran against the disposable CI MySQL
   service.
2. **deploy-staging** — the *same* release artifact was downloaded from the
   artifact store and copied into a temporary staging folder on the runner.
   It was not rebuilt and not re-checked-out. Laravel booted from the copied
   artifact and passed the database-free smoke checks.
3. **Manual approval** — GitHub paused the run at the `production`
   environment and waited for the required reviewer's approval before the
   production job was allowed to start.
4. **deploy-production** — the identical artifact was copied into a temporary
   production folder on the runner and passed the same smoke checks.

Both deployment jobs label their output as **SIMULATED** and state explicitly
that the application is not live.

---

## 5. v1.1.0 — Deliberately Broken Pre-Release

| Field | Value |
|---|---|
| Tag | `v1.1.0` (annotated) |
| Commit | `f06f4f1` |
| Tag message | `Deliberately broken version for pipeline-blocking practice` |
| Branch | `release/failure-demo` (local only; never pushed) |
| Date | 2026-10-28 |

`v1.1.0` is **not a product release**. It was created for the purpose of
practising pipeline blocking, and it contains a defect that was introduced on
purpose.

### The deliberate defect

A single line was changed in
`p2p-platform/app/Http/Controllers/TransactionController.php`:

```diff
-        return Inertia::render('transactions/index', [
+        return Inertia::render('transactions/history', [
```

The Inertia page component was pointed at `transactions/history`, but no such
frontend page exists — the project only has `transactions/index.tsx` and
`transactions/show.tsx`.

### How the defect was caught

An existing test, `DemoScreenTest::test_the_transaction_history_is_scoped_to_the_signed_in_user`,
asserts that the transactions screen renders the `transactions/index`
component. It fails because the controller now renders a different component
name, and because the page file `transactions/history.tsx` does not exist.

**No test was modified and no check was weakened to produce this failure.** The
defect was carried by the application code alone, and the pre-existing test
suite detected it.

---

## 6. Deploy #3 — Blocked by Failing Tests

**Run:** <https://github.com/BrishtyDas22/brain-station_p2p-project/actions/runs/40689535794>

**Result: the test suite failed, and the staging and production jobs were
skipped.**

The failing step in this run was **"Run the test suite"** in the
`test-build` job. The build steps that follow it — "Build the release
artifact" and "Upload the release artifact" — were skipped, so no artifact
was produced for this version.

The cause of the test failure was the nonexistent `transactions/history.tsx`
page: the deliberate defect in `TransactionController` points the Inertia
page component at a page that does not exist, and the pre-existing test
`DemoScreenTest::test_the_transaction_history_is_scoped_to_the_signed_in_user`
asserts that this screen renders the `transactions/index` component. The run
log itself is not readable without authentication, so the individual test
assertion text is not quoted here; the failure is attributable to this defect
because the defect is the only difference between this commit and the
commit that passed.

This is the intended behaviour of the exercise. The pipeline ran against
`v1.1.0` and stopped at the **test/build** stage, because the deliberate
defect was detected by the test suite. Because the Deploy workflow declares
its dependencies explicitly — `test/build` → `deploy-staging` →
`deploy-production` — the failure of the first stage meant that neither
deployment job ran at all. Both deploy jobs are recorded with the conclusion
`skipped`.

The failure was therefore **blocking, not merely reported**: the broken
version never produced a deployable artifact, never reached staging, and
**never reached production**.

---

## 7. Deploy #4 — Rollback Practice Redeployment

**Run:** <https://github.com/BrishtyDas22/brain-station_p2p-project/actions/runs/40690858126>

**Result: successful redeployment of `v1.0.0` for rollback practice,
including the staging checks and the production approval.**

All four jobs in this run concluded `success`, and the `production`
environment deployment was approved by **BrishtyDas22** on 2026-10-28. The
run's own description records its intent:

> Rollback practice: redeploying working version v1.0.0 after v1.1.0 was
> blocked by tests.

This run practised returning to a known-good version. `v1.1.0` had failed its
test stage and never reached production, so there was no broken version
deployed to roll back *from*. The rollback practice therefore consisted of
redeploying **`v1.0.0`** — the working version — through the complete
pipeline:

1. **test/build** — the same checks and test suite ran against `v1.0.0`
   (`27239e6`) and passed, producing a release artifact from that tested
   checkout.
2. **deploy-staging** — the artifact was copied into a temporary staging
   folder and the Laravel smoke checks ran against the copied artifact.
3. **Manual approval** — the run again paused at the `production` environment
   and waited for the required reviewer's approval.
4. **deploy-production** — the same artifact was copied into a temporary
   production folder and passed the same smoke checks.

The exercise thereby demonstrated both halves of the delivery contract: a
defective version is stopped by the pipeline before it can be deployed, and a
known-good version can be promoted again through the identical, gated path.

---

## 8. What the Exercise Demonstrated

1. **The test suite is a real gate.** A defect introduced in application code
   alone was caught by a pre-existing test, with no test changes and no
   weakened checks.
2. **Failures block downstream stages.** Because the jobs declare explicit
   dependencies, a failing test stage means staging and production do not run
   at all. The broken version was never promoted.
3. **The artifact is promoted, not rebuilt.** Staging and production consume
   the identical artifact built from the tested checkout, so what is
   smoke-tested is what would be deployed.
4. **The approval gate is real.** GitHub paused the run at the `production`
   environment and required the designated reviewer to approve before the
   production job started.
5. **Deployments are serialized.** A deployment already in progress is never
   cancelled by a newer one.
6. **A working version can be promoted again.** The pipeline supports
   returning to `v1.0.0` through the same gated path.

**Reminder: every deployment in this document was simulated, and the
application is not hosted live.**

---

*Generated 2026-10-28. Deployment runs: #2 (40679082778), #3 (40689535794),
#4 (40690858126).*

*Verification basis: each run's job list, job conclusions, environment
approvals and workflow-run listing were read from the public GitHub Actions
API and run pages. Step-level test output is not retrievable without
authentication, so no individual test assertion is quoted in this document.*
