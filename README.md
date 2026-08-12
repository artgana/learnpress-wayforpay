## LearnPress – WayForPay Payment Gateway

Custom payment gateway that integrates the [WayForPay](https://wayforpay.com/) payment provider with the [LearnPress](https://wordpress.org/plugins/learnpress/) LMS plugin for WordPress.

The gateway:

- **Adds “WayForPay” as a payment method** in LearnPress → Settings → Payments.
- **Redirects students to WayForPay** to complete payment.
- **Handles WayForPay callbacks** to automatically update LearnPress orders (completed / failed).

---

## Requirements

- **WordPress**: 6.3 or higher  
- **PHP**: 7.4 or higher  
- **LearnPress**: 4.0.0 or higher  
- **WayForPay merchant account** with active credentials

---

## Configuration

Once the plugin is activated:

1. Go to **LearnPress → Settings → Payments**.
2. You should see a **WayForPay** section in the left-hand list of payment methods.
3. Click **WayForPay** to access its settings.

The available options are:

- **Enable/Disable**  
  Turn the WayForPay gateway on or off.

- **Title**  
  The payment method label students see at checkout (default: “WayForPay”).

- **Description**  
  Short description shown under the payment method at checkout.

- **Merchant Account**  
  Your WayForPay **Merchant Account ID**.

- **Secret Key**  
  Your WayForPay **Secret Key** (used to sign and verify requests).

- **Order Timeout**  
  Time in seconds before the payment session expires on WayForPay (default: `49000`).

- **Debug Mode**  
  When enabled, the gateway writes debug messages to `debug.log` using `error_log()`.  
  Only enable this in development or when troubleshooting.

---

## Changelog

### 4.1.0

- **Fix:** checkout totals sent to WayForPay could mismatch when a coupon/discount was applied. The `amount` field always reflected the discounted order total, but each line item's `productPrice` still used the pre-discount course price, so `amount` and `Σ(productPrice × productCount)` could disagree — a mismatch WayForPay's own validation can reject. Line item prices are now prorated against the order total so the two always match, with or without a coupon.
- **Fix:** the callback signature check built its HMAC over a conditional subset of fields, omitting `authCode`/`cardPan`/`transactionStatus`/`reasonCode` whenever they were empty. WayForPay always signs the full fixed field set regardless of value, so a declined/failed transaction (empty `authCode`) could fail signature verification and be wrongly rejected as invalid. The full fixed field list is now always used, with empty-string fallbacks.
- **Security:** signature comparisons now use `hash_equals()` instead of `!==` to avoid timing-attack exposure.
- **Hardening:** the `$_POST` fallback in the callback handler is now run through `wp_unslash()` before use, per WordPress data-handling conventions.
- **Feature:** implemented `refund()`, wiring the gateway into LearnPress's built-in refund flow (Orders screen "Refund" action, and self-service refund requests if enabled). Calls WayForPay's REFUND API directly; LearnPress's core order code still owns marking the order refunded and recording who/when/how much.

### 4.0.0

- Initial release: WayForPay payment gateway for LearnPress (checkout redirect, server-to-server callback, admin settings).
