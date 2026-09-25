=== Uplink Time Greeting ===
Contributors: stphnwlkr
Tags: business hours, countdown, greeting, bricks, etch
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show greetings, opening countdowns, and dates from a reusable seven-day business schedule in blocks, Bricks, Etch, and shortcodes.

== Description ==

Build reusable daily schedules with up to 24 time entries each. Assign the same schedule to Monday through Friday, for example, then copy it to customize one day. A separate schedule can cover weekends. Each entry has a start time, optional label, message, and optional Opening or Closing event. Messages continue until the next entry, including across midnight.

Use {time} and {tz} in messages, or {countdown} for time until the next entry. {next_label} and {next_time} describe that entry. Mark an entry as Opening to use {opening_countdown} and {opening_time}, even when the next opening is on another day. Example: a 5:00 AM message can say "It's {time}. We open in {countdown}." before a 7:00 AM Opening entry.

The WordPress block and shortcode update countdowns on the page and refresh at schedule changes. Bricks dynamic tags and Etch options data resolve the current value when the page renders; for a live countdown in either builder, use a shortcode-capable element. A weekly schedule output uses the site's Week Starts On setting. The plugin also offers editable date wording, a timezone setting, and output examples for greeting, date, both, and schedule.

Existing Time Greeting Block content keeps working after you deactivate that plugin and activate this one. On first activation, this plugin copies its saved settings without removing the old data.

== Installation ==

1. Upload and activate Uplink Time Greeting.
2. Open Settings > Uplink Time Greeting > Weekly schedule to assign daily schedules and write messages. Expand a day to assign or customize its schedule.
3. Add the block, a builder dynamic tag, or a shortcode to a page.

If you are moving from Time Greeting Block, deactivate it before activating Uplink Time Greeting.

== Frequently Asked Questions ==

= How do I set Monday through Friday hours once? =

Create a schedule with your opening and closing entries and assign it to all five weekdays. Assign a different schedule to Saturday and Sunday. Use Customize this day to copy a shared schedule for one day.

= How do I show a countdown to opening? =

Mark an entry as Opening. Use {opening_countdown} in an earlier message. {countdown} instead counts to the very next time entry.

= How do I use it in Bricks? =

Insert {tgb_greeting}, {tgb_date}, {tgb_both}, or {tgb_schedule} in a dynamic text field. These tag names are retained for existing content. Use [time_greeting] or [time_greeting display="schedule"] in a Shortcode element for live or formatted output.

= How do I use it in Etch? =

Insert {options.time_greeting.greeting}, {options.time_greeting.date}, {options.time_greeting.both}, or {options.time_greeting.schedule} in a text element. Individual day hours are available as {options.time_greeting.days.monday}, and so on. Use a shortcode-capable element for a live countdown or formatted schedule.

= Is there a shortcode? =

Yes. Use [time_greeting], [time_greeting display="date"], [time_greeting display="both"], or [time_greeting display="schedule"]. The shortcode also accepts date_format, timezone, and tz_abbr.

== Changelog ==

= 1.0.0 =

* Initial release with a reusable seven-day schedule, per-day customization, opening countdowns, live block and shortcode updates, Bricks and Etch data, and date controls.
