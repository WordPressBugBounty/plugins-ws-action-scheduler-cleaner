=== WS Action Scheduler Cleaner ===
Contributors: winningsolutions
Donate link: https://www.winning-solutions.de
Requires at least: 6.3
Tested up to: 7.0
Stable tag: 1.3.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optimize your WordPress database by efficiently managing the Action Scheduler tables used by popular plugins like WooCommerce.

== Description ==

WS Action Scheduler Cleaner is a small tool designed to optimize your WordPress database by managing the Action Scheduler tables. Action Scheduler, a robust job queue and background processing library, is utilized by many popular plugins such as WooCommerce, WP Forms, and Jetpack to handle resource-intensive tasks asynchronously.

While Action Scheduler is crucial for the smooth operation of these plugins, its tables can grow significantly over time, potentially impacting your site's performance. This is where WS Action Scheduler Cleaner comes in.

Key features:

* Easily clear completed, failed, or canceled actions from the Action Scheduler tables
* Set up automatic clearing schedules for efficient database maintenance
* Optimize the database tables to reclaim unused space and potentially improve performance
* Customize retention periods for logs and actions
* User-friendly interface integrated into the WordPress admin area

By regularly cleaning up unnecessary data from Action Scheduler tables, you can:

* Improve database query performance
* Reduce database size
* Enhance overall site speed and responsiveness

WS Action Scheduler Cleaner is an essential tool for any WordPress site using plugins that rely on Action Scheduler, especially high-traffic e-commerce sites or membership platforms that generate a large number of scheduled actions.

== Installation ==

1. Upload the `action-scheduler-cleaner` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Tools > Action Scheduler Cleaner to access the interface.

== Frequently Asked Questions ==

= Is this plugin compatible with WooCommerce? =

Yes, Action Scheduler Cleaner is fully compatible with WooCommerce and other plugins that use Action Scheduler.

= Will this plugin affect my scheduled actions? =

No, this plugin only clears completed, failed, or canceled actions. It does not interfere with pending or in-progress actions.

= What PHP and WordPress versions are required? =

Requires WordPress 6.3 or newer and PHP 7.4 or newer (compatible with PHP 8.5).

= Why do I only see Pending (and All) on the Action Scheduler admin screen? =

Action Scheduler only shows status tabs when at least one action has that status in the database. This plugin does not remove or hide those tabs. If Complete or Failed never appear, there may be no rows with those statuses—common when millions of actions are stuck in pending and the queue cannot catch up. Cleanup Schedule (how often the plugin runs) is not the same as Retention Period (how old finished actions must be before deletion). Retention uses completion or last attempt time, matching Action Scheduler's built-in cleaner.

= What is the difference between Cleanup Schedule and Retention Period? =

Cleanup Schedule sets how often this plugin's scheduled cleanup runs (1–365 days, or disabled). Retention Period sets the age threshold for deleting finished actions you selected. When a cleanup schedule is enabled, Action Scheduler's own continuous cleanup is turned off to prevent double deletion.

== Usage ==

1. In the WordPress admin panel, go to Tools > Action Scheduler Cleaner
2. Select which action statuses you want to clear (completed, failed, canceled)
3. Set up different automatic clearing schedules if desired
4. Click "Clear Selected Actions" to manually clear actions – or let the automatic schedule handle it
5. Optionally optimize the DB tables as well; this is your solution for when the size of the tables doesn't seem to go down on simple clearing

For best results, we recommend setting up an automatic clearing schedule to maintain optimal database performance. Something like 7 days is usually sufficient. By default, Action Scheduler does this every 30 days, but only for completed and canceled actions, whereas you can control this aspect in the plugin. On deactivation of the plugin, the 30-day retention period as well as which actions get cleared will reset to their defaults.

== Screenshots ==

1. The plugin's user interface.

== Changelog ==

= 2026-05-25: 1.3.0 =
* **More reliable cleanup performance**: Manual AJAX cleanup now uses time-bounded chunks with progress saved after each batch; scheduled cron uses gentler defaults.
* **Prevent DB contention**: Add global lock to prevent parallel clear/optimize operations. This was mainly relevant on sites with huge tables that would take a long time to clear.
* **Table size bugfix**: Sometimes, the table size would not get updated correctly.
* **Add filters for custom setups**: `wsacsc_ajax_cleanup_batch_size`, `wsacsc_ajax_cleanup_max_seconds`, `wsacsc_cron_cleanup_batch_size`, `wsacsc_cron_cleanup_max_seconds`, `wsacsc_cleanup_use_background_continuation`, `wsacsc_scheduled_auto_optimize_max_rows`, `wsacsc_scheduled_auto_optimize_delay_seconds`.

