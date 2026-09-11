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

Run: `vendor/bin/phpunit --filter 'write_seq|sequence_sidecar' tests/unit/SnapshotsTest.php`
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
				$extra = array( 'replaced_with_sha256' => hash( 'sha256', $content ) );
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

Run: `vendor/bin/phpunit --filter 'write_seq|sequence_sidecar' tests/unit/SnapshotsTest.php`
Expected: PASS (4 tests).

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

**One documented residual.** On a host without `link()`, an **executable** target cannot be republished at all: `publish_by_write()` and `put_back_by_write()` both refuse execute bits, because `fopen()` cannot create them (SiteAgent#96, #97). Claiming such a file would strand it aside. So for that case only — no `link()` AND the target is executable — the restore verifies in place and replaces with `replace_in_place()`, keeping the 2.17.2 behaviour and its narrow window. This mirrors the created-file restore's own executable branch, which 2.17.2 added for the same reason.

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

Run: `vendor/bin/phpunit --filter 'overwrite_restore|unfenced|bare_file_snapshot|external_write_after_the_claim|directory_or_symlink_at_the_path|descriptor_opened_before_the_claim|failed_stage_never_removes' tests/unit/SnapshotsTest.php`
Expected: FAIL — today's restore writes the payload back unconditionally, so the fenced, `already`, coded and claim tests all fail.

- [ ] **Step 4: Pass the record into the file restore**

In `restore()`'s `case 'file':`, the final line becomes:

```php
				return $this->restore_existing_file( (string) $record['target'], $bytes, $record );
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

		// THE authoritative check: the file we hold, not the name we read.
		$actual = hash_file( 'sha256', $claim );
		if ( ! is_string( $actual ) || ! hash_equals( $replaced, $actual ) ) {
			$this->discard_stage( $tmp );
			return $this->put_claim_back( $claim, $target, 'the file changed as the restore claimed it' );
		}

		$published = $this->publish( $tmp, $target );
		if ( true !== $published ) {
			$this->discard_stage( $tmp );
			if ( 'exists' === $published ) {
				// Something took the path while we held the file: never clobber it.
				return $this->changed_since( 'another file took the path while the restore was in flight', array( 'moved_aside' => $claim ) );
			}
			$out = $this->put_claim_back( $claim, $target, 'the old bytes could not be published' );
			return array_merge( $out, array( 'error' => 'Failed to write file: ' . $target . ' (' . (string) $published . ')', 'code' => null ) );
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
	 * Put a claimed file back at its path and answer changed-since. link() is
	 * no-clobber; without it the claim is copied back by exclusive create. A
	 * put-back that cannot land leaves the file aside, named.
	 *
	 * @param string $claim  The claimed file.
	 * @param string $target Its path.
	 * @param string $detail Why the restore is being abandoned.
	 * @return array
	 */
	private function put_claim_back( $claim, $target, $detail ) {
		if ( $this->link_available() ) {
			if ( @link( $claim, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- EEXIST is the expected refusal, classified below.
				wp_delete_file( $claim );
				return file_exists( $claim )
					? $this->changed_since( $detail, array( 'moved_aside' => $claim ) )
					: $this->changed_since( $detail );
			}
			return $this->changed_since( $detail, array( 'moved_aside' => $claim ) );
		}
		if ( true !== $this->put_back_by_write( $claim, $target ) ) {
			return $this->changed_since( $detail, array( 'moved_aside' => $claim ) );
		}
		$a = hash_file( 'sha256', $claim );
		$b = hash_file( 'sha256', $target );
		if ( ! is_string( $a ) || ! is_string( $b ) || ! hash_equals( $a, $b ) ) {
			return $this->changed_since( $detail . '; the copy at its path may be behind the file kept aside', array( 'moved_aside' => $claim ) );
		}
		wp_delete_file( $claim );
		return file_exists( $claim )
			? $this->changed_since( $detail, array( 'moved_aside' => $claim ) )
			: $this->changed_since( $detail );
	}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'overwrite_restore|unfenced|bare_file_snapshot|external_write_after_the_claim|directory_or_symlink_at_the_path|descriptor_opened_before_the_claim|failed_stage_never_removes' tests/unit/SnapshotsTest.php`
Expected: PASS (10 tests).

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

the voided record's answer gains its code:

```php
		$expected = isset( $record['expected_sha256'] ) ? (string) $record['expected_sha256'] : '';
		if ( '' === $expected ) {
			return array(
				'success' => false,
				'code'    => 'aura_snapshot_voided',
				'error'   => 'Snapshot record carries no expected hash.', // unchanged wording; only the code is added
			);
		}
```

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
Expected: PASS (4 tests).

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
