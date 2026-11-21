# Omnibus Price Compliance for Maho

EU Omnibus Directive (2019/2161) compliance: records the price history of every
product save, shows the lowest price of the reference period next to a crossed-out
price, and reports the products whose reference price is higher than that lowest price.

## What it does

- **Price history** — every time you save a product, the prices it had until then are kept,
  so the price you announce and the price you sold it at before stay distinguishable.
  Configurable products keep the price of each variant.

  ![Omnibus price history grid](admin-history.png)

- **Lowest price of the reference period** — for every product the lowest price applied in
  the last 30 days (the period is configurable) is worked out in advance, with the gap from
  the crossed-out price and the day that lowest price leaves the period. Every page reads
  the ready figure: nothing is calculated while the customer waits.
- **Storefront notice** — when a product shows a crossed-out price, customers see a short
  note under it ("Lowest price in the last 30 days.") and the explanation on hover, in the
  language of the store. No JavaScript, and one notice per product even when the page shows
  the price more than once. The style is the module's own, so the notice looks the same on
  every theme and updating the module never requires a theme rebuild.

  ![Omnibus notice and its tooltip on the product page](frontend-tooltip.gif)

  ![Notice next to the crossed-out price](frontend-notice.png)

- **Admin** — a dashboard (`Catalog → Omnibus → Dashboard`) lists the products whose
  crossed-out price is higher than the lowest price of the period, the biggest gap first,
  with a one-click CSV/Excel export. The same history is available for the whole store and,
  as a tab, on the product you are editing, where a warning at the top shows the gap.

  ![Omnibus compliance dashboard](admin-dashboard.png)

  ![Product edit: compliance warning and Omnibus History tab](admin-product-tab.png)

- **Maho assistant** — the non-compliant products are available to the admin assistant, so
  you can ask which products do not comply and get the list, without opening a grid.

## Requirements

- PHP >= 8.5
- Maho Commerce

## Installation

```sh
composer require empiricompany/maho-omnibus
./maho migrate
./maho cache:flush
```

Fill the index once, then keep it updated by the observers (daily cron for the sliding
window):

```sh
./maho index:reindex mm_omnibus_compliance
```

## Configuration

*Stores → Configuration → Catalog → Omnibus Price Compliance*

| Setting | Meaning |
| --- | --- |
| Module enabled | Turns the observers, the notice and the cron off |
| Reference period (days) | Length of the window used to find the lowest price (default 30) |
| History retention (days) | Rows older than this are removed by the nightly cron |
| Price box selector | CSS selector of the price element (default `.price-box`) |
| Notice position | `inside`, `before` or `after` the matched element (default `after`, outside the price box, so the crossed-out price and the discount badge keep their layout; `inside` uses the full price box row) |
| Debug log | Writes the module decisions to `var/log/mm_omnibus.log` |

## When a product does not comply

A product is non-compliant when the crossed-out reference price is **higher** than the
lowest price recorded in the reference window. The comparison is per product **and
website**, because prices are website-scoped; when prices are global the website
column and filter are hidden.

The record of the price change itself is excluded from the window: it is the price
being announced, not a previous one.

## Data

### `omnibus_price_history`

One row per recorded price: `product_id`, `parent_product_id` (configurable children),
`website_id`, `action` (`product_save` or `catalogrule_apply`), `price`,
`special_price`, `final_price`, the `old_*` counterparts, `is_reference` and
`created_at`. A rolling de-duplication keeps one row per product, parent, website and
action per day, and a catalog rule record supersedes the product save of the same day
when both exist.

### `omnibus_compliance_index`

`product_id`, `website_id`, `reference_price`, `reference_recorded_at`, `lowest_price`,
`lowest_price_date`, `expires_at`, `difference`, `is_noncompliant`, `computed_at`.
Rebuilt by the indexer `mm_omnibus_compliance`; `expires_at` exists so grids and API can
sort by "days until expiry" without database-specific date arithmetic.

The dashboard legend shows `computed_at`, so a stale index is visible instead of silent.

## Cron

| Job | Schedule | What it does |
| --- | --- | --- |
| `mm_omnibus_reindex` | `0 3 * * *` | Rebuilds the index, so the sliding window stays aligned day by day |
| `mm_omnibus_cleanup` | `30 3 * * *` | Removes the history older than the retention |

## API

`GET /omnibus/non-compliant-products` — requires the `catalog/mm_omnibus` ACL.

| Parameter | Meaning |
| --- | --- |
| `websiteId` | Only the rows of that website |
| `minDifference` | Only the rows whose difference is at least this amount |
| `page`, `itemsPerPage` | Pagination (default 20, max 500) |

Each item carries `productId`, `sku`, `name`, `parentSku`, `websiteId`, `website`,
`referencePrice`, `lowestPrice`, `lowestPriceDate`, `difference`, `daysUntilExpiry` and
`computedAt`.

## Tests

```sh
composer check   # pest, php -l, php-cs-fixer, phpstan, rector
composer test:db # same suite on a throwaway SQLite store (installs it first)
```

`composer check` runs the unit tests, which cover the HTML fragment injection and the
compliance arithmetic and need no database. The integration tests (table creation, indexer,
collection, API provider) are skipped with a message until a test store exists:
`composer test:db` installs one inside the package (`var/db/maho_test.sqlite`, no sample
data), runs the declarative schema migration, launches Pest and restores
`app/etc/local.xml`. Your own store is never touched.

## License

OSL-3.0. See [LICENSE.txt](LICENSE.txt).