= 2026-05-22: 1.2.9.1 =
**Fix**: Minor CSS fix with Modern styles.

= 2026-05-22: 1.2.9 =
* **WordPress 7.0 compatibility**: Tested with WordPress 7.0; minimum WordPress version is now 6.3.
* **PHP compatibility**: Supports PHP 7.4 through 8.5.
* **WordPress 6.3 APIs**: Diagnostic logging via `wp_is_development_mode( 'plugin' )`, `cron_memory_limit` for scheduled cleanups, and simplified object cache flushing.
* **Admin UI**: Styles use WordPress admin theme CSS variables for better compatibility with the Modern admin color scheme.
* **Scheduling**: Cron health checks run on the plugin admin screen only; reduced redundant schedule registration hooks.
* **Security**: Hardened AJAX status save and cleanup progress validation before batch deletes.
* **Retention fix**: Scheduled action cleanup now uses completion or last attempt time (aligned with Action Scheduler), not the original scheduled date—important for high-backlog sites where old scheduled dates caused immediate deletion of newly finished actions.
* **Admin copy**: Clarified cleanup schedule vs retention period and why Action Scheduler status tabs may show only Pending.
* **FAQ**: Added troubleshooting for missing Complete/Failed status filters.

= 2025-12-11: 1.2.5 =
* **Increased batch size**: AJAX-caused cleanups are a bit too slow to deal with GB-sized tables, so now they're faster while still not stressing the DB too much.

= 2025-11-26: 1.2.4 =
* **Updated error handling**: Fix error occurring when the tables exist but have 0 rows.

