# Creators API Field Parity Audit

Output of `pendingTasks.txt` MIGR-01. Compares every field this plugin
currently extracts from the legacy PA-API (`Amazon_API::parse_item()`/
`parse_pricing()`) against its Creators API equivalent
(`Amazon_Creators_API::parse_item()`/`parse_pricing()`), and separately
inventories fields the Creators API exposes that neither implementation
currently uses.

**Method:** live `getItems` call against the real Creators API on
2026-07-14, ASIN `B0GWGVC46T` (UK), requesting every resource string in
`GetItemsResource::getAllowableEnumValues()` (the reference SDK's full
enum) rather than just the subset `Amazon_Creators_API` currently
requests - see `docs/creatorsapi-php-sdk/src/.../model/GetItemsResource.php`.
This surfaces both what's actually populated for a real account and what's
available but unrequested. Script and raw response were kept out of the
repo (see `SECRETS.md` for why) but the finding below is reproducible by
requesting the full resource list against any live ASIN.

**Verdict up front: no plugin-used field is lost.** Every field
`Amazon_API::parse_item()` currently populates has a working Creators API
equivalent already wired up in `Amazon_Creators_API::parse_item()`, except
the one gap already documented in that class's docblock
(`is_prime_price`). One real bug was found in the *existing* Creators API
availability parsing (not a parity gap - see below), and a substantial set
of new, unused-by-either-implementation fields were found, several of
which are directly relevant to a price tracker.

---

## 1. Facts fields - matched (PA-API → Creators API)

| `facts.*` key | PA-API source | Creators API source | Status |
|---|---|---|---|
| title | `ItemInfo.Title.DisplayValue` | `itemInfo.title.displayValue` | matched |
| brand | `ItemInfo.ByLineInfo.Brand.DisplayValue` | `itemInfo.byLineInfo.brand.displayValue` | matched |
| manufacturer | `ItemInfo.ByLineInfo.Manufacturer.DisplayValue` | `itemInfo.byLineInfo.manufacturer.displayValue` | matched |
| features | `ItemInfo.Features.DisplayValues` | `itemInfo.features.displayValues` | matched |
| color | `ItemInfo.ProductInfo.Color.DisplayValue` | `itemInfo.productInfo.color.displayValue` | matched |
| size | `ItemInfo.ProductInfo.Size.DisplayValue` | `itemInfo.productInfo.size.displayValue` | matched |
| dimensions | `ItemInfo.ProductInfo.ItemDimensions.{Height,Length,Width}` | `itemInfo.productInfo.itemDimensions.{height,length,width}` | matched (both omit `weight` - see §3) |
| unit_count | `ItemInfo.ProductInfo.UnitCount.DisplayValue` | `itemInfo.productInfo.unitCount.displayValue` | matched |
| formats | `ItemInfo.TechnicalInfo.Formats.DisplayValues` | `itemInfo.technicalInfo.formats.displayValues` | matched |
| model_number | `ItemInfo.ManufactureInfo.Model.DisplayValue` | `itemInfo.manufactureInfo.model.displayValue` | matched |
| part_number | `ItemInfo.ManufactureInfo.PartNumber.DisplayValue` | `itemInfo.manufactureInfo.itemPartNumber.displayValue` | matched (renamed, already handled) |
| binding | `ItemInfo.Classifications.Binding.DisplayValue` | `itemInfo.classifications.binding.displayValue` | matched |
| product_group | `ItemInfo.Classifications.ProductGroup.DisplayValue` | `itemInfo.classifications.productGroup.displayValue` | matched |
| edition | `ItemInfo.ContentInfo.Edition.DisplayValue` | `itemInfo.contentInfo.edition.displayValue` | matched |
| languages | `ItemInfo.ContentInfo.Languages.DisplayValues` | `itemInfo.contentInfo.languages.displayValues` | matched |
| release_date | `ItemInfo.ContentInfo.PublicationDate.DisplayValue` | `itemInfo.contentInfo.publicationDate.displayValue` | matched |
| ean | `ItemInfo.ExternalIds.EANs.DisplayValues[0]` | `itemInfo.externalIds.eans.displayValues[0]` | matched |
| upc | `ItemInfo.ExternalIds.UPCs.DisplayValues[0]` | `itemInfo.externalIds.upcs.displayValues[0]` | matched |
| isbn | `ItemInfo.ExternalIds.ISBNs.DisplayValues[0]` | `itemInfo.externalIds.isbns.displayValues[0]` | matched |
| amazon_category / amazon_category_id | `BrowseNodeInfo.BrowseNodes[0]` + `Ancestor` chain | `browseNodeInfo.browseNodes[0]` + `ancestor` chain | matched |
| parent_asin | `ParentASIN` | `parentASIN` | matched |

