# Central analysis storage implementation plan

1. Create four installation-wide tables using `base_prefix`: analysis_runs, analysis_shortcodes, analysis_blocks and analysis_mediaindex (all prefixed `rrze_msm_`). Scope all operations by network and website; use run identities for isolation.
2. Write bounded shortcode/block findings incrementally into separate tables, including unfinished results. Persist only bounded checkpoints; publish a completed generation after both phases succeed. Read small monitoring summaries and paginated details.
3. Move storage checkpoints and bounded media-index segments out of evictable transients. Keep existing byte budgets, worker admission and conservative missing-segment handling.
4. Remove old analysis results and runtime keys without deserializing them, using resumable site/key batches. Mark affected sites as requiring reanalysis. Preserve unrelated data, settings and scheduling preferences.
5. Add regression coverage, update runtime documentation and regenerate build/translation artifacts. Report integration-test limitations explicitly.

No old analysis is imported. Cleanup must finish for a website before new analysis data is written. Completed compact storage summaries remain non-autoloaded options; the large temporary media index lives only in the central table.

## Implementation status

All five implementation steps are complete in the working tree. The new source is in `includes/Analysis/`. The storage resource and central persistence standalone regressions pass with a 64-MiB PHP memory limit; PHP syntax checks and the development build are also run. WordPress integration coverage was added in `tests/Unit/AnalysisStoreTest.php` and the scheduler test was adapted.

The local WordPress PHPUnit suite cannot run because `vendor/bin/phpunit` and a configured disposable WordPress test database are unavailable. MySQL/MariaDB `dbDelta()`, locking/concurrency, real object-cache behaviour, UI rendering and an 800-site production-like load test remain deployment validation tasks. SQLite-backed isolated tests do not replace them.
