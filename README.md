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

This extension is built for exactly one approved provider - the shop's
operator. Nothing stops installing it with more than one, but a cart that
somehow holds products from different providers is refused at checkout
rather than guessed at (see `CheckoutController::singleProviderId()`); nor
does it try to split one cart into several orders.

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
core's offers, go with them), and `x_shop_cart_item` (references the core's
accounts and this extension's variants). Removing the package leaves the
tables in place.

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
