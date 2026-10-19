# Uplink Hours & Greetings

A seven-day schedule, special-date overrides, live open status, greetings, countdowns, and local dates for WordPress, Bricks, Etch, and shortcodes. Version **1.1.0**.

## Set up a week

Open **Settings → Uplink Hours & Greetings → Weekly schedule**. Create a daily schedule once and assign it to several days. Expand each day to choose its schedule. For example, one schedule can cover Monday–Friday, while another covers the weekend. **Customize this day** copies a shared schedule so its hours and messages can change independently.

Each schedule has timed entries with a start time, optional label, message, and optional Opening or Closing event. The active message continues until the next timed entry, including across midnight. A **Closing text** entry adds an untimed schedule value such as “Last Call” and does not affect greetings or countdowns. To show a pre-opening message, add a 5:00 AM entry with `It's {time}. We open in {countdown}.` and a 7:00 AM entry marked **Opening**. Use `{opening_countdown}` when the next opening is farther away than the next entry, including after a closed weekend.

Message tokens: `{time}`, `{tz}`, `{countdown}`, `{next_label}`, `{next_time}`, `{opening_countdown}`, `{opening_time}`. The timezone follows WordPress Settings → General by default. A timezone override, date introduction, and date ending punctuation are separately configurable. The punctuation setting is blank by default.

Administrators can use the **Permissions** tab to allow additional roles or individual users to update plugin settings. Administrators always retain access. The admin tabs switch in place, so schedule edits remain on the page while moving between sections. **How to Use** is the final tab and contains every editor integration plus appearance guidance.

Special dates can include a destination URL and link label for an event, sale, holiday notice, or dedicated detail page. The Special Dates block can show one saved date or an upcoming list, with separate controls for hours, message, and link visibility. Its output is stacked and inherits the theme's visual styling; the plugin only supplies the list and spacing layout.

| Editor | Insert |
| --- | --- |
| WordPress block editor | **Uplink Hours & Greetings** for greeting, date, status, or schedule; **Uplink Special Dates** for one saved date or an upcoming list. |
| Bricks | Use **Uplink Weekly Schedule** for seven schedule rows or **Uplink Special Dates** for upcoming events. The event loop exposes formatted date, title, message, hours, closed state, URL, and link label tags. |
| Etch | Loop over `options.uplink_hours_greetings.week` to build a schedule with your own HTML and classes. Special-date links are included in both `week` rows and the `special_dates` array. Complete outputs, current and next entries, next opening, timezone, current clock, individual days, and machine-readable windows are available under `options.uplink_hours_greetings`. |
| Shortcode | `[uplink_hours_greetings]`, `[uplink_hours_greetings display="status"]`, `[uplink_hours_greetings display="schedule"]`, or `[uplink_hours_greetings display="special_dates"]`. |
| PHP | `uplink_hours_greetings_echo( array( 'display' => 'both' ) );` |

Blocks and shortcodes refresh countdowns and schedule changes on the page. Bricks tags and Etch options data are plain text resolved at page render time; use a shortcode-capable element for a live countdown. Page caching can delay the plain-text values.

The ready-made schedule uses a `<dl>` with one `<dt>` and `<dd>` pair per day. Opening and closing times use `<time datetime="HH:MM">`; an untimed closing uses `<span class="ulhgr-schedule__closes ulhgr-schedule__closes--text">`. CSS hooks include `.ulhgr-schedule`, `.ulhgr-schedule__day`, `.ulhgr-schedule__name`, `.ulhgr-schedule__hours`, `.ulhgr-schedule__interval`, `.ulhgr-schedule__opens`, and `.ulhgr-schedule__closes`. Each day also has `data-day` and `data-state` (`open`, `closed`, or `unset`). Style the row spacing, padding, and border with `--ulhgr-schedule-gap`, `--ulhgr-schedule-padding`, and `--ulhgr-schedule-border`.