= 2025-11-24: 1.2.3 =
* **Improved scheduling logic for time**: Fixed a bug that affected same-day changes of cleanup times.
* **Improved AJAX cleanup performance**: AJAX-based cleanup now uses batch processing with larger batches (5000 rows per batch, so still a low enough number that shouldn't cause DB server issues) for faster cleanup of large tables.
* **Enhanced user feedback**: Added modern loading spinner animation and italic styling to progress messages. Buttons remain disabled and progress messages stay visible during cleanup operations.
* **Prevented timeouts on large tables**: Both AJAX-based and scheduled cleanup operations now temporarily increase PHP execution time and memory limits during cleanup to handle very large tables (multiple gigabytes) without timing out. Original PHP settings are automatically restored after cleanup completes.
* **Added cron health checking for version migration**: We now handle edge cases for crons after version migration better and fixed some uninstall behavior issues.

= 2025-11-20: 1.2.0 =
* **Fully separated Action Scheduler scheduled cleaning and plugin-based scheduled cleaning**: Made clear the distinction and purpose of the two systems. Setting Cleanup Schedule activates the plugin-based scheduled cleaning. Action Scheduler's built-in cleanup is automatically disabled when the plugin's scheduled cleanup is active to prevent conflicts.
* **Separated scheduling and retention**: Cleanup Schedule (how often to run) is now separate from Retention Period (delete older than X days). Retention Period controls both Action Scheduler's setting and the plugin's own retention period setting. Cleanup Schedule only affects the plugin's scheduled cleaner.
* **Added time selector**: Set a specific time of day for scheduled cleanup runs (respects WordPress timezone/localization).
* **Fixed zero-day retention**: A retention period of 0 days now correctly deletes all matching entries (actions/logs) for both the plugin's and Action Scheduler's cleanup systems.
* **Improved behavior in caching situations**: Object Caching could sometimes lead to issues with event hook generation. This is fixed.
* **Fixed plugin uninstall behavior**: Event hooks and some DB options could sometimes be left over on uninstallation. Now the plugin properly cleans up after itself.
* **Backward compatibility**: Existing settings are automatically migrated to the new structure on first load.

= 2025-11-11: 1.1.0 =
* **Fixed scheduled cleanup**: Fixed retention period filter bug (thanks for reporting, @kosaacupuncture) and made the cleanup process more robust.
* **Enhanced AS integration**: Now uses `action_scheduler_default_cleaner_statuses` filter to respect your selected action statuses (complete, failed, canceled) in AS's automatic cleanup process as well.
* **Minor performance improvements**: Increased cleanup batch size from 25 to 50 actions per batch and extended queue runner time limit from 30 to 60 seconds for more efficient processing
* **Better error handling**: Added error logging via WP_DEBUG and improved error handling for cleanup operations to help diagnose issues.
* **Compatibility updates**: Tested and verified compatibility with WordPress 6.9 and WooCommerce 10.3+.
* **Misc. code improvements**: Added table existence checks before cleanup operations and improved cache management and refactor the plugin a bit.

= 2026-06-10: 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.0 =
* More reliable cleanup on large Action Scheduler tables: time-bounded chunks, saved progress, and a global lock to prevent overlapping clear/optimize jobs. Scheduled cleanup may auto-optimize small tables after retention runs. Recommended for all users; especially sites with larges tables or custom tuning via the new developer filters.

= 1.2.7 =
* Fixes retention cleanup to use completion/last-attempt time on busy Action Scheduler queues. Recommended for sites that only see Pending on the Scheduled Actions screen.

= 1.2.6 =
* WordPress 7.0 compatibility update. Requires WordPress 6.3+ and PHP 7.4+. Recommended for all users before upgrading WordPress to 7.0.

= 1.2.0 =
* This update reworks the automatic cleanup functionality so both Action Scheduler's built-in cleaning and the plugin's can be configured. Your existing settings will be preserved. Recommended for all users.

= 1.1.0 =
* This update fixes automatic cleanup functionality and slightly enhances performance. Your existing settings will be preserved. Recommended for all users.

= 1.0.0 =
* The initial release of the plugin.

== Developer Information ==

Cleanup and optimization run in time-bounded chunks: each pass deletes up to `batch_size` rows per SQL statement and repeats until `max_seconds` elapses or no matching rows remain. Progress is saved between chunks so large tables can be processed without a single long request.

The following filters (since 1.3.0) let you tune behavior for your hosting environment. Add them in a custom plugin or your theme's `functions.php`.

= Manual cleanup (admin UI / AJAX) =

* `wsacsc_ajax_cleanup_batch_size` (default: `10000`) — Maximum rows deleted per `DELETE` statement during manual clear operations initiated from the admin screen. Higher values can speed up very large tables but increase load per query.
* `wsacsc_ajax_cleanup_max_seconds` (default: `18`) — Seconds of work per AJAX request before the plugin saves progress and returns. The admin UI polls for the next chunk while you keep the page open. Increase on slow hosts if chunks finish too quickly; decrease if requests time out.

= Scheduled cleanup (WP-Cron) =

* `wsacsc_cron_cleanup_batch_size` (default: `2000`) — Same as the AJAX batch size, but for automatic retention cleanup run on a schedule. Defaults are lower than manual cleanup to reduce impact on live traffic.
* `wsacsc_cron_cleanup_max_seconds` (default: `45`) — Seconds of work per cron run before the job pauses and schedules continuation (see below).

= Background continuation =

* `wsacsc_cleanup_use_background_continuation` (default: `true`) — When a chunk ends before deletion is finished, schedule a single WP-Cron event to resume the job in the background. This is important for scheduled cleanup on large tables and when no admin session is polling progress. Manual cleanup in the browser also continues via AJAX polling; setting this filter to `false` disables cron-based resumption only (unfinished scheduled jobs may stall unless something else triggers the next chunk).

= Scheduled auto-optimize =

After a scheduled retention pass deletes all matching rows for a table, the plugin may run `OPTIMIZE TABLE` automatically to reclaim disk space. Manual clear from the admin UI never auto-optimizes; use the Optimize button instead.

* `wsacsc_scheduled_auto_optimize_max_rows` (default: `100000`) — Run auto-optimize only if the table's total row count is at or below this value after cleanup. Prevents long, blocking optimize operations on tables that are still huge. Values below `1000` are raised to `1000`.
* `wsacsc_scheduled_auto_optimize_delay_seconds` (default: `5`) — Seconds to wait after the last delete batch before running `OPTIMIZE TABLE`, giving the database a short breather. Clamped between `0` and `60`.

Example (gentler cron, no background continuation):

    add_filter( 'wsacsc_cron_cleanup_batch_size', function () { return 500; } );
    add_filter( 'wsacsc_cron_cleanup_max_seconds', function () { return 30; } );
    add_filter( 'wsacsc_cleanup_use_background_continuation', '__return_false' );

Author: Winning Solutions
Author URI: [https://www.winning-solutions.de](https://www.winning-solutions.de)
