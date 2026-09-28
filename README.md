# Uplink Hours & Greetings

A seven-day business schedule, greeting, countdown, and local date for WordPress, Bricks, Etch, and shortcodes. Version **1.0.0**.

## Set up a week

Open **Settings → Uplink Hours & Greetings → Weekly schedule**. Create a daily schedule once and assign it to several days. Expand each day to choose its schedule. For example, one schedule can cover Monday–Friday, while another covers the weekend. **Customize this day** copies a shared schedule so its hours and messages can change independently.

Each schedule has time entries with a start time, optional label, message, and optional Opening or Closing event. The active message continues until the next entry, including across midnight. To show a pre-opening message, add a 5:00 AM entry with `It's {time}. We open in {countdown}.` and a 7:00 AM entry marked **Opening**. Use `{opening_countdown}` when the next opening is farther away than the next entry, including after a closed weekend.

Message tokens: `{time}`, `{tz}`, `{countdown}`, `{next_label}`, `{next_time}`, `{opening_countdown}`, `{opening_time}`. The timezone follows WordPress Settings → General by default; an override and date introduction are separately configurable.

Administrators can use the **Permissions** tab to allow additional roles or individual users to update plugin settings. Administrators always retain access. The admin tabs switch in place, so schedule edits remain on the page while moving between sections. **How to Use** is the final tab and contains every editor integration plus appearance guidance.

| Editor | Insert |
| --- | --- |
| WordPress block editor | **Uplink Hours & Greetings** block; choose Greeting, Date, or Both. |
| Bricks | Set a layout element's Query Loop type to **Uplink Weekly Schedule** and use `{ulhgr_day}` and `{ulhgr_hours}` in child elements. A Shortcode element with `[uplink_hours_greetings display="schedule"]` provides ready-made semantic markup. `{ulhgr_schedule}` remains available as inline plain text. |
| Etch | Loop over `options.uplink_hours_greetings.week` to build a schedule with your own HTML and classes. Complete outputs, current and next entries, next opening, timezone, current clock, individual days, and machine-readable windows are available under `options.uplink_hours_greetings`. |
| Shortcode | `[uplink_hours_greetings]`, `[uplink_hours_greetings display="date"]`, `[uplink_hours_greetings display="both"]`, or `[uplink_hours_greetings display="schedule"]`. |
| PHP | `uplink_hours_greetings_echo( array( 'display' => 'both' ) );` |

Blocks and shortcodes refresh countdowns and schedule changes on the page. Bricks tags and Etch options data are plain text resolved at page render time; use a shortcode-capable element for a live countdown. Page caching can delay the plain-text values.

The ready-made schedule uses a `<dl>` with one `<dt>` and `<dd>` pair per day. Opening and closing times use `<time datetime="HH:MM">`. CSS hooks include `.ulhgr-schedule`, `.ulhgr-schedule__day`, `.ulhgr-schedule__name`, `.ulhgr-schedule__hours`, `.ulhgr-schedule__interval`, `.ulhgr-schedule__opens`, and `.ulhgr-schedule__closes`. Each day also has `data-day` and `data-state` (`open`, `closed`, or `unset`). Style the row spacing, padding, and border with `--ulhgr-schedule-gap`, `--ulhgr-schedule-padding`, and `--ulhgr-schedule-border`.

For a custom Bricks design, add an outer Div with HTML tag `dl`, then a nested Div with Query Loop enabled and type **Uplink Weekly Schedule**. Give that repeating Div the HTML tag `div`; add child text elements with tags `dt` and `dd` and dynamic content `{ulhgr_day}` and `{ulhgr_hours}`. Style the row and children with Bricks controls. The loop follows WordPress's **Week Starts On** setting and returns all seven days, including closed days. `{ulhgr_state}` returns `open`, `closed`, or `unset`; `{ulhgr_key}` returns the English day key; `{ulhgr_today}` returns `1` or `0`. These tags resolve only inside this schedule loop.

Etch receives an ordered `week` array that follows WordPress's **Week Starts On** setting. Each day has `key`, `number`, `day`, `hours`, `state`, `is_today`, and `windows`. Each window has `start`, `start_label`, `end`, `end_label`, and `overnight`. Direct paths include `greeting`, `date`, `both`, `schedule`, `timezone`, `timezone_abbr`, `now.*`, `current.*`, `next.*`, `opening.*`, and `days.monday` through `days.sunday`. Example:

```html
<dl class="business-hours">
  {#loop options.uplink_hours_greetings.week as day}
    <div class="business-hours__day" data-state="{day.state}">
      <dt>{day.day}</dt>
      <dd>{day.hours}</dd>
    </div>
  {/loop}
</dl>
```

This repository includes installable source. WordPress.org directory graphics live in `wordpress-org-assets/` and are excluded from the release ZIP. See `readme.txt` for the public description.

License: GPLv2 or later.
