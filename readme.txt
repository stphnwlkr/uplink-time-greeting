=== Uplink Time Greeting ===
Contributors: stphnwlkr
Tags: greeting, business hours, date, bricks, etch
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show a greeting and local date that change with your daily schedule in the block editor, Bricks, Etch, or a shortcode.

== Description ==

Set the start times and messages for morning, afternoon, evening, and night. Use your site's timezone or select another timezone for the schedule. The date introduction can be translated or rewritten, and Date only can include it.

Uplink Time Greeting provides the same saved schedule through a WordPress block, Bricks dynamic tags, Etch options data, a shortcode, and a PHP helper. The settings screen shows an example for greeting, date, and both.

Existing Time Greeting Block content keeps working after you deactivate that plugin and activate this one. On first activation, this plugin copies its saved settings without removing the old data.

== Installation ==

1. Upload and activate Uplink Time Greeting.
2. Open Settings > Uplink Time Greeting to set your schedule and messages.
3. Add the Uplink Time Greeting block, a builder dynamic tag, or a shortcode to a page.

If you are moving from Time Greeting Block, deactivate it before activating Uplink Time Greeting.

== Frequently Asked Questions ==

= How do I use it in Bricks? =

Insert {tgb_greeting}, {tgb_date}, or {tgb_both} in a dynamic text field. These tag names are retained for existing content.

= How do I use it in Etch? =

Insert {options.time_greeting.greeting}, {options.time_greeting.date}, or {options.time_greeting.both} in a text element.

= Is there a shortcode? =

Yes. Use [time_greeting], [time_greeting display="date"], or [time_greeting display="both"]. The shortcode also accepts date_format, timezone, and tz_abbr.

= Can I change the four time periods? =

Yes. Enter a start time and a message for each period. Night continues until morning starts again.

== Changelog ==

= 1.0.0 =

* Initial Uplink Time Greeting release with block, Bricks, Etch, shortcode, schedule, and date controls.
