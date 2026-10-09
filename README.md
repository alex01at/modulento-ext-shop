# Shop

An extension for [Modulento](https://github.com/alex01at/modulento) that
turns the catalogue into a classic, single-provider online shop: products
with variants, a cart spanning several of them, one checkout.

It adds the offer type `shop.product`: a product is a normal offer (title,
description, pictures, category) with one or more variants, each with its
own label, an optional SKU, a price and a stock count. A product with only
one variant shows no picker on its page; more than one shows a choice, the
out-of-stock ones disabled. Stock is checked when something is added to the
cart and taken atomically at checkout, so two buyers racing for the last
piece cannot both succeed.

The cart (`/cart`) is tied to the account, not a guest session - there is no
guest checkout. Checkout (`/checkout`) adds one flat shipping fee (set under
**Administration → Shop**) and places one order for every line in the cart
at once, through `Orders::create()` directly rather than the generic
single-offer order form - a cart holding several products has no single
"offer" to route through. Its own order flow covers what follows: the
provider marks an order shipped, the buyer confirms receipt (or it is
confirmed automatically after 14 days), and either side can propose a
cancellation the other agrees to or refuses - the same shape Modulento's own
Requests feature uses. A cancelled order gives its stock back.

**CSV import** (**account → Produkte importieren**, `/account/shop/import`):
creates products in bulk from a CSV file - one row per variant, several rows
with the same `product` column become one product with several variants.
Create-only: a product whose title already exists for this provider is
skipped rather than merged or updated, so importing the same file twice is
harmless. Everything goes through the normal `Variants`/`Offers` code the
product form itself uses, including the SKU-uniqueness check and the usual
pending/published approval decision - a second language needs a second
import, with the locale switched beforehand.

This extension is built for exactly one approved provider - the shop's
operator. Nothing stops installing it with more than one, but a cart that
somehow holds products from different providers is refused at checkout
rather than guessed at (see `CheckoutController::singleProviderId()`); nor
does it try to split one cart into several orders.

**Discount codes** (**Administration → Shop → Rabattcodes**): a flat amount
or a percentage off the product subtotal, never off shipping, with an
optional expiry, a maximum number of redemptions and a minimum order value.
A redemption is taken atomically at checkout and given back if the order is
later cancelled, the same way stock is. `order_item.unit_price` is unsigned
in the core, so a discount can never be its own negative line - instead
`Discounts::apply()` prorates it across the product lines by their share of
the subtotal, splitting a line into two rows where the reduction does not
divide evenly by its quantity, so the total is exact to the cent.

Discount codes are their own module, listed under **Administration →
Modules** - on by default, alongside the core's own modules, only while this
extension itself is active. Switched off, the cart's "enter a code" field
and the admin discounts page are both gone, and checkout no longer prorates
anything; existing codes and their redemption counts stay untouched for
whenever it is switched back on.

**Digital products**: a variant can be marked digital on the offer form
(**no shipping, no stock to run out of**) and, once saved, gets a file
uploaded to it on the same page. A cart with nothing physical in it is not
charged shipping. Once an order is paid, its order page offers a download
of every digital variant it contains, checked against the order's own
buyer, payment state and line items - never a public URL - and a scheduled
task (**Administration → Tasks**, `shop.mail_downloads`, every 5 minutes)
mails the same links once, since placing an order and an order actually
being paid are not necessarily the same moment (a bank transfer, for one).

## Requirements

Modulento 0.47.0 or newer.

## Installing

In Modulento, open **Administration → Packages**, enter
`alex01at/modulento-ext-shop` and install. Then enable "Shop" under
**Administration → Extensions**; this creates the tables. Give your own
account a provider profile and have it approved - that is the shop's one
provider. Set the flat shipping rate under **Administration → Shop**.

By hand: unpack a release into `extensions/shop/` of the installation and
enable the extension.

## Data

The extension keeps its data in tables of its own, all starting with
`x_shop_`: `x_shop_variant` and `x_shop_variant_translation` (reference the
core's offers, go with them), `x_shop_cart_item` (references the core's
accounts and this extension's variants), `x_shop_discount` and
`x_shop_cart_discount`, and the digital-product columns added to
`x_shop_variant` itself. Removing the package leaves the tables in place.
A digital variant's file lives outside the web root under
`var/uploads/shop-downloads/<offerId>/`, next to the file an offer's own
pictures use the same way.

## Changing the look

The templates in `templates/` are rendered as `@shop/<file>.twig`. Do not
edit them here - an update would replace the files. A theme overrides a
template by bringing a file of the same name in
`themes/<theme>/extensions/shop/`.

## Development

The tests are part of the Modulento repository and expect the extension at
`extensions/shop` there - for example as a symlink to this clone:

```
ln -s /path/to/modulento-ext-shop /path/to/modulento/extensions/shop
cd /path/to/modulento && php tests/run.php
```

This repository itself only checks the syntax of its PHP files on every push.

## Releasing

Set `version` in `extension.json`, commit, then tag and push:

```
git tag v0.1.0 && git push origin main v0.1.0
```

The workflow checks that tag and `extension.json` agree, builds
`modulento-ext-shop-<version>.zip` with its SHA-256 and publishes both as a
GitHub Release.

## Licence

GPL-3.0-or-later, see `LICENSE`.