For a custom Bricks design, add an outer Div with HTML tag `dl`, then a nested Div with Query Loop enabled and type **Uplink Weekly Schedule**. Give that repeating Div the HTML tag `div`; add child text elements with tags `dt` and `dd` and dynamic content `{ulhgr_day}` and `{ulhgr_hours}`. Style the row and children with Bricks controls. The loop follows WordPress's **Week Starts On** setting and returns all seven days, including closed days. `{ulhgr_state}` returns `open`, `closed`, or `unset`; `{ulhgr_key}` returns the English day key; `{ulhgr_today}` returns `1` or `0`. These tags resolve only inside this schedule loop.

To build an upcoming events list in Bricks, enable Query Loop on an inner Div or Block and choose **Uplink Special Dates**. Use `{ulhgr_event_date}`, `{ulhgr_event_date_label}`, `{ulhgr_event_title}`, `{ulhgr_event_message}`, `{ulhgr_event_hours}`, `{ulhgr_event_closed}`, `{ulhgr_event_link_url}`, and `{ulhgr_event_link_label}` inside the repeating element.

`{ulhgr_special_label}`, `{ulhgr_special_link_url}`, and `{ulhgr_special_link_label}` are direct status tags for the special-date override active today. They return an empty value on a regular date. Use `{ulhgr_event_title}` and the other event tags when building an upcoming special dates loop.

For set-and-forget content in Bricks, add a **Dynamic data** condition to the announcement wrapper. Set the dynamic value to `{ulhgr_special_label}` and the comparison to **Is not empty**. Bricks omits the wrapper on regular dates and renders it with the current special-date tags for the full matching calendar date.

For one upcoming event without a loop, use `{ulhgr_next_special_date}`, `{ulhgr_next_special_date_label}`, `{ulhgr_next_special_label}`, `{ulhgr_next_special_message}`, `{ulhgr_next_special_hours}`, `{ulhgr_next_special_closed}`, `{ulhgr_next_special_link_url}`, and `{ulhgr_next_special_link_label}`. These return fields from the first upcoming saved special date.

The Bricks dynamic data picker separates plugin tags into **Uplink — Direct (no loop)**, **Uplink — Weekly Schedule Loop**, and **Uplink — Special Dates Loop** groups.

Etch exposes direct current and next special-date objects without requiring a loop. `options.uplink_hours_greetings.current_special` represents a matching date, while `options.uplink_hours_greetings.next_special` represents the first upcoming date. `options.uplink_hours_greetings.upcoming_special_dates` provides a date-ordered loop with formatted `date_label` and `hours` values. The existing `special_dates` loop includes every saved date plus `is_today`, `is_past`, and `is_upcoming` flags.

Etch receives an ordered `week` array that follows WordPress's **Week Starts On** setting. Add an Etch Loop block, set **Target** to `options.uplink_hours_greetings.week`, and set **Item ID** to `day`. Inside it, use `{day.day}`, `{day.hours}`, `{day.state}`, `{day.key}`, `{day.number}`, and `{day.is_today}` in native Etch elements. Each day also has a `windows` array. To customize each opening and closing value, add a nested Loop block with **Target** `day.windows` and **Item ID** `window`, then use `{window.start}`, `{window.start_label}`, `{window.end}`, `{window.end_label}`, `{window.end_type}`, and `{window.overnight}`. `end_type` is `time`, `text`, or `none`, which lets a design render an untimed closing such as “Last Call” as text. Direct paths include `greeting`, `date`, `both`, `schedule`, `timezone`, `timezone_abbr`, `now.*`, `current.*`, `next.*`, `opening.*`, and `days.monday` through `days.sunday`.

This repository includes installable source. Release-ready WordPress.org graphics live in `.wordpress-org/`; editable source graphics live in `wordpress-org-assets/` and are excluded from the plugin ZIP. See `readme.txt` for the public description.

License: GPLv2 or later.
