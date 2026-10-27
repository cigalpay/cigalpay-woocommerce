# CigalPay for WooCommerce

Official WooCommerce plugin for [CigalPay](https://cigalpay.com): accept crypto payments (Bitcoin, USDT, Ethereum, Solana, Litecoin, Dogecoin and more) in your store. Your prices stay in USD or EUR, and the customer picks the coin on the CigalPay checkout.

- Works with the block-based checkout (the default since WooCommerce 8.3) and the classic checkout.
- Compatible with High-Performance Order Storage (HPOS).
- No webhook to set up: the plugin sends its notification address with every payment.
- Each order is checked against the checkout created for it, at the order's price.

## Requirements

- WordPress 6.3+
- WooCommerce 8.0+
- PHP 7.4+
- Store currency: USD or EUR
- A CigalPay account ([sign up](https://cigalpay.com))

## Install

1. Download `cigalpay-for-woocommerce.zip` from the [latest release](https://github.com/cigalpay/cigalpay-woocommerce/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, pick the zip and activate it.

## Set up

1. In your CigalPay dashboard, open **Settings → API credentials** and copy your **Account ID**, your **notification signing secret** and, to refund orders in crypto, your **API key**.
2. In WordPress, open **WooCommerce → Settings → Payments → CigalPay**.
3. Paste the values, check **Enable** and save.

You can also change the title and description customers see at checkout.

## How it works

1. The customer chooses crypto at checkout and is sent to the CigalPay checkout.
2. They pick a coin and pay.
3. CigalPay notifies your store each time the payment status changes, and the plugin updates the order:

| CigalPay status | Order |
| --- | --- |
| `WAITING`: coin chosen | Stays **Pending payment**, with a note |
| `CONFIRMING`: payment seen on the network | **On hold**, so WooCommerce doesn't cancel it as unpaid while it confirms |
| `CONFIRMED` / `FINISHED` | **Processing** (paid). The CigalPay payment id is the transaction id |
| `PARTIALLY_PAID` | **On hold**, with a note showing the amount received and the amount expected |
| `EXPIRED` / `FAILED` | Unchanged, with a note. The customer can pay again from their order |

A payment made in another coin and converted automatically is marked paid the same way. The order note shows the coin the customer used.

If a customer pays twice, the order is paid once and the second payment is recorded in a note, so you can refund it.

## Refunds

On the order page, click **Refund**, enter the amount and click **Refund via CigalPay**:

1. The amount is converted into the coin the customer paid, at the payment's rate, so they get back the same share of what they sent.
2. It comes out of your CigalPay balance in that coin, with the network fee.
3. The customer gets an email with a link to enter the address to refund. CigalPay reviews it and sends the coin.

The order notes show the refund and the customer's link. If CigalPay refuses it, for example when the balance is too low, WooCommerce shows the reason and no refund is recorded. A payment forwarded to your own wallet isn't on your CigalPay balance: refund it from that wallet. CigalPay's fee on the payment is not refunded.

## Security

Every notification is checked before it touches an order:

1. **Signature.** The `x-cigalpay-sig` header must match the body, signed with your notification signing secret. Otherwise the request is rejected with `401`.
2. **Checkout.** The payment must come from a checkout this store created for that order. Anyone can create a CigalPay checkout with your Account ID and an order number, so the order number alone is never trusted.
3. **Amount.** The checkout's price must still match the order total. If the order was changed after checkout, it is put on hold with a note instead of being marked paid.

## Logs

Errors are logged in **WooCommerce → Status → Logs**, source `cigalpay`. Turn on **Debug log** in the plugin settings to log every notification.

## FAQ

**Can I refund from WooCommerce?**
Yes, see [Refunds](#refunds). Add your API key in the settings first.

**My store isn't in USD or EUR.**
CigalPay is hidden at checkout, and the settings page shows a notice.

**What does the plugin send to CigalPay?**
The order total and currency, the order number, the store name, the return and notification addresses, and the customer's language. No customer names, emails or addresses. When you refund an order, the customer's billing email, so CigalPay can send them the refund link.

## For developers

The plugin uses the WordPress HTTP API and needs no Composer dependencies. To point it at another CigalPay checkout API, for example for local testing:

```php
add_filter( 'cigalpay_wc_checkout_url', function () {
    return 'http://localhost:3501';
} );
```

To integrate CigalPay outside WooCommerce, use the [PHP SDK](https://github.com/cigalpay/cigalpay-php-sdk) or the [JavaScript SDK](https://github.com/cigalpay/cigalpay-js-sdk).

## Support

- Docs: [cigalpay.com/developers](https://cigalpay.com/developers)
- Email: support@cigalpay.com
- Bugs and requests: [GitHub issues](https://github.com/cigalpay/cigalpay-woocommerce/issues)

## License

MIT
