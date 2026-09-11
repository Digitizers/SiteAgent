# SiteAgent 2.17.3 — the fenced file restore and the per-target write sequence — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship SiteAgent 2.17.3 — the site half of Aura#520: an overwrite record that records what replaced it (`replaced_with_sha256`), a restore of that record that writes only while the file still holds exactly those bytes, a per-target `write_seq` that orders two writes to one file, and a designated refusal code on every file-restore refusal so Aura can map them to 409 instead of guessing at a 500.

**Architecture:** Everything lands in one class, `Aura_Worker_Snapshots`, behind the per-path lock 2.17.2 introduced — the fence is checked and the write happens under the same `with_target_lock()` section, so nothing can change at the path between the check and the rename. `write_seq` is a small sidecar file beside that lock (`path-<sha1>.seq`), read and written under it. No REST change is needed: `restore_after_admission()` already maps "the answer carries a `code`" to HTTP 409, so the four new codes arrive as designated refusals for free.

**Tech Stack:** PHP 7.4+ (CI matrix 7.4 / 8.1 / 8.2), WordPress 6.2+, PHPUnit (`composer test`, or `vendor/bin/phpunit --filter <name>`), PHPCS (`composer lint`). The test harness stubs WordPress — `tests/bootstrap.php`, no WordPress loaded — and writes real files under `WP_CONTENT_DIR`.

**Spec:** `Digitizers/Aura` — `docs/superpowers/specs/2026-09-11-site-file-snapshot-restore-design.md`, §3.1 (this plan), merged as `db7d7738`. Read §3.1 and §2 before starting. This is plan 1 of 3: plan 2 is Power Pack 0.2.6 (`snapshot_fenced` + `write_seq` forwarded), plan 3 is the Aura mirror and restore path. They ship in that order (spec §8).

**Baseline:** `composer test` on `main` at `8c174b8` — 2268 tests, 9959 assertions, green (10 PHP deprecations and 1 PHPUnit deprecation are pre-existing; they must not increase).

## Global Constraints