All 20 currently-used `facts` fields have working equivalents. Confirmed live: the
real response for B0GWGVC46T populated every one of these except EAN/ISBN
variants not applicable to this product category.

## 2. Pricing fields - matched, missing, and one bug

| `pricing.*` key | PA-API source | Creators API source | Status |
|---|---|---|---|
| current_price | `Offers.Listings[0].Price.Amount` (falls back to `Summaries.LowestPrice`) | `offersV2.listings[0].price.money.amount` | matched (no summary fallback exists on Creators API - acceptable, see §2c) |
| rrp | `Offers.Listings[0].SavingBasis.Amount` | `offersV2.listings[0].price.savingBasis.money.amount` | matched |
| is_prime_price | `Offers.Listings[0].DeliveryInfo.IsPrimeEligible` | *(no equivalent)* | **missing, already accepted** - always `false`, documented in `Amazon_Creators_API`'s class docblock |
| availability | `Offers.Listings[0].Availability.Type`/`Message` | `offersV2.listings[0].availability.type`/`message` | matched field-for-field, **but see bug below** |

### 2a. Bug found and fixed (not a parity gap): availability `type` vocabulary mismatch

**Status: fixed 2026-07-14** in `Amazon_Creators_API::parse_pricing()`.
The match now normalizes `_`/`-` to spaces before comparing, so Creators
API's `IN_STOCK`/`OUT_OF_STOCK`/`PREORDER`-style values match correctly
instead of only PA-API's old vocabulary. Verified directly against the
real captured live response (`IN_STOCK` → `in_stock`) plus synthetic
`OUT_OF_STOCK`/`PREORDER` cases with no `message` field present, confirming
the primary `type` match now classifies correctly on its own rather than
relying on the message-text fallback to mask a bad default. Original
finding preserved below for context.

`Amazon_Creators_API::parse_pricing()` matches the raw `availability.type`
value against PA-API's vocabulary:

```php
$pricing['availability'] = match ($availability_type) {
    'now' => 'in_stock',
    'out of stock' => 'out_of_stock',
    'pre-order', 'preorder' => 'preorder',
    default => 'unknown',
};
```

The live Creators API response for this ASIN returned
`availability.type = "IN_STOCK"` (confirmed via direct API call), not
`"Now"`. Lowercased, `"in_stock"` doesn't match the `'now'` case, so this
branch always falls through to `default => 'unknown'` for real Creators
API data. The two passing integration tests written earlier (`tests/integration/test-creators-api.php`)
still asserted `availability === 'in_stock'` correctly - but only because
the *second* pass, matching on `availability.message` (`"In stock"`
contains `"in stock"`), overwrites the wrong `'unknown'` with the right
value. **If a listing ever lacks a `message` field** (it's optional on
`OfferAvailabilityV2`), this silently degrades to `'unknown'` for an
actually-in-stock item, with no error or warning.

This is a real bug in the code being kept (not the code being deleted),
independent of the migration decision. Recommend fixing the `type` match
arms to Creators API's actual enum vocabulary (`IN_STOCK` confirmed;
`OUT_OF_STOCK`/`PREORDER` or similar are the likely siblings by the same
naming convention, but unconfirmed live - worth testing against an
out-of-stock/preorder ASIN before assuming the exact spelling) rather than
relying on the message-string fallback to mask it.

### 2b. Fields requested but parsed by neither implementation (not a gap - pre-existing)

Both `Amazon_API::get_item_resources()` and
`Amazon_Creators_API::get_item_resources()` already request these, but
neither `parse_item()`/`parse_pricing()` extracts them into output today.
Migration doesn't lose anything here since nothing currently reads them -
listed because they're "free" (already fetched) if ever wanted:

| Resource | Contains | Requested by PA-API | Requested by Creators API |
|---|---|---|---|
| `Offers.Listings.MerchantInfo` / `offersV2.listings.merchantInfo` | Seller name + ID (e.g. `"Amazon"` / `A3P5ROKL5A1OLE`) | yes | yes |
| `Offers.Listings.Condition` / `offersV2.listings.condition` | New/Used + subcondition + note | yes | yes |
| `ItemInfo.ContentRating` / `itemInfo.contentRating` | Audience rating | yes | yes |

### 2c. Confirmed acceptable gap

`Offers.Summaries.LowestPrice`/`HighestPrice` (PA-API's fallback when no
`Listings` are present) has no `offersV2` equivalent - `offersV2` only
exposes per-listing prices, no aggregate summary. Already documented in
`Amazon_Creators_API`'s class docblock. Confirmed still true - no
resource in `GetItemsResource`'s full enum offers a summary-level price.

