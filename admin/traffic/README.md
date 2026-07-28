# Live Traffic admin module

A self-contained admin panel that tails your server logs and renders them in
report sub-tabs:

| Sub-tab      | Source              | Notes                                            |
|--------------|---------------------|--------------------------------------------------|
| Live Traffic | `access.log` + rotated tail | Polls every 5s; bridges midnight UTC rotation |
| 403-404      | `access.log.1.gz`   | Yesterday's blocked/not-found tally, cached 2 days|
| PHP Errors   | `php_errors.log`    | Last 7 days                                       |
| PHP Slow     | `php_slow.log`      | Last 7 days, grouped into timestamped blocks      |
| WAF Log      | `waf.log`           | ModSecurity JSON parsed into readable cards       |
| WP-Cron      | `wp-cron.log`       | Recent grouped cron runs                           |
| Visitors     | `wp_options`        | Latest 100 daily human counts; Turnstile only      |
| Download     | Configured logs dir | File name, size, modified time, and secure download|

Everything lives under `admin/Traffic/`. One class is the entry point; the rest
are internal.

## Wiring it into the host plugin

From wherever you build the Live Traffic tab (e.g. your admin bootstrap, once
per request), call:

```php
\dbtn\Admin\Traffic\DBTN_Traffic::init();
```

That single call registers the REST routes and the admin asset enqueue. Then,
inside your tab-1 render callback, replace the old inline markup with:

```php
\dbtn\Admin\Traffic\DBTN_Traffic::render_panel();
```

That's the whole integration. `init()` is idempotent, so calling it more than
once is harmless.

### Asset enqueue target

Assets only load on the admin page whose hook is
`toplevel_page_dbtnsubscriber-plugin`. If your menu slug differs, filter it:

```php
add_filter( 'dbtn_traffic_page_hook', fn() => 'your_page_hook_suffix' );
```

## File layout

```
admin/Traffic/
├── class-dbtn-traffic.php                     # entry point: init(), render_panel(), enqueue
├── class-dbtn-traffic-rest.php                # REST routes and download list
├── class-dbtn-traffic-log-reader.php          # tailing, rotation bridge, parsing, statuses
├── class-dbtn-traffic-report-403-404.php      # 403/404 report
├── class-dbtn-traffic-report-php-errors.php   # PHP errors report
├── class-dbtn-traffic-report-php-slow.php     # PHP slow report
├── class-dbtn-traffic-report-waf.php          # WAF report
├── class-dbtn-traffic-report-wp-cron.php      # WP-Cron report
├── class-dbtn-traffic-report-visitors.php     # Daily human visitor counts
├── css/dbtn-traffic.css
└── js/dbtn-traffic.js
```

Class names use the `dbtn\Admin\Traffic\` sub-namespace so the existing DBTN
autoloader finds them with no changes: first segment `Admin` selects `admin/`,
the `Traffic` segment becomes the sub-directory (capital **T** — the autoloader
does not lowercase sub-paths), and the class name maps to the
`class-…-….php` filename.

## Dependencies

The four **report** tabs depend only on `DBTN_Traffic_Log_Reader` inside this
module — fully self-contained.

The **Live Traffic** tab has three hard dependencies on the host plugin (by
design — it will fatal without them):

| Used for                         | Host class / method                              |
|----------------------------------|--------------------------------------------------|
| Caller's own IP                  | `dbtn\Support\DBTN_Utilities::get_client_ip()`   |
| "Validated" row highlight / Hide me | `dbtn\Support\DBTN_Visitor_Validator` (is/mark/get) |
| Location column                  | `dbtn\Admin\DBTN_GeoIP::lookup_string()`         |

The per-row IP lookup *card* (click an IP) is independent of `DBTN_GeoIP`; it
calls `ipinfo.io` directly from the browser.

Clicking a URL in the Path column pauses live polling and shows recent requests
for the same path. Query strings are ignored, so `/shop?page=1` and
`/shop?page=2` are grouped together as `/shop`.

## Spinning it out into its own plugin

When you're ready to lift this into a standalone plugin:

1. Add a plugin-header bootstrap file that defines `DBTN_ADMIN_DIR` and
   `DBTN_ADMIN_URL` (pointing at wherever this `Traffic/` directory lands), wires
   an autoloader for the `dbtn\Admin\Traffic\` namespace, registers an admin
   menu page, and calls `DBTN_Traffic::init()` plus `render_panel()` in the page
   callback.
2. Satisfy the three Live-Traffic dependencies above — either bring those
   classes along or swap in equivalents.

No other file needs to change; nothing outside `Traffic/` references these
classes.

## Log file location

Logs are read from `dirname( $_SERVER['DOCUMENT_ROOT'] ) . '/logs/<file>'` by
default. When `dbtn_lt_settings['logs_dir']` is configured, that custom
directory is used instead. The Download tab uses this same resolved directory.
Downloads require the `manage_options` capability, use a download nonce, and
are restricted to direct files inside that directory.
```
