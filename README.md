# Uplink Time Greeting

A seven-day business schedule, greeting, countdown, and local date for WordPress, Bricks, Etch, and shortcodes. Version **1.0.0**.

## Set up a week

Open **Settings → Uplink Time Greeting → Weekly schedule**. Create a daily schedule once and assign it to several days. Expand each day to choose its schedule. For example, one schedule can cover Monday–Friday, while another covers the weekend. **Customize this day** copies a shared schedule so its hours and messages can change independently.

Each schedule has time entries with a start time, optional label, message, and optional Opening or Closing event. The active message continues until the next entry, including across midnight. To show a pre-opening message, add a 5:00 AM entry with `It's {time}. We open in {countdown}.` and a 7:00 AM entry marked **Opening**. Use `{opening_countdown}` when the next opening is farther away than the next entry, including after a closed weekend.

Message tokens: `{time}`, `{tz}`, `{countdown}`, `{next_label}`, `{next_time}`, `{opening_countdown}`, `{opening_time}`. Date introduction and timezone are separately configurable.

| Editor | Insert |
| --- | --- |
| WordPress block editor | **Uplink Time Greeting** block; choose Greeting, Date, or Both. |
| Bricks | `{tgb_greeting}`, `{tgb_date}`, `{tgb_both}`, or `{tgb_schedule}` in a dynamic text field. |
| Etch | `{options.time_greeting.greeting}`, `{options.time_greeting.date}`, `{options.time_greeting.both}`, `{options.time_greeting.schedule}`, or an individual day such as `{options.time_greeting.days.monday}`. |
| Shortcode | `[time_greeting]`, `[time_greeting display="date"]`, `[time_greeting display="both"]`, or `[time_greeting display="schedule"]`. |
| PHP | `time_greeting_echo( array( 'display' => 'both' ) );` |

Blocks and shortcodes refresh countdowns and schedule changes on the page. Bricks tags and Etch options data are plain text resolved at page render time; use a shortcode-capable element for a live countdown. Page caching can delay the plain-text values.

The older block name, Bricks tags, Etch keys, shortcode, and PHP helper remain available for existing content. Deactivate **Time Greeting Block** before activating this plugin. Its daily settings are copied into one reusable schedule and assigned to all seven days; the original option is left untouched.

This repository includes installable source. WordPress.org directory graphics live in `wordpress-org-assets/` and are excluded from the release ZIP. See `readme.txt` for the public description.

License: GPLv2 or later.