`Offers.Listings.Promotions` also has no direct equivalent - already
documented as dropped. Partially offset by `dealDetails` (see §3) which is
more structured than PA-API's `Promotions` ever was, though not a
field-for-field replacement.

---

## 3. New fields available on Creators API, unused by either implementation

Found via the full-resource live call; none of these are requested by
`Amazon_Creators_API::get_item_resources()` today. Ranked by relevance to
a price tracker.

**High value:**

- **`customerReviews.count` / `customerReviews.starRating`** - review count
  and star rating. Not requested by either PA-API or Creators API
  implementations in this codebase currently. Directly relevant to a
  product-tracking tool; worth adding to `facts`.
- **`browseNodeInfo.browseNodes[].salesRank`** (per-category rank,
  confirmed live: `110`) and **`browseNodeInfo.websiteSalesRank.salesRank`**
  (site-wide rank, confirmed live: `23171`) - equivalent to Amazon's
  "Best Sellers Rank." Not requested by either implementation. High
  relevance for a price/demand tracker.
- **`detailPageURL`** - a ready-to-use, already affiliate-tagged product
  URL (confirmed live:
  `https://www.amazon.co.uk/dp/B0GWGVC46T?tag=getyourself0a-21&linkCode=ogi&th=1&psc=1`).
  This is an unconditional base field on `Item` (no `GetItemsResource`
  entry gates it - same as PA-API's `DetailPageURL`), so it costs nothing
  extra to request. Neither implementation currently stores it, meaning
  any UI wanting to link out has to reconstruct the URL (and affiliate
  tag) manually instead of using Amazon's own pre-tagged link.

**Medium value:**

- **`offersV2.listings.dealDetails`** - `accessType`, `badge`,
  `earlyAccessDurationInMilliseconds`, `endTime`, `percentClaimed`,
  `startTime`. Structured deal/lightning-deal info - the closest thing to
  a replacement for PA-API's dropped `Promotions` field (see §2c), and
  arguably more useful (machine-readable end time + claim percentage
  rather than free-text promotion strings).
- **`offersV2.listings.isBuyBoxWinner`** (bool, confirmed live: `true`) -
  whether this listing is the one Amazon surfaces as the default
  "Buy Now" offer. Useful signal when multiple sellers exist.
- **`offersV2.listings.violatesMAP`** (bool, confirmed live: `false`) -
  Minimum Advertised Price violation flag.
- **`offersV2.listings.availability.maxOrderQuantity` /
  `minOrderQuantity`** (confirmed live: `30` / `1`) - order-quantity
  limits, a mild scarcity signal.

**Low priority / niche:**

- **`itemInfo.productInfo.itemDimensions.weight`** - already returned
  today under the existing `itemInfo.productInfo` resource (confirmed
  live: `17.63698096 pounds`) since `weight` needs no separate resource
  flag, but neither `parse_item()` extracts it into `facts.dimensions`
  alongside height/length/width. Free to add.
- **`offersV2.listings.loyaltyPoints.points`** - Amazon Japan-specific
  loyalty points; low relevance outside the JP marketplace.
- **`offersV2.listings.type`** (`OfferType`) - not requested currently;
  purpose not confirmed against live data (this ASIN's response didn't
  distinguish it from `condition`).
- **`images.primary.{small,medium,highRes}`** / `images.variants.{small,medium,highRes}`**
  - both implementations currently request `large` only (parity, not a
    gap). Smaller sizes are available if thumbnails are ever wanted;
    `highRes` if a zoom view is ever wanted.
- **`itemInfo.tradeInInfo`** - requested by PA-API's resource list today
  but never parsed into output there either; Creators API's
  implementation already correctly omits requesting it. Not a loss since
  nothing reads it today.

---

## Summary

- **Fields lost:** 1 (`is_prime_price`), already documented and accepted
  in `Amazon_Creators_API`'s class docblock.
- **Fields matched:** all 20 currently-used `facts` fields + 3 of 4
  currently-used `pricing` fields (the 4th being the accepted loss above).
- **Bug found in code being kept:** availability `type` vocabulary
  mismatch (§2a) - recommend fixing as part of, or before, the migration,
  since it affects every product refresh today, not just after Phase 4.
- **New fields available, unused by either implementation:** 13,
  three of which (`customerReviews`, sales rank, `detailPageURL`) are
  directly relevant to this plugin's purpose and cost nothing extra to
  request.

## Sign-off

Per `pendingTasks.txt` MIGR-01: this list needs to be **explicitly
reviewed and accepted** before Phase 2 (removing old API configs) starts.
Reviewing this file is that step - once accepted, update MIGR-01 in
`pendingTasks.txt` to `[x]` and Phase 2 can begin.
