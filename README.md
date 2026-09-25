# Uplink Time Greeting

A scheduled greeting and local date for WordPress, Bricks, Etch, and shortcodes. Version **1.0.0**.

Set four daily start times, a message for each period, a timezone, and a customizable date introduction under **Settings → Uplink Time Greeting**. The output examples show greeting, date, and both.

| Editor | Insert |
| --- | --- |
| WordPress block editor | **Uplink Time Greeting** block; choose Greeting, Date, or Both. |
| Bricks | `{tgb_greeting}`, `{tgb_date}`, or `{tgb_both}` in a dynamic text field. |
| Etch | `{options.time_greeting.greeting}`, `{options.time_greeting.date}`, or `{options.time_greeting.both}`. |
| Shortcode | `[time_greeting]`, `[time_greeting display="date"]`, or `[time_greeting display="both"]`. |
| PHP | `time_greeting_echo( array( 'display' => 'both' ) );` |

The older block name, Bricks tags, Etch keys, shortcode, and PHP helper remain available for existing content. Deactivate **Time Greeting Block** before activating this plugin. The first activation copies its settings to a new option and leaves the old settings untouched.

This repository includes the installable source. WordPress.org directory graphics live in `wordpress-org-assets/` and are excluded from the release ZIP. See `readme.txt` for the public plugin description.

License: GPLv2 or later.
