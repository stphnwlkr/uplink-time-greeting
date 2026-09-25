# Uplink Time Greeting

A seven-day business schedule, greeting, countdown, and local date for WordPress, Bricks, Etch, and shortcodes. Version **1.0.0**.

## Set up a week

Open **Settings → Uplink Time Greeting → Weekly schedule**. Create a daily schedule once and assign it to several days. Expand each day to choose its schedule. For example, one schedule can cover Monday–Friday, while another covers the weekend. **Customize this day** copies a shared schedule so its hours and messages can change independently.

Each schedule has time entries with a start time, optional label, message, and optional Opening or Closing event. The active message continues until the next entry, including across midnight. To show a pre-opening message, add a 5:00 AM entry with `It's {time}. We open in {countdown}.` and a 7:00 AM entry marked **Opening**. Use `{opening_countdown}` when the next opening is farther away than the next entry, including after a closed weekend.

Message tokens: `{time}`, `{tz}`, `{countdown}`, `{next_label}`, `{next_time}`, `{opening_countdown}`, `{opening_time}`. Date introduction and timezone are separately configurable.

| Editor | Insert |
| --- | --- |
| WordPress block editor | **Uplink Time Greeting** block; choose Greeting, Date, or Both. |
| Bricks | Set a layout element's Query Loop type to **Uplink Weekly Schedule** and use `{utg_day}` and `{utg_hours}` in child elements. A Shortcode element with `[time_greeting display="schedule"]` provides ready-made semantic markup. `{tgb_schedule}` remains available as inline plain text. |
| Etch | Loop over `options.time_greeting.week` to build a schedule with your own HTML and classes. Inline text remains available as `{options.time_greeting.schedule}`. |
| Shortcode | `[time_greeting]`, `[time_greeting display="date"]`, `[time_greeting display="both"]`, or `[time_greeting display="schedule"]`. |
| PHP | `time_greeting_echo( array( 'display' => 'both' ) );` |

Blocks and shortcodes refresh countdowns and schedule changes on the page. Bricks tags and Etch options data are plain text resolved at page render time; use a shortcode-capable element for a live countdown. Page caching can delay the plain-text values.

The ready-made schedule uses a `<dl>` with one `<dt>` and `<dd>` pair per day. Opening and closing times use `<time datetime="HH:MM">`. CSS hooks include `.utg-schedule`, `.utg-schedule__day`, `.utg-schedule__name`, `.utg-schedule__hours`, `.utg-schedule__interval`, `.utg-schedule__opens`, and `.utg-schedule__closes`. Each day also has `data-day` and `data-state` (`open`, `closed`, or `unset`). Style the row spacing, padding, and border with `--utg-schedule-gap`, `--utg-schedule-padding`, and `--utg-schedule-border`.

For a custom Bricks design, add an outer Div with HTML tag `dl`, then a nested Div with Query Loop enabled and type **Uplink Weekly Schedule**. Give that repeating Div the HTML tag `div`; add child text elements with tags `dt` and `dd` and dynamic content `{utg_day}` and `{utg_hours}`. Style the row and children with Bricks controls. The loop follows WordPress's **Week Starts On** setting and returns all seven days, including closed days. `{utg_state}` returns `open`, `closed`, or `unset`; `{utg_key}` returns the English day key; `{utg_today}` returns `1` or `0`. These tags resolve only inside this schedule loop.

Etch receives an ordered `week` array that follows WordPress's **Week Starts On** setting. Each day has `key`, `day`, `hours`, `state`, `is_today`, and `windows`. Each window has `start`, `start_label`, `end`, `end_label`, and `overnight`. Example:

```html
<dl class="business-hours">
  {#loop options.time_greeting.week as day}
    <div class="business-hours__day" data-state="{day.state}">
      <dt>{day.day}</dt>
      <dd>{day.hours}</dd>
    </div>
  {/loop}
</dl>
```

The older block name, Bricks tags, Etch keys, shortcode, and PHP helper remain available for existing content. Deactivate **Time Greeting Block** before activating this plugin. Its daily settings are copied into one reusable schedule and assigned to all seven days; the original option is left untouched.

This repository includes installable source. WordPress.org directory graphics live in `wordpress-org-assets/` and are excluded from the release ZIP. See `readme.txt` for the public description.

License: GPLv2 or later.