- **Every new refusal carries a `code`.** `aura_snapshot_unfenced`, `aura_file_changed_since`, `aura_snapshot_voided`, `aura_path_locked` — spelled exactly so. `restore_after_admission()` maps `isset( $result['code'] )` to **409**; an answer without a code is a 500 and means an execution failure, not a refusal (spec §3.1).
- **The fence is exact-match, both ways.** A restore of an overwrite record writes only when the file hashes to the record's `replaced_with_sha256`; when it already hashes to the payload it answers `{ success: true, already: true }` and writes nothing; anything else — different bytes, gone, not a regular file — is `aura_file_changed_since` and nothing is written (spec §3.1).
- **No hash, no restore.** A `kind: file` record with `existed !== false` and no `replaced_with_sha256` answers `aura_snapshot_unfenced` and writes nothing. Records taken by a direct `snapshot_file()` (REST `POST /aura/v2/snapshot`, an old Power Pack's fallback path) are unfenced by construction and are refused — a deliberate behaviour change, called out in the changelog (spec §2 Q3: fail closed).
- **`write_seq` is taken under the target lock, before the record is persisted**, as `max(previous + 1, now in microseconds)` with `previous` from the sidecar `path-<sha1>.seq`, and the sidecar is written back before the record (spec §3.1).
- **A `write_seq` that cannot be established is ABSENT, never guessed.** An unreadable or non-decimal sidecar, a sidecar that cannot be written, or a 32-bit build (`PHP_INT_SIZE < 8`, where microseconds overflow) leaves the record without the key; the write itself still proceeds. Aura mirrors such a row unfenced (spec §3.1).
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
| `tests/unit/SnapshotsTest.php` | Tasks 1–3: engine tests. One EXISTING test changes behaviour — `test_file_snapshot_and_restore_roundtrip` (line 44), which restores a bare `snapshot_file()` record and must now assert the `aura_snapshot_unfenced` refusal, with a new roundtrip through `overwrite_file()` beside it |
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
			$prev = (int) trim( $raw );
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
- Modify: `digitizer-site-worker/includes/class-aura-worker-snapshots.php` — `restore()`'s `case 'file'` (~line 2345) passes the record; `restore_existing_file()` (~line 2007) gains the fence and a locked half
- Modify: `tests/unit/SnapshotsTest.php` — `test_file_snapshot_and_restore_roundtrip` (line 44) changes behaviour
- Test: `tests/unit/SnapshotsTest.php`

**Interfaces:**
- Consumes: Task 1's records (`replaced_with_sha256` is written by `overwrite_file()`).
- Produces: `restore()` of an overwrite record answers one of `{ success: true }`, `{ success: true, already: true }`, `{ success: false, code: 'aura_snapshot_unfenced', error }`, `{ success: false, code: 'aura_file_changed_since', error: 'file_changed_since', detail }`.

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
	}

	public function test_an_overwrite_restore_of_a_file_edited_since_is_refused_and_touches_nothing(): void {
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

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter 'overwrite_restore|unfenced|bare_file_snapshot' tests/unit/SnapshotsTest.php`
Expected: FAIL — today's restore writes the payload back unconditionally, so the "edited since", "already" and "unfenced" tests all fail.

- [ ] **Step 3: Pass the record into the file restore**

In `restore()`'s `case 'file':`, the final line becomes:

```php
				return $this->restore_existing_file( (string) $record['target'], $bytes, $record );
```

- [ ] **Step 4: Fence the restore, under the lock**

Replace the body of `restore_existing_file()` and add its locked half:

```php
	private function restore_existing_file( $target, $bytes, array $record = array() ) {
		if ( '' === $target ) {
			return array( 'success' => false, 'error' => 'Snapshot record carries no target.' );
		}
		$refused = $this->refuse_at_path( $target, false );
		if ( null !== $refused ) {
			return $refused;
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
	 * The fenced restore proper, under the target's lock (Aura#520 §3.1): the
	 * old bytes go back only while the file still holds exactly what the write
	 * left there. The check and the rename are one locked section, so nothing
	 * can change at the path in between.
	 *
	 * @param string $target The path.
	 * @param string $bytes  The snapshot's payload — what goes back.
	 * @param array  $record The record, for its fence.
	 * @return array { success, already?, code?, error?, detail? }
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
			return $refused;
		}
		$current = is_file( $target ) ? hash_file( 'sha256', $target ) : false;
		if ( ! is_string( $current ) ) {
			return array(
				'success' => false,
				'code'    => 'aura_file_changed_since',
				'error'   => 'file_changed_since',
				'detail'  => 'the file is gone, or is no longer a regular file',
			);
		}
		if ( hash_equals( hash( 'sha256', $bytes ), $current ) ) {
			return array( 'success' => true, 'already' => true ); // already back; write nothing
		}
		if ( ! hash_equals( $replaced, $current ) ) {
			return array(
				'success' => false,
				'code'    => 'aura_file_changed_since',
				'error'   => 'file_changed_since',
				'detail'  => 'the file no longer holds the content this write left there',
			);
		}
		$replaced_ok = $this->replace_in_place( $target, $bytes );
		return true === $replaced_ok
			? array( 'success' => true )
			: array( 'success' => false, 'error' => 'Failed to write file: ' . $target . ' (' . $replaced_ok . ')' );
	}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'overwrite_restore|unfenced|bare_file_snapshot' tests/unit/SnapshotsTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Run the whole suite and the linter**

Run: `composer test` — expected: green. Any OTHER test that fails here is restoring a bare `snapshot_file()` record; the only one on `main` is the roundtrip replaced in Step 1. Do not weaken the fence to keep a test green — update the test to go through `overwrite_file()`.
Run: `composer lint`.

- [ ] **Step 7: Commit**

```bash
git add digitizer-site-worker/includes/class-aura-worker-snapshots.php tests/unit/SnapshotsTest.php
git commit -m "feat(snapshots): an overwrite records what replaced the file, and its restore writes only while the file still holds exactly that

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
				'error'   => 'Snapshot record carries no expected hash; the rollback record for this create is gone.',
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
