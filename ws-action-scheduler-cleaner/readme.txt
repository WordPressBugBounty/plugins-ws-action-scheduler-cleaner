=== WS Action Scheduler Cleaner ===
Contributors: winningsolutions
Donate link: https://www.winning-solutions.de
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 1.2.5
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

= 1.2.0 =
* This update reworks the automatic cleanup functionality so both Action Scheduler's built-in cleaning and the plugin's can be configured. Your existing settings will be preserved. Recommended for all users.

= 1.1.0 =
* This update fixes automatic cleanup functionality and slightly enhances performance. Your existing settings will be preserved. Recommended for all users.

= 1.0.0 =
* The initial release of the plugin.

== Developer Information ==

Author: Winning Solutions
Author URI: [https://www.winning-solutions.de](https://www.winning-solutions.de)
