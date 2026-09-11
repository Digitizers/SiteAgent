# SiteAgent 2.17.3 — the fenced file restore and the per-target write sequence — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship SiteAgent 2.17.3 — the site half of Aura#520: an overwrite record that records what replaced it (`replaced_with_sha256`), a restore of that record that writes only while the file still holds exactly those bytes, a per-target `write_seq` that orders two writes to one file, and a designated refusal code on every file-restore refusal so Aura can map them to 409 instead of guessing at a 500.

**Architecture:** Everything lands in one class, `Aura_Worker_Snapshots`. The per-path lock 2.17.2 introduced serialises SiteAgent's own writers, and **nothing else** — a plugin, an administrator over SFTP or any other process can write the target while we hold it (Codex #101 round-1 P1). So the fence does not rest on the lock: when the restore is about to write, it CLAIMS the path with an atomic `rename()` to an opaque `.aura-restore-<hex>` and verifies the file it now holds, exactly as the created-file restore has since 2.17.0 — what is verified and what is replaced are then the same inode, and whatever arrives at the path afterwards is never touched. `write_seq` is a small sidecar file beside that lock (`path-<sha1>.seq`), read and written under it. No REST change is needed: `restore_after_admission()` already maps "the answer carries a `code`" to HTTP 409, so the four new codes arrive as designated refusals for free.

**Tech Stack:** PHP 7.4+ (CI matrix 7.4 / 8.1 / 8.2), WordPress 6.2+, PHPUnit (`composer test`, or `vendor/bin/phpunit --filter <name>`), PHPCS (`composer lint`). The test harness stubs WordPress — `tests/bootstrap.php`, no WordPress loaded — and writes real files under `WP_CONTENT_DIR`.

**Spec:** `Digitizers/Aura` — `docs/superpowers/specs/2026-09-11-site-file-snapshot-restore-design.md`, §3.1 (this plan), merged as `db7d7738`. Read §3.1 and §2 before starting. This is plan 1 of 3: plan 2 is Power Pack 0.2.6 (`snapshot_fenced` + `write_seq` forwarded), plan 3 is the Aura mirror and restore path. They ship in that order (spec §8).

**Baseline:** `composer test` on `main` at `8c174b8` — 2268 tests, 9959 assertions, green (10 PHP deprecations and 1 PHPUnit deprecation are pre-existing; they must not increase).

## Global Constraints

