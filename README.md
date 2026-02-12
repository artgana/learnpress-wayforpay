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