- **Every new refusal carries a `code`.** `aura_snapshot_unfenced`, `aura_file_changed_since`, `aura_snapshot_voided`, `aura_path_locked` — spelled exactly so. `restore_after_admission()` maps `isset( $result['code'] )` to **409**; an answer without a code is a 500 and means an execution failure, not a refusal (spec §3.1).
- **The fence is exact-match, both ways.** A restore of an overwrite record writes only when the file hashes to the record's `replaced_with_sha256`; when it already hashes to the payload it answers `{ success: true, already: true }` and writes nothing; anything else — different bytes, gone, not a regular file — is `aura_file_changed_since` and nothing is written (spec §3.1).
- **The fence key is written AFTER the write lands, which is a deliberate departure from spec §3.1** (which says `replaced_with_sha256` is "persisted with the record (before `replace_in_place()`)"). Persisting it first makes the record assert something untrue for as long as the write can still fail, and every recovery from that state depends on a delete or a void that can itself be refused (Codex #101 round-5/round-6 P1). Stamping after the write removes the unsafe state instead of recovering from it; a stamp that fails leaves the record unfenced, which is the safe side. The spec's intent — no restorable record without a proven fence — is kept exactly.
- **No hash, no restore.** A `kind: file` record with `existed !== false` and no `replaced_with_sha256` answers `aura_snapshot_unfenced` and writes nothing. Records taken by a direct `snapshot_file()` (REST `POST /aura/v2/snapshot`, an old Power Pack's fallback path) are unfenced by construction and are refused — a deliberate behaviour change, called out in the changelog (spec §2 Q3: fail closed).
- **`write_seq` is taken under the target lock, before the record is persisted**, as `max(previous + 1, now in microseconds)` with `previous` from the sidecar `path-<sha1>.seq`, and the sidecar is written back before the record (spec §3.1).
- **A `write_seq` that cannot be established is ABSENT, never guessed.** An unreadable or non-decimal sidecar, a sidecar that cannot be written, or a 32-bit build (`PHP_INT_SIZE < 8`, where microseconds overflow) leaves the record without the key; the write itself still proceeds. Aura mirrors such a row unfenced (spec §3.1).
- **A code is ADDED to a refusal; its `error` text never changes.** Thirty-odd existing assertions read `error` (`'locked'`, `'file_changed_since'`, `'symlink'`, `'not a regular file'`), and they must all keep passing: every change in this plan adds a `code` key (and sometimes `detail`) beside the wording that is already there.
- **`refuse_at_path()` itself is NOT touched.** It is shared with the WRITE paths, where a symlink refusal is not a "changed since" fact and must not become a 409. The restore path wraps it — `restore_refusal()` — so only a restore's refusals gain `aura_file_changed_since` (Codex #101 round-1 P1).
- **The path is claimed before it is written, never merely checked.** A hash taken in place decides only the answers that write NOTHING (`already`, a refusal); once the restore intends to write, it claims the path by `rename()` and re-verifies the claimed file, so an external writer arriving between the check and the write is never clobbered (Codex #101 round-1 P1).
- **Hash comparisons use `hash_equals()`**, never `===`, matching every existing comparison in this class.
- **Nothing is written outside the lock.** Every new check that decides whether to write runs inside the `with_target_lock()` closure, after the existing under-lock `refuse_at_path()` re-check.
- Coding conventions: tabs; `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard; `Aura_Worker_*` prefixes; `composer lint` green — `WordPress.WP.AlternativeFunctions` and `WordPress.PHP.NoSilencedErrors` are on, so every `@`, `fopen`, `rename` and `unlink` carries the line-level `phpcs:ignore` the file already uses nearby.
- Version bump touches **four places, always together** (CLAUDE.md "Releasing"): the `Version:` header and `AURA_WORKER_VERSION` in `digitizer-site-worker/digitizer-site-worker.php`, `Stable tag:` **and** a `== Changelog ==` entry in `digitizer-site-worker/readme.txt`, and a `## Changelog` entry **plus** the `Stable-x.y.z` badge in `README.md`.
- **`readme.txt`'s changelog headroom is 367 words** (`php .github/scripts/check-readme-limits.php`). The 2.17.3 entry must fit inside it; if it does not, MOVE the oldest entries to `docs/changelog-archive.md` byte-for-byte and advance the stub — never compress or rewrite an entry (CLAUDE.md).
- Git: explicit `git add <path>` per file, never `-A`; never `git stash`; commit trailer `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`; a PR for every change, then the Codex review loop; merge and release only on the owner's approval.

---

## File structure

| File | Responsibility |
|---|---|
| `digitizer-site-worker/includes/class-aura-worker-snapshots.php` | Every code change in this plan: `next_write_seq()`, `snapshot_file()`'s extra meta, `overwrite_file()`'s recorded hash, the fenced `restore_existing_file()`, the codes on the create-restore and lock paths |
| `tests/unit/SnapshotsTest.php` | Tasks 1–3: engine tests. TWO existing tests change behaviour: `test_file_snapshot_and_restore_roundtrip` (line 44), which restores a bare `snapshot_file()` record and must now assert the `aura_snapshot_unfenced` refusal; and `test_restoring_an_existing_file_snapshot_replaces_by_stage_and_rename_under_the_target_lock` (line 2166), whose stage-failure half restores onto a target it has just changed to `v3` — the fence now refuses that before `stage()` is ever called (Codex #101 round-1 P2) |
| `tests/unit/SnapshotsRestoreCodesTest.php` (new) | Task 4: the REST surface — each code answered 409, and the pin that a file restore opens no door-log entry |
| `digitizer-site-worker/includes/class-aura-worker-api.php` | Task 5: one line — the restore response statement is written twice on line 1540, the second copy unreachable (a defect already on `main`, folded in on the owner's instruction) |
| `digitizer-site-worker/digitizer-site-worker.php`, `digitizer-site-worker/readme.txt`, `README.md` | Task 6: 2.17.3 |

Three facts the implementer must not "fix":

- **`restore_after_admission()` needs no change to its behaviour.** Task 5 removes a duplicated line inside it; nothing about the mapping moves. It already answers 409 for any result carrying a `code` (`class-aura-worker-api.php:1539`). Do not add a code table there; adding one is how the two layers drift.
- **A `kind: file` record never opens a door entry**, because `restore_snapshot()` reserves one only when `$record['door_kind']` is in `Aura_Worker_Snapshots::DOOR_KINDS` — and `file` is not a `door_kind` at all. Task 4 pins this with a test rather than changing anything; the test exists because Aura's design depends on a file restore creating no second action.
- **The flock lock file and the `.seq` sidecar are different files with the same stem.** `with_lock()` keeps `path-<sha1>.lock` (empty, by design — never "clean it up"), and the sidecar is `path-<sha1>.seq` beside it. Never write the sequence into the lock file: on a host without `flock()` that name is a *directory* (`path-<sha1>.lock.d`).

---

### Task 1: `write_seq` — the per-target write sequence

**Files:**
- Modify: `digitizer-site-worker/includes/class-aura-worker-snapshots.php` — add `next_write_seq()` beside `with_target_lock()` (~line 937); call it in `create_file_locked()` (the record array, ~line 322) and in `overwrite_file()`'s locked closure (~line 215); add the `$extra` parameter to `snapshot_file()` (~line 165)
- Test: `tests/unit/SnapshotsTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `protected function next_write_seq( $path ): ?int` — the sequence, or `null` when it cannot be established. Records written by `create_file()` and `overwrite_file()` carry `write_seq` (int) when it could be taken. `public function snapshot_file( $path, array $extra = array() )` — `$extra` keys are merged into the record's meta before it is persisted.

- [ ] **Step 1: Write the failing tests**

Add to `tests/unit/SnapshotsTest.php`:

```php
	public function test_write_seq_is_recorded_and_strictly_increases_per_target(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/seq.php';

		$first = $snaps->create_file( $file, "<?php // v1\n" );
		$this->assertTrue( $first['success'] );
		$a = $snaps->get( $first['snapshot']['id'] )['write_seq'] ?? null;
		$this->assertIsInt( $a, 'a create records write_seq' );

		$second = $snaps->overwrite_file( $file, "<?php // v2\n" );
		$this->assertTrue( $second['success'] );
		$b = $snaps->get( $second['snapshot']['id'] )['write_seq'] ?? null;
		$this->assertIsInt( $b, 'an overwrite records write_seq' );
		$this->assertGreaterThan( $a, $b, 'the second write to this path sorts after the first' );
	}

	public function test_write_seq_increases_even_when_the_clock_goes_backwards(): void {
		// The sidecar holds the previous value, so a clock that steps back
		// cannot make the later write sort before the earlier one.
		$snaps = new class extends Aura_Worker_Snapshots {
			public $now = 2000000000000000; // microseconds
			protected function now_micros() {
				return $this->now;
			}
		};
		$file = WP_CONTENT_DIR . '/clock.php';
		file_put_contents( $file, "<?php // v0\n" );

		$first    = $snaps->overwrite_file( $file, "<?php // v1\n" );
		$snaps->now = 1000000000000000; // the clock steps BACK an hour's worth
		$second   = $snaps->overwrite_file( $file, "<?php // v2\n" );

		$a = $snaps->get( $first['snapshot']['id'] )['write_seq'];
		$b = $snaps->get( $second['snapshot']['id'] )['write_seq'];
		$this->assertSame( $a + 1, $b, 'the sidecar, not the clock, orders the second write' );
	}

	public function test_an_unreadable_sequence_sidecar_leaves_the_record_without_write_seq(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/garbage.php';
		file_put_contents( $file, "<?php // v0\n" );
		$sidecar = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.seq';
		file_put_contents( $sidecar, "not-a-number\n" );

		$out = $snaps->overwrite_file( $file, "<?php // v1\n" );

		$this->assertTrue( $out['success'], 'the write still lands' );
		$this->assertArrayNotHasKey( 'write_seq', $snaps->get( $out['snapshot']['id'] ), 'no sequence is invented' );
	}

	public function test_a_stale_sequence_stage_is_swept_from_the_snapshots_directory(): void {
		// Codex #101 round-3 P2: a crash between stage() and rename() left a
		// `.aura-create-*` in the snapshots directory that nothing swept.
		$snaps = new Aura_Worker_Snapshots();
		$dir   = WP_CONTENT_DIR . '/aura-backups/snapshots';
		$stray = $dir . '/.aura-create-deadbeefdeadbeef';
		file_put_contents( $stray, '123' );
		touch( $stray, time() - 7200 ); // older than STAGE_MAX_AGE

		$file = WP_CONTENT_DIR . '/sweeps.php';
		file_put_contents( $file, "<?php // v0\n" );
		$this->assertTrue( $snaps->overwrite_file( $file, "<?php // v1\n" )['success'] );

		$this->assertFileDoesNotExist( $stray, 'the stale sidecar stage is swept' );
	}

	public function test_a_direct_snapshot_file_records_no_write_seq(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/direct.php';
		file_put_contents( $file, "<?php // v0\n" );

		$snap = $snaps->snapshot_file( $file );

		$this->assertTrue( $snap['success'] );
		$this->assertArrayNotHasKey( 'write_seq', $snaps->get( $snap['snapshot']['id'] ) );
	}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter 'write_seq|sequence_sidecar|sequence_stage_is_swept' tests/unit/SnapshotsTest.php`
Expected: FAIL — `write_seq` is absent from every record, so the first three tests fail on their assertions (the fourth passes already, and must keep passing).

- [ ] **Step 3: Add the sequence helper and its clock seam**

In `class-aura-worker-snapshots.php`, beside `with_target_lock()`:

```php
	/**
	 * The per-target write sequence: a value that strictly increases for
	 * successive writes to ONE path, so two writes to one file can be ordered
	 * after the fact (Aura#520 §3.1). Aura orders a rollback by it, because
	 * nothing it observes does: it runs its render validation after the site
	 * answers and stamps the action's finish time only then, so the earlier of
	 * two concurrent writes can finish later.
	 *
	 * Callers hold the target lock, so the read-modify-write below is
	 * serialised per path. The value is `max(previous + 1, now)` in
	 * microseconds: monotonic whatever the wall clock does, and still roughly
	 * chronological across different paths.
	 *
	 * @param string $path The target path.
	 * @return int|null The sequence, or null when it cannot be established —
	 *                  the record then carries none and Aura fails closed.
	 */
	protected function next_write_seq( $path ) {
		if ( PHP_INT_SIZE < 8 ) {
			return null; // microseconds do not fit in a 32-bit int
		}
		// A crash between stage() and rename() below leaves a `.aura-create-*`
		// in the SNAPSHOTS directory, and nothing else ever sweeps it: the
		// create sweeper only visits a create target's own directory (Codex
		// #101 round-3 P2). reconcile_stage() answers true for a stray with no
		// record, so these are removed once older than STAGE_MAX_AGE, at most
		// STAGE_SWEEP_CAP per call.
		$this->sweep_stale_stages( rtrim( $this->dir, '/' ) );

		$sidecar = $this->dir . 'path-' . sha1( (string) $path ) . '.seq';
		$prev    = 0;
		if ( file_exists( $sidecar ) ) {
			$raw = @file_get_contents( $sidecar ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A sidecar this class owns; an unreadable one is an answer (null), not a warning.
			if ( ! is_string( $raw ) || 1 !== preg_match( '/^[0-9]+$/', trim( $raw ) ) ) {
				return null; // present but unreadable: never invent an order
			}
			$digits = ltrim( trim( $raw ), '0' );
			// DECIMAL IS NOT THE SAME AS IN RANGE (Codex #101 round-1 P2). A
			// value above PHP_INT_MAX saturates on the cast, `$prev + 1` then
			// becomes a FLOAT, the record gets a non-integer `write_seq`, and
			// the sidecar is rewritten in exponent notation — unreadable on the
			// next write. Compare as decimal strings before casting anything,
			// and refuse the value that cannot be incremented as an int.
			$max = (string) PHP_INT_MAX;
			if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) >= 0 ) ) {
				return null;
			}
			$prev = (int) $digits;
		}
		$now = $this->now_micros();
		$seq = $prev >= $now ? $prev + 1 : $now;

		// Written BEFORE the record, by the same stage-and-rename every other
		// write here uses: a reader never sees a half-written sequence.
		$tmp = $this->stage( rtrim( $this->dir, '/' ), basename( $sidecar ), (string) $seq, 0600 );
		if ( is_array( $tmp ) ) {
			return null;
		}
		if ( ! @rename( $tmp, $sidecar ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Replacing the sidecar IS the point, atomically.
			$this->discard_stage( $tmp );
			return null;
		}
		return $seq;
	}

	/**
	 * Seam: the clock, in microseconds. A test steps it backwards.
	 *
	 * @return int
	 */
	protected function now_micros() {
		return (int) round( microtime( true ) * 1000000 );
	}
```

- [ ] **Step 4: Record it on both engine writes**

In `snapshot_file()`, take the extra meta and merge it (the signature gains a defaulted parameter, so the REST caller at `class-aura-worker-api.php:1157` is unaffected):

```php
	public function snapshot_file( $path, array $extra = array() ) {
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) || ! is_file( $path ) ) {
			return array( 'success' => false, 'error' => 'File not found: ' . (string) $path );
		}

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return array( 'success' => false, 'error' => 'Unable to read file: ' . $path );
		}

		$record = $this->persist(
			array_merge(
				array(
					'kind'   => 'file',
					'target' => $path,
					'bytes'  => strlen( $contents ),
				),
				$extra
			),
			$contents
		);
```

(the rest of the method is unchanged).

In `overwrite_file()`'s locked closure, take the sequence and the replaced-content hash before the snapshot is persisted, and pass both:

```php
			function () use ( $path, $content ) {
				$refused = $this->refuse_at_path( $path, true ); // again, under the lock
				if ( null !== $refused ) {
					return $refused;
				}
				// NO FENCE KEY YET (Codex #101 round-6 P1): `replaced_with_sha256`
				// is stamped only once the write has landed — see Task 2. A
				// record that carries it before then is a record that asserts
				// something untrue for as long as the write might fail.
				$extra = array();
				$seq   = $this->next_write_seq( $path );
				if ( null !== $seq ) {
					$extra['write_seq'] = $seq;
				}
				$snap = $this->snapshot_file( $path, $extra );
```

In `create_file_locked()`, add it to the record array built at ~line 322 (the re-read check below it is unchanged — it asserts specific keys, and `write_seq` is not one of them):

```php
		$sha   = hash( 'sha256', $content );
		$meta  = array(
			'kind'            => 'file',
			'target'          => $path,
			'existed'         => false,
			'expected_sha256' => $sha,
			'staged'          => $tmp,
			'bytes'           => strlen( $content ),
		);
		$seq   = $this->next_write_seq( $path );
		if ( null !== $seq ) {
			$meta['write_seq'] = $seq;
		}
		$record = $this->persist_create_record( $meta );
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'write_seq|sequence_sidecar|sequence_stage_is_swept' tests/unit/SnapshotsTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Run the whole suite and the linter**

Run: `composer test` — expected: 2272 tests, green, deprecations unchanged (10 + 1).
Run: `composer lint` — expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-snapshots.php tests/unit/SnapshotsTest.php
git commit -m "feat(snapshots): a per-target write_seq, taken under the target lock and kept in a path-<sha1>.seq sidecar

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: the fenced overwrite restore

**Files:**
- Modify: `digitizer-site-worker/includes/class-aura-worker-snapshots.php` — `restore()`'s `case 'file'` (~line 2345) passes the record; `restore_existing_file()` (~line 2007) gains the fence, a locked half and a coded-refusal wrapper
- Modify: `tests/unit/SnapshotsTest.php` — TWO existing tests change (lines 44 and 2166)
- Test: `tests/unit/SnapshotsTest.php`

**Interfaces:**
- Consumes: Task 1's records (`replaced_with_sha256` is written by `overwrite_file()`).
- Produces: `restore()` of an overwrite record answers one of `{ success: true }`, `{ success: true, already: true }`, `{ success: false, code: 'aura_snapshot_unfenced', error }`, `{ success: false, code: 'aura_file_changed_since', error: 'file_changed_since', detail?, moved_aside? }`, or a path refusal carrying its existing wording plus `code: 'aura_file_changed_since'`.
- Produces: `private function restore_refusal( array $refused ): array` — `refuse_at_path()`'s answer with `code: 'aura_file_changed_since'` added.

**The shape, and why it is not a simple stage-and-rename.** `with_target_lock()` serialises SiteAgent's writers only; a plugin or an administrator can write the target while we hold it. Hashing the file and then renaming a stage over it would clobber an edit that lands in between — the exact thing the fence exists to prevent (Codex #101 round-1 P1). So:

1. an in-place hash decides every answer that **writes nothing** — `already`, and both refusals;
2. when the restore intends to **write**, it STAGES the payload first — while the target is still live — then claims the path by `rename()` to `.aura-restore-<hex>`, re-verifies the file it now holds, and publishes the stage into the freed path with the no-clobber `publish()` (`link()` where available, an exclusive-create write where not). Staging first keeps the live pathname absent only for the rename and the publish, instead of for however long it takes to write the whole payload on a slow disk (Codex #101 round-2 P2);
3. anything that arrives at the path after the claim is never touched, and a claimed file that turns out to be changed is put back by the same primitives the created-file restore uses;
4. **the claim is re-hashed immediately before it is deleted.** A writer that opened the target BEFORE the claim still holds a descriptor on that inode and can write through it after the authoritative hash; the claim is then the only pathname those bytes have, and unlinking it would destroy them. A claim whose hash moved is kept and named in `moved_aside` (Codex #101 round-2 P1).

**Two documented residuals.**

**(a) The claim's hash-to-unlink window is accepted, not closed** (Codex #101 round-3 P1, declined with reasons). A writer holding a descriptor opened before the claim can write into the claimed inode after the final re-hash and before the `unlink`, and no portable primitive makes those two steps atomic — PHP exposes no way to unlink a file only if its contents are unchanged. The alternative on offer is to retain the claim whenever such a writer cannot be excluded, which is *always*: every successful restore would then leave an `.aura-restore-<hex>` beside the target — a name this engine deliberately never sweeps — and report `moved_aside`, which Aura surfaces to the operator. That trades a microsecond window for permanent litter on every restore and a warning that means nothing. **The created-file restore has carried this exact window since 2.17.0** (`restore_created_file_locked()`, the `wp_delete_file( $claim )` after its own `hash_file()`), so retaining here would also make the two restore paths disagree about the same risk. The re-hash immediately before the unlink stays: it is the narrowest portable bound, and it catches every writer that has finished by then.

**(b)** On a host without `link()`, an **executable** target cannot be republished at all: `publish_by_write()` and `put_back_by_write()` both refuse execute bits, because `fopen()` cannot create them (SiteAgent#96, #97). Claiming such a file would strand it aside. So for that case only — no `link()` AND the target is executable — the restore verifies in place and replaces with `replace_in_place()`, keeping the 2.17.2 behaviour and its narrow window. This mirrors the created-file restore's own executable branch, which 2.17.2 added for the same reason.

- [ ] **Step 1: Write the failing tests**

```php
	public function test_an_overwrite_restore_writes_only_while_the_file_holds_what_the_write_left(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/fenced.php';
		file_put_contents( $file, "<?php // original\n" );

		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];
		$out = $snaps->restore( $rec['id'] );

		$this->assertTrue( $out['success'] );
		$this->assertArrayNotHasKey( 'already', $out );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ) );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'the claim is cleaned up' );
	}

	public function test_an_overwrite_restore_of_a_file_edited_since_is_refused_and_claims_nothing(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/edited.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		file_put_contents( $file, "<?php // a human edited this\n" );
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertSame( 'file_changed_since', $out['error'] );
		$this->assertSame( "<?php // a human edited this\n", file_get_contents( $file ), 'nothing was written' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'a refusal never moves the file aside' );
	}

	public function test_an_external_write_after_the_claim_is_never_clobbered(): void {
		// Codex #101 round-1 P1: the lock holds only SiteAgent's own writers.
		// after_claim() models the editor that lands the instant we claim.
		$snaps = new class extends Aura_Worker_Snapshots {
			public $fired = false;
			protected function after_claim( $claim, $target ) {
				if ( ! $this->fired ) {
					$this->fired = true;
					file_put_contents( $claim, "<?php // edited under us\n" ); // the claimed inode changes
				}
			}
		};
		$file = WP_CONTENT_DIR . '/raced.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertSame( "<?php // edited under us\n", file_get_contents( $file ), 'the edit is back at its path, not overwritten' );
	}

	public function test_without_link_a_restored_file_keeps_its_restrictive_mode(): void {
		// Codex #101 round-3 P1: publish()'s write path creates from
		// FS_CHMOD_FILE (0644), so a 0600 file restored on a link()-less host
		// would become readable by every local account.
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
		};
		$file = WP_CONTENT_DIR . '/private.php';
		file_put_contents( $file, "<?php // original\n" );
		chmod( $file, 0600 );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$out = $snaps->restore( $rec['id'] );

		$this->assertTrue( $out['success'] );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ) );
		clearstatcache();
		$this->assertSame( 0600, fileperms( $file ) & 0777, 'a private file is never widened by a restore' );
	}

	public function test_an_overwrite_whose_write_never_lands_leaves_an_unfenced_record(): void {
		// Codex #101 round-5/round-6 P1: the record must never assert bytes that
		// did not land. It carries no fence until the write succeeds, so a
		// failed write needs no retirement at all.
		$file  = WP_CONTENT_DIR . '/never-landed.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function stage( $dir, $name, $content, $mode = null ) {
				return array( 'success' => false, 'error' => 'Short write while staging (disk full?): ' . $dir );
			}
		};

		$res = $snaps->overwrite_file( $file, "<?php // written\n" );

		$this->assertFalse( $res['success'] );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ), 'the target never changed' );
		foreach ( ( new Aura_Worker_Snapshots() )->list_snapshots() as $rec ) {
			$this->assertArrayNotHasKey( 'replaced_with_sha256', $rec, 'no record claims a write that did not land' );
		}
	}

	public function test_a_stamp_that_fails_leaves_the_record_unfenced_and_the_write_successful(): void {
		// The write is a fact; the bookkeeping is not. An unfenced record is
		// the safe side: Aura never offers it for restore.
		$file  = WP_CONTENT_DIR . '/unstamped.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function stamp_replaced_hash( $id, $sha ) {
				return false;
			}
		};

		$res = $snaps->overwrite_file( $file, "<?php // written\n" );

		$this->assertTrue( $res['success'], 'the write landed and is reported as such' );
		$this->assertSame( "<?php // written\n", file_get_contents( $file ) );
		$this->assertArrayNotHasKey( 'replaced_with_sha256', $res['snapshot'] );
		$out = $snaps->restore( $res['snapshot']['id'] );
		$this->assertSame( 'aura_snapshot_unfenced', $out['code'] );
	}

	public function test_a_racer_that_takes_the_path_during_cleanup_keeps_its_file(): void {
		// Codex #101 round-6 P1: stat-then-unlink is two steps on a NAME. The
		// entry is claimed by rename() and the moved inode re-checked, so a
		// racer's replacement is never the file that gets deleted.
		$file  = WP_CONTENT_DIR . '/cleanup-race.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			public $done = false;
			protected function link_available() {
				return false;
			}
			public $writes = 0;
			protected function write_all( $fh, $src ) {
				if ( 0 === $this->writes++ ) {
					return false; // force the cleanup path
				}
				return parent::write_all( $fh, $src );
			}
			protected function before_entry_removal( $target ) {
				if ( ! $this->done ) {
					$this->done = true;
					unlink( $target );
					file_put_contents( $target, "<?php // a racer's file\n" );
				}
			}
		};
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( "<?php // a racer's file\n", file_get_contents( $file ), "the racer's file is never deleted" );
	}

	public function test_without_link_a_short_restore_write_clears_its_entry_and_puts_the_file_back(): void {
		// Codex #101 round-5 P1: write_exclusively() leaves an empty or partial
		// entry at the path, which put_claim_back()'s exclusive create cannot
		// replace — the healthy file stayed aside and the answer claimed
		// "changed since" while the site was actually broken.
		$file  = WP_CONTENT_DIR . '/short-restore.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			public $writes = 0;
			protected function write_all( $fh, $src ) {
				// ONLY THE PUBLISH IS SHORT (Codex #101 round-7 P2):
				// put_back_by_write()'s recovery copy dispatches through this
				// same method, so failing every call would break the put-back
				// the assertions below depend on.
				if ( 0 === $this->writes++ ) {
					fwrite( $fh, '<?php // half' ); // a short write
					return false;
				}
				return parent::write_all( $fh, $src );
			}
		};
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertArrayNotHasKey( 'code', $out, 'our own failure is a 500, not a changed-since 409' );
		$this->assertSame( "<?php // written\n", file_get_contents( $file ), 'the file we claimed is back at its path' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'nothing left aside' );
	}

	public function test_a_partial_restore_write_that_cannot_be_cleared_keeps_the_file_aside_and_says_so(): void {
		// The other half of round-5 P1: when our damaged entry cannot be
		// removed, the healthy file stays under its claim name and the answer
		// names it, rather than reporting a tidy refusal.
		$file  = WP_CONTENT_DIR . '/stuck-restore.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps = new class extends Aura_Worker_Snapshots {
			protected function link_available() {
				return false;
			}
			public $writes = 0;
			protected function write_all( $fh, $src ) {
				if ( 0 === $this->writes++ ) {
					return false; // the publish is short
				}
				return parent::write_all( $fh, $src ); // the put-back copy is real
			}
			protected function remove_own_entry( $fh, $target, $mine ) {
				return false; // the entry cannot be unlinked
			}
		};
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertArrayHasKey( 'moved_aside', $out );
		$this->assertFileExists( $out['moved_aside'] );
		$this->assertSame( "<?php // written\n", file_get_contents( $out['moved_aside'] ), 'the healthy file is the one kept' );
	}

	public function test_a_chmod_that_lands_after_the_claim_is_the_mode_that_is_restored(): void {
		// Codex #101 round-4 P1: a chmod leaves the content alone, so the
		// authoritative hash still passes; republishing at the mode read before
		// the claim would discard the restriction someone just applied.
		$file  = WP_CONTENT_DIR . '/tightened.php';
		file_put_contents( $file, "<?php // original\n" );
		chmod( $file, 0644 );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $done = false;
			protected function after_claim( $claim, $target ) {
				if ( ! $this->done ) {
					$this->done = true;
					chmod( $claim, 0600 ); // tightened while we hold it
				}
			}
		};
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$out = $snaps->restore( $rec['id'] );

		$this->assertTrue( $out['success'] );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ) );
		clearstatcache();
		$this->assertSame( 0600, fileperms( $file ) & 0777, 'the mode the file had when we claimed it is the mode it comes back with' );
	}

	public function test_a_dangling_symlink_that_takes_the_path_answers_the_changed_code(): void {
		// Codex #101 round-4 P2: publish() answers 'unsupported_filesystem' for
		// a DANGLING link (file_exists() is false for one), and the merge used
		// to overwrite the put-back's code with null — a 500 for what is a
		// designated changed-since refusal.
		$file  = WP_CONTENT_DIR . '/raced-link.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $done = false;
			protected function after_claim( $claim, $target ) {
				if ( ! $this->done ) {
					$this->done = true;
					symlink( WP_CONTENT_DIR . '/does-not-exist.php', $target ); // a racer takes the path
				}
			}
		};
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'], 'a designated refusal, not a 500' );
		$this->assertArrayHasKey( 'moved_aside', $out );
		$this->assertTrue( is_link( $file ), "the racer's link is never replaced" );
	}

	public function test_a_directory_that_takes_the_path_after_the_claim_is_put_back(): void {
		// Codex #101 round-7 P1: the claim moves whatever is at the path, and a
		// directory cannot be put back by link or copy — only by rename.
		$file  = WP_CONTENT_DIR . '/raced-dir.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $done = false;
			protected function after_claim( $claim, $target ) {
				if ( ! $this->done ) {
					$this->done = true;
					rename( $claim, $claim . '-stash' );   // our file steps aside
					mkdir( $claim, 0755 );                 // a directory is what we now hold
				}
			}
		};
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertDirectoryExists( $file, 'the directory is put back at its path, not stranded aside' );
	}

	public function test_a_voided_create_record_answers_voided_even_when_the_file_is_gone(): void {
		// Codex #101 round-7 P2: the already-gone shortcut used to run first, so
		// a retired record reported a cheerful success and a rollback counted
		// it as undone.
		$snaps = new class extends Aura_Worker_Snapshots {
			public function void( $id ) {
				return $this->void_record_in_place( $id, array( 'interrupted' => true ) );
			}
		};
		$file = WP_CONTENT_DIR . '/voided-gone.php';
		$rec  = $snaps->create_file( $file, "<?php // new\n" )['snapshot'];
		$this->assertTrue( $snaps->void( $rec['id'] ) );
		unlink( $file );

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_snapshot_voided', $out['code'] );
		$this->assertArrayNotHasKey( 'already', $out );
	}

	public function test_a_write_through_a_descriptor_opened_before_the_claim_is_kept_aside(): void {
		// Codex #101 round-2 P1: rename() does not revoke an open descriptor.
		// A writer that opened the target before the claim can write into the
		// claimed inode while we publish; the claim is then the only pathname
		// those bytes have, so it is re-hashed and KEPT instead of unlinked.
		$file  = WP_CONTENT_DIR . '/descriptor.php';
		file_put_contents( $file, "<?php // original\n" );
		$snaps = new class extends Aura_Worker_Snapshots {
			public $fh = null;
			protected function publish( $tmp, $path ) {
				if ( null !== $this->fh ) {
					fwrite( $this->fh, "// appended through the open handle\n" ); // into the claimed inode
					fflush( $this->fh );
					$this->fh = null;
				}
				return parent::publish( $tmp, $path );
			}
		};
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$snaps->fh = fopen( $file, 'ab' ); // opened BEFORE the claim
		$out       = $snaps->restore( $rec['id'] );

		$this->assertTrue( $out['success'], 'the old bytes are back at the path' );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ) );
		$this->assertArrayHasKey( 'moved_aside', $out, 'the changed inode is named, not destroyed' );
		$this->assertFileExists( $out['moved_aside'] );
		$this->assertStringContainsString( 'appended through the open handle', file_get_contents( $out['moved_aside'] ) );
	}

	public function test_a_failed_stage_never_removes_the_live_path(): void {
		// Codex #101 round-2 P2: the payload is staged while the target is
		// still live, so a staging failure leaves the path untouched and
		// nothing is ever claimed.
		$file = WP_CONTENT_DIR . '/stage-first.php';
		file_put_contents( $file, "<?php // original\n" );
		$plain = new Aura_Worker_Snapshots();
		$rec   = $plain->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$short = new class extends Aura_Worker_Snapshots {
			protected function stage( $dir, $name, $content, $mode = null ) {
				return array( 'success' => false, 'error' => 'Short write while staging (disk full?): ' . $dir );
			}
		};
		$out = $short->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertStringContainsString( 'Short write', $out['error'] );
		$this->assertSame( "<?php // written\n", file_get_contents( $file ), 'the live path never went away' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'nothing was claimed' );
	}

	public function test_an_overwrite_restore_run_twice_is_already_and_writes_nothing(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/twice.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$this->assertTrue( $snaps->restore( $rec['id'] )['success'] );
		clearstatcache();
		$mtime = filemtime( $file );

		$again = $snaps->restore( $rec['id'] );

		$this->assertTrue( $again['success'] );
		$this->assertTrue( $again['already'] );
		clearstatcache();
		$this->assertSame( $mtime, filemtime( $file ), 'the file was not rewritten' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'an already-restored file is never claimed' );
	}

	public function test_an_overwrite_restore_of_a_file_that_is_gone_is_refused(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/gone.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		unlink( $file );
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertFileDoesNotExist( $file, 'a deleted file is never re-created by a restore' );
	}

	public function test_a_directory_or_symlink_at_the_path_is_refused_with_the_changed_code(): void {
		// Codex #101 round-1 P1: these refusals carried no code, so the REST
		// layer answered 500 for what is a designated changed-since refusal.
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/swapped.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		unlink( $file );
		file_put_contents( WP_CONTENT_DIR . '/elsewhere-2.php', "real\n" );
		symlink( WP_CONTENT_DIR . '/elsewhere-2.php', $file );
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertStringContainsString( 'symlink', $out['error'], 'the wording a reader already knows is kept' );
		$this->assertSame( "real\n", file_get_contents( WP_CONTENT_DIR . '/elsewhere-2.php' ), 'nothing written through the link' );
	}

	public function test_a_record_taken_without_the_replacing_hash_is_refused_as_unfenced(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/unfenced.php';
		file_put_contents( $file, "<?php // original\n" );

		$rec = $snaps->snapshot_file( $file )['snapshot']; // the direct REST/legacy path
		file_put_contents( $file, "<?php // whatever\n" );
		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_snapshot_unfenced', $out['code'] );
		$this->assertSame( "<?php // whatever\n", file_get_contents( $file ), 'nothing was written' );
	}
```

And REPLACE the existing `test_file_snapshot_and_restore_roundtrip` (line 44) — a bare `snapshot_file()` record is now refused, which is the point of the fence:

```php
	public function test_a_bare_file_snapshot_captures_but_no_longer_restores(): void {
		// 2.17.3: a record that does not say what replaced the file cannot
		// prove the file is unchanged, so its restore is refused (Aura#520
		// §2 Q3, fail closed). overwrite_file() is the fenced path.
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/target.php';
		file_put_contents( $file, "<?php // original\n" );

		$snap = $snaps->snapshot_file( $file );
		$this->assertTrue( $snap['success'] );
		$this->assertSame( 'file', $snap['snapshot']['kind'] );

		file_put_contents( $file, "<?php // clobbered\n" );
		$restore = $snaps->restore( $snap['snapshot']['id'] );

		$this->assertFalse( $restore['success'] );
		$this->assertSame( 'aura_snapshot_unfenced', $restore['code'] );
		$this->assertStringContainsString( 'clobbered', file_get_contents( $file ) );
	}
```

- [ ] **Step 2: Fix the second existing test, whose stage-failure half the fence now refuses first**

`test_restoring_an_existing_file_snapshot_replaces_by_stage_and_rename_under_the_target_lock` (line 2166) restores `$rec` — fenced to `v2` — after putting `v3` at the path, and expects `stage()`'s failure to surface as `Short write`. Under the fence the restore refuses before `stage()` is reached (Codex #101 round-1 P2). Give that half a FRESH fenced overwrite whose replacement still matches the target. Replace the block from `// A short stage write on the restore leaves the target as it was.` down to (but not including) `// A symlink at the path is refused rather than replaced.` with:

```php
		// A short stage write on the restore leaves the target as it was. The
		// record must be a FRESH overwrite, so the fence passes and the restore
		// actually reaches stage() (Codex #101 round-1 P2).
		$fresh = $snaps->overwrite_file( $file, "<?php // v3\n" )['snapshot'];
		$short = new class extends Aura_Worker_Snapshots {
			protected function stage( $dir, $name, $content, $mode = null ) {
				return array( 'success' => false, 'error' => 'Short write while staging (disk full?): ' . $dir );
			}
		};
		$res = $short->restore( $fresh['id'] );
		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'Short write', $res['error'] );
		$this->assertSame( "<?php // v3\n", file_get_contents( $file ), 'the target is put back as it was' );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/.aura-restore-*' ), 'the claim is not left behind' );
```

- [ ] **Step 3: Run the new and changed tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'overwrite_restore|unfenced|bare_file_snapshot|external_write_after_the_claim|directory_or_symlink_at_the_path|descriptor_opened_before_the_claim|failed_stage_never_removes|restored_file_keeps_its_restrictive_mode|chmod_that_lands_after_the_claim|dangling_symlink_that_takes_the_path|write_never_lands_leaves_an_unfenced|stamp_that_fails|racer_that_takes_the_path_during_cleanup|directory_that_takes_the_path_after_the_claim|voided_create_record_answers_voided|short_restore_write_clears|partial_restore_write_that_cannot_be_cleared' tests/unit/SnapshotsTest.php`
Expected: FAIL — today's restore writes the payload back unconditionally, so the fenced, `already`, coded and claim tests all fail.

- [ ] **Step 4: Pass the record into the file restore, and retire a record whose write never landed**

In `restore()`'s `case 'file':`, the final line becomes:

```php
				return $this->restore_existing_file( (string) $record['target'], $bytes, $record );
```

And in `overwrite_file()`'s locked closure, the `replace_in_place()` failure branch stops leaving the record behind:

```php
				$replaced_ok = $this->replace_in_place( $path, $content );
				if ( true !== $replaced_ok ) {
					// Nothing to retire (Codex #101 round-6 P1). The record was
					// persisted WITHOUT `replaced_with_sha256`, so a write that
					// did not land leaves an UNFENCED record: restore refuses it
					// with `aura_snapshot_unfenced` and Aura mirrors it
					// unfenced and never offers it. The previous revision
					// stamped the hash up front and deleted-or-voided the record
					// on failure, which left the dangerous state reachable
					// whenever BOTH the delete and the void were refused — a
					// failure this shape cannot have, because the unsafe record
					// is never written in the first place.
					return array( 'success' => false, 'error' => $replaced_ok );
				}
				// THE FENCE IS STAMPED ONLY ONCE THE WRITE HAS LANDED. A stamp
				// that fails (a swept record, an unwritable directory) leaves
				// the record unfenced, which is the SAFE side: the write is a
				// fact and is reported as a success, and the record simply
				// cannot be restored from Aura.
				$sha    = hash( 'sha256', $content );
				$record = self::redact( $snap['snapshot'] );
				if ( $this->stamp_replaced_hash( $snap['snapshot']['id'], $sha ) ) {
					$record['replaced_with_sha256'] = $sha;
				}
				return array(
					'success'  => true,
					'snapshot' => $record,
					'bytes'    => strlen( $content ),
				);
```

The stamp, in the idiom `reinstate_record()` already uses — under the record's own lock, so a sweep that is voiding this record cannot be overwritten:

```php
	/**
	 * Stamp the overwrite fence onto a record whose write has landed.
	 *
	 * @param string $id  Snapshot id.
	 * @param string $sha sha256 of the content that was written.
	 * @return bool True when the record now carries the fence.
	 */
	protected function stamp_replaced_hash( $id, $sha ) {
		$done = $this->with_record_lock(
			$id,
			function () use ( $id, $sha ) {
				$meta_path = $this->dir . basename( (string) $id ) . '.json';
				$record    = $this->get( $id );
				if ( ! is_array( $record ) || ! empty( $record['voided'] ) ) {
					return false; // retired while we were writing: leave it unfenced
				}
				$record['replaced_with_sha256'] = (string) $sha;
				$json                           = wp_json_encode( $record );
				if ( false === $json ) {
					return false;
				}
				$n = @file_put_contents( $meta_path, $json ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The record file this class owns; a refusal is answered to the caller.
				return false !== $n && $n === strlen( $json ) && $this->sync_file( $meta_path );
			}
		);
		return true === $done;
	}
```

`void_record_in_place()` must drop the fence key too, or a voided record still looks restorable:

```php
		unset( $record['expected_sha256'], $record['staged'], $record['replaced_with_sha256'] ); // the overwrite fence goes with the create's (Codex #101 round-5 P1)
```

- [ ] **Step 5: Fence the restore — in-place for the no-write answers, claim-and-publish to write**

Replace the body of `restore_existing_file()` and add its helpers:

```php
	private function restore_existing_file( $target, $bytes, array $record = array() ) {
		if ( '' === $target ) {
			return array( 'success' => false, 'error' => 'Snapshot record carries no target.' );
		}
		$refused = $this->refuse_at_path( $target, false );
		if ( null !== $refused ) {
			return $this->restore_refusal( $refused );
		}
		$out = $this->with_target_lock(
			$target,
			function () use ( $target, $bytes, $record ) {
				return $this->restore_existing_file_locked( $target, $bytes, $record );
			}
		);
		return $this->locked_answer( $out, $target );
	}

	/**
	 * A path refusal, on the RESTORE path, is a designated "changed since"
	 * refusal: the file the record was taken from is not what is at the path
	 * now. `refuse_at_path()` itself is left alone — it is shared with the
	 * write paths, where a symlink is not a changed-since fact and must not
	 * become a 409 (Codex #101 round-1 P1). The wording is kept exactly as it
	 * is; only the code is added.
	 *
	 * @param array $refused refuse_at_path()'s answer.
	 * @return array
	 */
	private function restore_refusal( array $refused ) {
		$refused['code'] = 'aura_file_changed_since';
		return $refused;
	}

	/**
	 * The fenced restore proper, under the target's lock (Aura#520 §3.1).
	 *
	 * An in-place hash settles every answer that writes nothing. To WRITE, the
	 * path is claimed by rename() and the claimed file is re-verified — the
	 * lock holds SiteAgent's writers only, so the bytes that are verified and
	 * the bytes that are replaced must be one inode (Codex #101 round-1 P1).
	 *
	 * @param string $target The path.
	 * @param string $bytes  The snapshot's payload — what goes back.
	 * @param array  $record The record, for its fence.
	 * @return array { success, already?, code?, error?, detail?, moved_aside? }
	 */
	private function restore_existing_file_locked( $target, $bytes, array $record ) {
		if ( ! empty( $record['voided'] ) ) {
			// Retired because its write never landed (Codex #101 round-5 P1):
			// a designated refusal, not an unfenced record.
			return array(
				'success' => false,
				'code'    => 'aura_snapshot_voided',
				'error'   => 'The rollback record for this write was retired because the write did not land.',
			);
		}
		$replaced = isset( $record['replaced_with_sha256'] ) ? (string) $record['replaced_with_sha256'] : '';
		if ( '' === $replaced ) {
			return array(
				'success' => false,
				'code'    => 'aura_snapshot_unfenced',
				'error'   => 'This snapshot does not record what replaced the file, so a restore cannot prove the file is unchanged; nothing was written.',
			);
		}
		$refused = $this->refuse_at_path( $target, false ); // again, under the lock
		if ( null !== $refused ) {
			return $this->restore_refusal( $refused );
		}
		$current = is_file( $target ) ? hash_file( 'sha256', $target ) : false;
		if ( ! is_string( $current ) ) {
			return $this->changed_since( 'the file is gone, or is no longer a regular file' );
		}
		if ( hash_equals( hash( 'sha256', $bytes ), $current ) ) {
			return array( 'success' => true, 'already' => true ); // already back; nothing claimed, nothing written
		}
		if ( ! hash_equals( $replaced, $current ) ) {
			return $this->changed_since( 'the file no longer holds the content this write left there' );
		}
		// An executable on a host without link() cannot be republished at all
		// (fopen() cannot create execute bits — SiteAgent#96/#97), and claiming
		// it would strand it aside. The in-place hash above IS its verification;
		// it is replaced the 2.17.2 way, with that window as the documented
		// residual. Every other case claims first.
		$perms = @fileperms( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The file is there (hashed above); a false reads as "no execute bits".
		if ( ! $this->link_available() && false !== $perms && 0 !== ( $perms & 0111 ) ) {
			$done = $this->replace_in_place( $target, $bytes );
			return true === $done
				? array( 'success' => true )
				: array( 'success' => false, 'error' => 'Failed to write file: ' . $target . ' (' . $done . ')' );
		}
		return $this->publish_restored_bytes( $target, $bytes, $replaced );
	}

	/** The changed-since refusal, in one place. */
	private function changed_since( $detail, array $extra = array() ) {
		return array_merge(
			array(
				'success' => false,
				'code'    => 'aura_file_changed_since',
				'error'   => 'file_changed_since',
				'detail'  => $detail,
			),
			$extra
		);
	}

	/**
	 * Claim the path, re-verify what we hold, and publish the old bytes into
	 * it — no-clobber, so a file that arrives after the claim is never
	 * replaced. The claimed file is put back on every refusal, by the same
	 * primitives restore_created_file_locked() uses.
	 *
	 * @param string $target   The path.
	 * @param string $bytes    The payload to put back.
	 * @param string $replaced The hash the file must still have.
	 * @return array
	 */
	private function publish_restored_bytes( $target, $bytes, $replaced ) {
		// STAGE FIRST, while the target is still live (Codex #101 round-2 P2):
		// staging writes the whole payload, and doing it after the claim left
		// the pathname absent for the length of that write. The mode is read
		// from the live file for the same reason.
		$mode = @fileperms( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The file is there (hashed by the caller); a false falls back to the create mode.
		$tmp  = $this->stage( dirname( $target ), basename( $target ), $bytes, false === $mode ? null : ( $mode & 0777 ) );
		if ( is_array( $tmp ) ) {
			return array( 'success' => false, 'error' => (string) $tmp['error'] ); // nothing claimed, nothing moved
		}

		try {
			$suffix = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			$suffix = substr( md5( uniqid( '', true ) ), 0, 16 );
		}
		$claim = dirname( $target ) . '/.aura-restore-' . $suffix; // opaque, never a .php name
		if ( ! @rename( $target, $claim ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- The claim IS the point: atomic, inode-preserving.
			$this->discard_stage( $tmp );
			return self::path_present( $target )
				? array( 'success' => false, 'error' => 'Unable to claim file for restore: ' . $target )
				: $this->changed_since( 'the file was removed while the restore was being prepared' );
		}
		$this->after_claim( $claim, $target );

		// A RACED NON-FILE IS PUT STRAIGHT BACK (Codex #101 round-7 P1). A
		// writer can replace the verified file with a DIRECTORY — or a symlink
		// — between the in-place hash and the rename above, and the rename
		// moves whatever is there to the claim name. put_claim_back() can only
		// hard-link or copy a REGULAR file, so it would leave a directory
		// stranded under an opaque name with the target missing, which is the
		// opposite of the exact-match contract: a replacement we did not write
		// is left exactly where it is. rename() is the only primitive that
		// moves such an entry back whole.
		if ( is_link( $claim ) || ! is_file( $claim ) ) {
			$this->discard_stage( $tmp );
			return @rename( $claim, $target ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Putting a racer's entry back, atomically; a refusal is answered below.
				? $this->changed_since( 'something that is not a regular file took the path while the restore was being prepared' )
				: $this->changed_since( 'something that is not a regular file took the path and could not be put back', array( 'moved_aside' => $claim ) );
		}

		// THE authoritative check: the file we hold, not the name we read.
		$actual = hash_file( 'sha256', $claim );
		if ( ! is_string( $actual ) || ! hash_equals( $replaced, $actual ) ) {
			$this->discard_stage( $tmp );
			return $this->put_claim_back( $claim, $target, 'the file changed as the restore claimed it' );
		}

		// AND THE CLAIMED INODE OWNS THE MODE (Codex #101 round-4 P1). The mode
		// read before the claim is a guess by the time we publish: a chmod that
		// lands in between leaves the CONTENT untouched, so the hash above still
		// passes, and republishing at the older mode would discard a
		// restriction someone just applied. Re-read it from the file we hold
		// and carry it onto the stage.
		$claimed_mode = @fileperms( $claim ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- We hold this file; a false keeps the mode the stage already has.
		if ( false !== $claimed_mode ) {
			$claimed_mode &= 0777;
			if ( ! $this->link_available() && 0 !== ( $claimed_mode & 0111 ) ) {
				// It gained execute bits while we held it, and fopen() cannot
				// recreate those: put it back rather than republish it lesser.
				$this->discard_stage( $tmp );
				return $this->put_claim_back( $claim, $target, 'the file became executable while the restore ran, and this host has no link()' );
			}
			if ( ! $this->secure_stage( $tmp, $claimed_mode ) ) {
				$this->discard_stage( $tmp );
				return $this->put_claim_back( $claim, $target, 'the mode the file now has could not be set on the replacement' );
			}
		}

		// THE MODE MUST SURVIVE A link()-LESS PUBLISH (Codex #101 round-3 P1).
		// publish() reaches write_exclusively() with NO mode, which creates from
		// FS_CHMOD_FILE (0644) — restoring a 0600 file on a host without link()
		// would hand it to every local account. link() itself preserves the
		// stage's mode, and the stage was created with the target's own mode
		// above, so only the write path needs this. publish()'s signature is
		// deliberately NOT changed: seven test subclasses override
		// publish( $tmp, $path ), and PHP rejects an override that drops a
		// parameter the parent declares.
		$published = $this->link_available()
			? $this->publish( $tmp, $target ) // link() preserves the stage's mode, set from the claim just above
			: $this->publish_restored_by_write( $tmp, $target, false === $claimed_mode ? ( false === $mode ? null : ( $mode & 0777 ) ) : $claimed_mode );
		if ( true !== $published ) {
			$this->discard_stage( $tmp );
			if ( 'exists' === $published ) {
				// Something took the path while we held the file: never clobber it.
				return $this->changed_since( 'another file took the path while the restore was in flight', array( 'moved_aside' => $claim ) );
			}
			// OUR OWN FAILURE IS NOT A CHANGED-SINCE REFUSAL (Codex #101
			// round-5 P1). The write path above has already cleared any entry
			// it created, so the put-back can land; the answer is an execution
			// failure (no code → 500), because nothing about the file changed
			// — this restore simply could not write. `put_claim_back()`'s
			// refusal shape is asked for only when a RACER took the path.
			$out           = $this->put_claim_back( $claim, $target, 'the old bytes could not be published', false );
			$out['error']  = 'Failed to write file: ' . $target . ' (' . (string) $published . ')';
			return $out;
		}
		$this->discard_stage( $tmp ); // in link mode the stage is a second name of the published inode

		// THE CLAIM IS NOT DELETED ON TRUST (Codex #101 round-2 P1). A writer
		// that opened the target before the claim holds a descriptor on THIS
		// inode and may have written through it since the hash above; the
		// claim is now the only pathname those bytes have. Re-hash, and keep
		// the file — named — when it moved. The restore itself still
		// succeeded: the old bytes are at the path.
		$out   = array( 'success' => true );
		$after = hash_file( 'sha256', $claim );
		if ( ! is_string( $after ) || ! hash_equals( $actual, $after ) ) {
			$out['moved_aside'] = $claim;
			$out['detail']      = 'the replaced file was written to while the restore ran and was kept aside rather than deleted';
			return $out;
		}
		wp_delete_file( $claim );
		if ( file_exists( $claim ) ) {
			$out['moved_aside'] = $claim; // `.aura-restore-*` is never swept: say where it is
		}
		return $out;
	}

	/**
	 * The link()-less publish of a RESTORE: exclusive-create the target and
	 * stream the staged bytes into the handle we own, with the mode the file
	 * had. publish()'s own write path cannot be used here — it creates from
	 * FS_CHMOD_FILE and would widen a private file (Codex #101 round-3 P1).
	 * Execute bits never reach this path: that case is answered in place,
	 * before anything is claimed.
	 *
	 * @param string   $tmp    The staged payload.
	 * @param string   $target The path.
	 * @param int|null $mode   The mode the target had, or null for the default.
	 * @return true|string As publish(): true, 'exists', 'partial', or a sentence.
	 */
	private function publish_restored_by_write( $tmp, $target, $mode ) {
		$src = @fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Our own staged file; a refusal is answered below.
		if ( false === $src ) {
			return 'the staged bytes could not be read back';
		}
		$mode = ( null === $mode ? $this->create_mode() : (int) $mode ) & 0666; // execute bits are refused before this path
		$was  = umask( 0777 & ~$mode );
		$fh   = @fopen( $target, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 'x' is the no-clobber claim; EEXIST is the expected refusal.
		umask( $was );
		if ( false === $fh ) {
			fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return self::path_present( $target ) ? 'exists' : 'the target could not be created';
		}
		$mine = fstat( $fh );
		$real = is_array( $mine ) ? ( (int) $this->mode_of( $fh, $mine ) & 0777 ) : -1;
		if ( $real !== $mode ) {
			$this->remove_own_entry( $fh, $target, $mine );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return sprintf( 'the created entry has mode %o, not the %o asked for (a default ACL?)', $real, $mode );
		}
		$ok = $this->write_all( $fh, $src ) && fflush( $fh ) && ( function_exists( 'fsync' ) ? (bool) fsync( $fh ) : true );
		fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! $ok ) {
			// THE RESTORE CLEARS ITS OWN DAMAGE (Codex #101 round-5 P1). The
			// create path deliberately leaves its empty entry at the target —
			// it has nothing else to put there. A RESTORE does: it is holding
			// the healthy file under the claim, and that file cannot go back
			// while an empty or half-written entry of ours occupies the path.
			// The entry is emptied and then unlinked, by name, only while the
			// name still resolves to the inode we created.
			$this->truncate_to_empty( $fh );
			$removed = $this->remove_own_entry( $fh, $target, $mine );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return $removed
				? 'the write into the restored target was short; the path was cleared so the file could be put back'
				: 'partial';
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$now = @stat( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone is an answer.
		if ( ! is_array( $mine ) || ! is_array( $now ) || $now['ino'] !== $mine['ino'] || $now['dev'] !== $mine['dev'] ) {
			return self::path_present( $target ) ? 'exists' : 'the target was removed during the write';
		}
		return true;
	}

	/**
	 * Unlink an entry THIS call created, by name and only while the name still
	 * resolves to that inode — never a racer's replacement (the rule
	 * write_exclusively() states: nothing addresses the path by name once it
	 * is claimed, except under an inode check).
	 *
	 * Protected so a test can model a refusal.
	 *
	 * @param resource   $fh     Our open handle.
	 * @param string     $target The path.
	 * @param array|false $mine  fstat() of our handle.
	 * @return bool True when the path is clear of our entry.
	 */
	protected function remove_own_entry( $fh, $target, $mine ) {
		$now = @stat( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone is an answer.
		if ( ! is_array( $mine ) || ! is_array( $now ) || $now['ino'] !== $mine['ino'] || $now['dev'] !== $mine['dev'] ) {
			return false; // not ours any more
		}
		$this->before_entry_removal( $target );

		// CLAIM BEFORE DELETING (Codex #101 round-6 P1). stat-then-unlink is
		// two steps on a NAME: a writer who replaces the path in between loses
		// the file we would then unlink, which is exactly the no-clobber rule
		// this path exists to keep. rename() is atomic and inode-preserving, so
		// what is deleted is the entry we moved, and a racer's file — if that
		// is what we moved — is put straight back.
		try {
			$suffix = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			$suffix = substr( md5( uniqid( '', true ) ), 0, 16 );
		}
		$aside = dirname( $target ) . '/.aura-restore-' . $suffix;
		if ( ! @rename( $target, $aside ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- The claim IS the point: atomic, inode-preserving.
			return false;
		}
		$moved = @stat( $aside ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone is an answer.
		if ( ! is_array( $moved ) || $moved['ino'] !== $mine['ino'] || $moved['dev'] !== $mine['dev'] ) {
			// We moved somebody else's file. Put it back and touch nothing; a
			// refused put-back leaves it under an `.aura-restore-*` name, which
			// is never swept, rather than deleting a file that is not ours.
			@rename( $aside, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- A best-effort put-back; the failure is answered as "not cleared".
			return false;
		}
		wp_delete_file( $aside );
		return ! self::path_present( $aside );
	}

	/**
	 * Seam between verifying our entry and claiming it for deletion. Nothing
	 * in production; a test models a racer replacing the path here.
	 *
	 * @param string $target The path.
	 */
	protected function before_entry_removal( $target ) {
	}

	/**
	 * Put a claimed file back at its path and answer changed-since. link() is
	 * no-clobber; without it the claim is copied back by exclusive create. A
	 * put-back that cannot land leaves the file aside, named.
	 *
	 * @param string $claim   The claimed file.
	 * @param string $target  Its path.
	 * @param string $detail  Why the restore is being abandoned.
	 * @param bool   $refusal True → a designated changed-since refusal (409);
	 *                        false → an execution failure (500), for a write of
	 *                        OURS that failed while the file itself never
	 *                        changed (Codex #101 round-5 P1).
	 * @return array
	 */
	private function put_claim_back( $claim, $target, $detail, $refusal = true ) {
		$answer = function ( array $extra = array() ) use ( $detail, $refusal ) {
			return $refusal
				? $this->changed_since( $detail, $extra )
				: array_merge( array( 'success' => false, 'error' => $detail, 'detail' => $detail ), $extra );
		};
		if ( $this->link_available() ) {
			if ( @link( $claim, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- EEXIST is the expected refusal, classified below.
				wp_delete_file( $claim );
				return file_exists( $claim )
					? $answer( array( 'moved_aside' => $claim ) )
					: $answer();
			}
			return $answer( array( 'moved_aside' => $claim ) );
		}
		if ( true !== $this->put_back_by_write( $claim, $target ) ) {
			return $answer( array( 'moved_aside' => $claim ) );
		}
		$a = hash_file( 'sha256', $claim );
		$b = hash_file( 'sha256', $target );
		if ( ! is_string( $a ) || ! is_string( $b ) || ! hash_equals( $a, $b ) ) {
			return $answer( array( 'moved_aside' => $claim, 'detail' => $detail . '; the copy at its path may be behind the file kept aside' ) );
		}
		wp_delete_file( $claim );
		return file_exists( $claim )
			? $answer( array( 'moved_aside' => $claim ) )
			: $answer();
	}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'overwrite_restore|unfenced|bare_file_snapshot|external_write_after_the_claim|directory_or_symlink_at_the_path|descriptor_opened_before_the_claim|failed_stage_never_removes|restored_file_keeps_its_restrictive_mode|chmod_that_lands_after_the_claim|dangling_symlink_that_takes_the_path|write_never_lands_leaves_an_unfenced|stamp_that_fails|racer_that_takes_the_path_during_cleanup|directory_that_takes_the_path_after_the_claim|voided_create_record_answers_voided|short_restore_write_clears|partial_restore_write_that_cannot_be_cleared' tests/unit/SnapshotsTest.php`
Expected: PASS (20 tests).

- [ ] **Step 7: Run the whole suite and the linter**

Run: `composer test` — expected: green. The two tests named above are the only existing ones this changes; every other assertion reads `error` wording that is deliberately unchanged. If a THIRD test fails, do not weaken the fence to keep it green — read what it asserts and fix the test, or report it as a finding against this plan.
Run: `composer lint`.

- [ ] **Step 8: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-snapshots.php tests/unit/SnapshotsTest.php
git commit -m "feat(snapshots): an overwrite records what replaced the file, and its restore claims the path before it writes so only those exact bytes are replaced

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: codes on the create restore, the voided record and a held path

**Files:**
- Modify: `digitizer-site-worker/includes/class-aura-worker-snapshots.php` — `restore_created_file_locked()` (~lines 2060–2180), `locked_answer()` (~line 988)
- Test: `tests/unit/SnapshotsTest.php`

**Interfaces:**
- Consumes: nothing from Tasks 1–2.
- Produces: `aura_file_changed_since` on every create-restore refusal that is a changed file, `already: true` when the created file is already gone, `aura_snapshot_voided` for a record whose hash was voided, `aura_path_locked` whenever the target lock is held.

- [ ] **Step 1: Write the failing tests**

```php
	public function test_a_created_file_already_gone_answers_already(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/created.php';
		$rec   = $snaps->create_file( $file, "<?php // new\n" )['snapshot'];
		unlink( $file );

		$out = $snaps->restore( $rec['id'] );

		$this->assertTrue( $out['success'] );
		$this->assertTrue( $out['already'] );
	}

	public function test_a_directory_at_the_created_path_answers_the_changed_code(): void {
		// Codex #101 round-1 P1: this refusal had no code, so the REST layer
		// answered 500 for a designated changed-since refusal.
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/created-dir.php';
		$rec   = $snaps->create_file( $file, "<?php // new\n" )['snapshot'];
		unlink( $file );
		mkdir( $file, 0755 );

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertStringContainsString( 'not a regular file', $out['error'] );
		$this->assertDirectoryExists( $file, 'the directory is untouched' );
	}

	public function test_a_created_file_edited_since_answers_the_changed_code(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/created-edited.php';
		$rec   = $snaps->create_file( $file, "<?php // new\n" )['snapshot'];
		file_put_contents( $file, "<?php // edited\n" );

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_file_changed_since', $out['code'] );
		$this->assertSame( 'file_changed_since', $out['error'] );
	}

	public function test_a_voided_record_answers_the_voided_code(): void {
		$snaps = new class extends Aura_Worker_Snapshots {
			public function void( $id ) {
				return $this->void_record_in_place( $id, array( 'interrupted' => true ) );
			}
		};
		$file = WP_CONTENT_DIR . '/voided.php';
		$rec  = $snaps->create_file( $file, "<?php // new\n" )['snapshot'];
		$this->assertTrue( $snaps->void( $rec['id'] ) );

		$out = $snaps->restore( $rec['id'] );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_snapshot_voided', $out['code'] );
		$this->assertFileExists( $file, 'a voided record never deletes the file' );
	}

	public function test_a_held_path_answers_the_locked_code(): void {
		// target_lock_tries() is the seam: one 20 ms attempt, and the lock is
		// already held by a handle this test keeps open.
		$snaps = new class extends Aura_Worker_Snapshots {
			protected function target_lock_tries() {
				return 1;
			}
		};
		$file = WP_CONTENT_DIR . '/held.php';
		file_put_contents( $file, "<?php // v0\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // v1\n" )['snapshot'];

		$lock = WP_CONTENT_DIR . '/aura-backups/snapshots/path-' . sha1( $file ) . '.lock';
		$fh   = fopen( $lock, 'cb' );
		$this->assertTrue( flock( $fh, LOCK_EX | LOCK_NB ) );

		$out = $snaps->restore( $rec['id'] );

		flock( $fh, LOCK_UN );
		fclose( $fh );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 'aura_path_locked', $out['code'] );
		$this->assertSame( 'locked', $out['error'] );
	}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter 'already|changed_code|voided_code|locked_code' tests/unit/SnapshotsTest.php`
Expected: FAIL — none of the four answers carries a `code` today, and the already-gone answer has no `already` key.

- [ ] **Step 3: Add the codes**

In `locked_answer()`, the held-lock answer gains its code (the unavailable-lock answer stays an execution failure — nothing could be created, which is not a designated refusal):

```php
		if ( null === $out ) {
			return array(
				'success' => false,
				'code'    => 'aura_path_locked',
				'error'   => 'locked',
				'detail'  => 'another write to this path is in progress: ' . $path,
			);
		}
```

In `restore_created_file_locked()`, the "already gone" answer at the top of the method gains `already`:

```php
		if ( ! self::path_present( $target ) ) {
			return array( 'success' => true, 'already' => true ); // already gone
		}
```

the voided record's answer gains its code AND moves to the top of the method, ahead of the already-gone shortcut (Codex #101 round-7 P2). A voided record's target is usually absent — the create never landed — so `path_present()` answered a cheerful `200 already` for a record retired precisely because nothing can be restored from it, and a rollback counted it as undone. The head of `restore_created_file_locked()` becomes:

```php
	private function restore_created_file_locked( array $record, $target ) {
		// RETIRED FIRST, GONE SECOND. A record with no expected hash was voided
		// (a sweep, or an interrupted publish), and its target is usually
		// absent — so the already-gone branch below would report success for a
		// record that cannot restore anything (Codex #101 round-7 P2).
		$expected = isset( $record['expected_sha256'] ) ? (string) $record['expected_sha256'] : '';
		if ( '' === $expected ) {
			return array(
				'success' => false,
				'code'    => 'aura_snapshot_voided',
				'error'   => 'Snapshot record carries no expected hash.', // unchanged wording; only the code is added
			);
		}
		if ( ! self::path_present( $target ) ) {
			return array( 'success' => true, 'already' => true ); // already gone
		}
```

and the `$expected` block that used to sit BELOW the `is_file()` check is deleted, since it has moved up. A record that is not voided still answers `already` for an absent target, which is what `test_restoring_a_created_file_that_is_already_gone_succeeds` asserts.

the not-a-regular-file branch (~line 2074), which today refuses a directory or a dangling symlink with no code at all and so reaches Aura as a 500 (Codex #101 round-1 P1) — the wording stays, exactly as `test_a_dangling_symlink_at_the_created_path_is_reported_never_treated_as_gone` asserts it:

```php
		if ( ! is_file( $target ) ) {
			return array(
				'success' => false,
				'code'    => 'aura_file_changed_since',
				'error'   => 'Target is not a regular file: ' . $target,
			);
		}
```

and **both** `file_changed_since` answers gain theirs — the in-place executable check (~line 2090) and the put-back branch (~line 2135). Add the key to each array, leaving `error` and `moved_aside` exactly as they are:

```php
					return array(
						'success' => false,
						'code'    => 'aura_file_changed_since',
						'error'   => 'file_changed_since',
						'detail'  => 'the file is executable and link() is unavailable, so it was verified in place and left untouched',
					);
```

```php
		$out = array( 'success' => false, 'code' => 'aura_file_changed_since', 'error' => 'file_changed_since' );
```

Also, in `restore_created_file()`, the vanished-target answer inside the claim branch (~line 2113, `array( 'success' => true )` — "it vanished between exists() and the claim") gains `'already' => true`, so every already-gone path answers alike.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'already|changed_code|voided_code|locked_code' tests/unit/SnapshotsTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the whole suite and the linter**

Run: `composer test` — expected: green. Existing tests that assert `error === 'file_changed_since'` or `'locked'` still pass: the `error` strings are unchanged and only a key was added.
Run: `composer lint`.

- [ ] **Step 6: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-snapshots.php tests/unit/SnapshotsTest.php
git commit -m "feat(snapshots): designated codes for a changed, voided or locked file restore, and already:true when the created file is gone

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: the REST surface — 409 per code, and no door entry for a file restore

**Files:**
- Create: `tests/unit/SnapshotsRestoreCodesTest.php`
- Test: itself. **No production code changes** — this task proves the layer above already behaves, and pins it.

**Interfaces:**
- Consumes: Tasks 2–3's codes.
- Produces: nothing; this is the contract Aura's §5 answer mapping is written against.

- [ ] **Step 1: Write the tests**

```php
<?php
/**
 * The REST surface of a file restore (Aura#520 §3.1): every designated
 * refusal is a 409 the caller can map, and a file restore never opens a
 * door-log entry — Aura relies on that, because a door entry would become a
 * second agent action for one restore.
 *
 * @package Aura_Worker\Tests
 */

use PHPUnit\Framework\TestCase;

final class SnapshotsRestoreCodesTest extends TestCase {

	/** @var Aura_Worker_API */
	private $api;

	protected function setUp(): void {
		sa_reset_state();
		$this->rrmdir( WP_CONTENT_DIR );
		mkdir( WP_CONTENT_DIR, 0755, true );
		Aura_Worker_Elementor_Door::reset_for_tests();
		Aura_Worker_Elementor_Door::init();
		$GLOBALS['_current_user_id'] = 3;
		$GLOBALS['_user_logins'][3]  = 'bot';
		$GLOBALS['_options']['aura_worker_site_token'] = Aura_Worker_Security::hash_token( 'tok' );
		$GLOBALS['_options'][ Aura_Worker_Rules::OPTION ] = array(
			'envelope'    => 'x.y',
			'seq'         => 5,
			'issued_at'   => '2026-09-02T00:00:00Z',
			'received_at' => time(),
			'rules'       => array(),
		);
		$this->api = new Aura_Worker_API( new Aura_Worker_Security() );
	}

	protected function tearDown(): void {
		$this->rrmdir( WP_CONTENT_DIR );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) {
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->rrmdir( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	private function request( array $params ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_header( 'X-Aura-Token', 'tok' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	public function test_a_changed_file_is_a_409_with_its_code(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/rest-changed.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];
		file_put_contents( $file, "<?php // edited\n" );

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['id'] ) ) );

		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_file_changed_since', $res->get_data()['code'] );
	}

	public function test_an_unfenced_record_is_a_409_with_its_code(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/rest-unfenced.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->snapshot_file( $file )['snapshot'];
		file_put_contents( $file, "<?php // edited\n" );

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['id'] ) ) );

		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'aura_snapshot_unfenced', $res->get_data()['code'] );
	}

	public function test_a_fenced_restore_succeeds_with_200(): void {
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/rest-ok.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$res = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['id'] ) ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( "<?php // original\n", file_get_contents( $file ) );
	}

	public function test_a_file_restore_opens_no_door_log_entry(): void {
		// A `file` record has no door_kind, so restore_snapshot() never
		// reserves an entry. Aura counts on this: an entry would surface as a
		// second agent action for one restore (Aura#520 §3.1).
		$snaps = new Aura_Worker_Snapshots();
		$file  = WP_CONTENT_DIR . '/rest-door.php';
		file_put_contents( $file, "<?php // original\n" );
		$rec = $snaps->overwrite_file( $file, "<?php // written\n" )['snapshot'];

		$before = Aura_Worker_Door_Log::log_after( 0 );
		$res    = $this->api->restore_snapshot( $this->request( array( 'id' => $rec['id'], 'aura_ref' => 'act_file_1' ) ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $before, Aura_Worker_Door_Log::log_after( 0 ), 'the door log is untouched' );
		$this->assertNull( Aura_Worker_Door_Log::get( 1 ), 'no entry was opened' );
	}
}
```

- [ ] **Step 2: Run them**

Run: `vendor/bin/phpunit tests/unit/SnapshotsRestoreCodesTest.php`
Expected: PASS (4 tests) with no production change — the codes come from Tasks 2–3 and `restore_after_admission()` already maps a `code` to 409. If a test fails here, the bug is in Task 2 or 3, not in the REST layer: fix it there.

- [ ] **Step 3: Run the whole suite and the linter**

Run: `composer test` — expected: green.
Run: `composer lint`.

- [ ] **Step 4: Commit**

```bash
git add tests/unit/SnapshotsRestoreCodesTest.php
git commit -m "test(snapshots): each file-restore refusal is a 409 with its code, and a file restore opens no door entry

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: remove the duplicated restore response statement

**Files:**
- Modify: `digitizer-site-worker/includes/class-aura-worker-api.php:1540`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing. A pre-existing defect on `main`, found while writing this plan and folded in on the owner's instruction.

Line 1540 holds the restore route's response statement **twice on one physical line**, separated by two tabs:

```php
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
```

The second copy is unreachable — the first returns — so behaviour is unchanged either way, and the suite and PHPCS are green with it. It is removed because it is wrong, not because it breaks: the next reader of this function should not have to work out which copy runs.

- [ ] **Step 1: Confirm the line before touching it**

Run: `sed -n 1540p digitizer-site-worker/includes/class-aura-worker-api.php | od -c | tail -6`
Expected: the statement's bytes appear twice, with `\t \t` between them and a single `\n` at the end.

- [ ] **Step 2: Replace the line with one statement**

```php
		return new WP_REST_Response( Aura_Worker_Rules::with_warnings( $result ), $status );
```

- [ ] **Step 3: Verify exactly one copy remains, and nothing else moved**

Run: `sed -n 1536,1541p digitizer-site-worker/includes/class-aura-worker-api.php`
Expected: the three comment lines, the `$status` line, ONE return, and the closing brace.
Run: `git diff --stat digitizer-site-worker/includes/class-aura-worker-api.php`
Expected: `1 file changed, 1 insertion(+), 1 deletion(-)`.

- [ ] **Step 4: Run the suite and the linter**

Run: `composer test` — expected: green, and the same test count as the previous task left.
Run: `composer lint` — expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-api.php
git commit -m "fix(api): drop the duplicated, unreachable restore response statement

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: release 2.17.3

**Files:**
- Modify: `digitizer-site-worker/digitizer-site-worker.php:6` (`Version:`) and `:21` (`AURA_WORKER_VERSION`)
- Modify: `digitizer-site-worker/readme.txt:7` (`Stable tag:`) and its `== Changelog ==` section
- Modify: `README.md:20` (the `Stable-2.17.2-green` badge) and its `## Changelog` section

**Interfaces:**
- Consumes: Tasks 1–5.
- Produces: the version Power Pack 0.2.6 pins as its submodule (plan 2).

- [ ] **Step 1: Bump the plugin header and the constant**

```php
 * Version:           2.17.3
```
```php
define( 'AURA_WORKER_VERSION', '2.17.3' );
```

- [ ] **Step 2: Add the `readme.txt` entry and bump the stable tag**

`Stable tag: 2.17.3`, and directly under `== Changelog ==`:

```
= 2.17.3 =
* Snapshots: an `overwrite_file()` record now stores the sha256 of the content it wrote (`replaced_with_sha256`), and restoring that record puts the old bytes back ONLY while the file still holds exactly what the write left there. A file edited since is refused untouched (`aura_file_changed_since`); a file already holding the old bytes answers `already` and is not rewritten; a record taken without that hash — a direct `POST /aura/v2/snapshot`, or a Power Pack older than 0.2.5 — is refused as unfenced (`aura_snapshot_unfenced`), because nothing proves what it would be writing over.
* Snapshots: every engine write to a path records a `write_seq`, taken under that path's lock and strictly increasing per target, so two writes to one file can be ordered after the fact — Aura rolls a run back in that order.
* Snapshots: a file restore that cannot act says why with a code instead of a bare failure — `aura_file_changed_since`, `aura_snapshot_unfenced`, `aura_snapshot_voided` (the create's rollback record is gone) and `aura_path_locked` (another write to the path is in progress) — each answered as an HTTP 409 refusal rather than a 500. Restoring a created file that is already gone answers `already`.
```

- [ ] **Step 3: Check the changelog budget**

Run: `php .github/scripts/check-readme-limits.php`
Expected: PASS with positive headroom (367 words before this entry; the entry above is ~215). If it fails, MOVE the oldest entries byte-for-byte into `docs/changelog-archive.md` and advance the `= <version> and earlier =` stub — never compress an entry.

- [ ] **Step 4: Add the `README.md` entry and badge**

Badge: `Stable-2.17.3-green`. Under `## Changelog`, above `### 2.17.2`:

```markdown
### 2.17.3

- **Snapshots: the overwrite restore is fenced.** An `overwrite_file()` record stores the sha256 of what it wrote (`replaced_with_sha256`), and its restore writes the old bytes back only while the file still hashes to exactly that (Aura#520). Edited since → `aura_file_changed_since`, untouched; already holding the old bytes → `already: true`, not rewritten; no hash in the record (a direct `POST /aura/v2/snapshot`, or Power Pack < 0.2.5) → `aura_snapshot_unfenced`, because nothing proves what the write would land on.
- **Snapshots: a per-target `write_seq`.** Every engine write records a sequence taken under the target's lock and kept in a `path-<sha1>.seq` sidecar beside it — `max(previous + 1, now µs)`, so it increases per target whatever the clock does. Aura orders a run's rollback by it: nothing it observes does, since it validates the render after the site answers and stamps the action's finish time only then, which can invert two concurrent writes to one file. A sidecar it cannot read or write leaves the record without a sequence rather than inventing one.
- **Snapshots: designated codes on the file-restore path.** `aura_file_changed_since`, `aura_snapshot_unfenced`, `aura_snapshot_voided`, `aura_path_locked` — each a 409 refusal rather than a 500 execution failure. Restoring a created file that is already gone answers `already: true`. A file restore still opens no door-log entry (now pinned by a test).
```

- [ ] **Step 5: Verify the whole release**

Run: `composer test` (expected green), `composer lint` (expected clean), `php .github/scripts/check-readme-limits.php` (expected pass).
Run: `grep -rn "2\.17\.3" digitizer-site-worker/digitizer-site-worker.php digitizer-site-worker/readme.txt README.md` — expected: five hits (header, constant, stable tag, readme entry, README entry) plus the badge line.

- [ ] **Step 6: Commit**

```bash
git add digitizer-site-worker/digitizer-site-worker.php digitizer-site-worker/readme.txt README.md
git commit -m "chore(release): 2.17.3 — the fenced file restore, the per-target write sequence, and designated refusal codes

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## After the tasks

1. Open the PR against `main` and run the Codex review loop (cap: 8 rounds), watching CI in parallel.
2. Merge, pre-release, staging field check, stable release and rollout are **each** the owner's call, one approval per step (spec §8 step 1). The staging check runs with `link()` disabled, the way web PHP runs on Cloudways: `php -d disable_functions=link /usr/local/bin/wp eval-file …` — overwrite restore unchanged / edited / already, and a create restore of a file already gone.
3. Only then plan 2 (Power Pack 0.2.6) pins this version as its submodule.
