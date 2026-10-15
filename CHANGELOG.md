# Changelog

What changes for merchants and their customers, version by version. The
version at the top is `Wajub::VERSION` in `wajub.php` and `<version>` in
`config.xml`.

## 1.0.0 — unreleased

First version, for PrestaShop 8.1 to 9 and PHP 8.1 or later.

- **Mobile Money and Card** as payment options of their own, with Wajub's payment form inside the checkout, in the theme's colours and font (Classic on PrestaShop 8, Hummingbird on 9). Mobile Money payers pick their operator from a list with its logo, or the operator is found from the number (a setting). **Other payment methods** sends the payer to Wajub's payment page, offering only what the account has beyond Mobile Money and cards. Each option shows only when the Wajub account offers it for the cart.
- The order is created when the customer places it, in "Awaiting Wajub payment", and its confirmation e-mail waits until it is paid. A payment declined, cancelled or left with "Use another payment method" gives the cart back, unless a payment may still go through. Without JavaScript, or when the form cannot load, the payer pays on Wajub's page.
- Each Wajub payment that goes through becomes a payment of the order, for what it collected. An order paid in part is "Partially paid with Wajub", and the customer pays the rest from the order confirmation page or their account ("Pay the rest"); instalment payments are taken into account. An order is never cancelled while one of its payments may still go through, and the merchant is told of a second payment and of a payment in another currency.
- Sandbox and Live never mix: each environment pays with its own key, a payment is read, cancelled and refunded with its own environment's key, and a refund across environments is refused.
- Refunds both ways: a partial refund, standard refund or product return on the order page is refunded through Wajub before PrestaShop makes the credit slip (refused, it makes none and says why); a refund made in the Wajub Dashboard is recorded as a credit slip. "Check status" on the order page. Disputes are noted on the order.
- "Connect with Wajub" creates the shop's key from the Wajub Dashboard, with nothing to copy; keys can be pasted instead. From 14 days before a key it created expires: a notice on every page of the back office (PrestaShop 9's legacy pages included), and one e-mail per key to the shop's e-mail address.
- The webhook is registered automatically, and the Webhooks tab says when the last delivery arrived, or why it was refused and what to do.
- A scheduled task (PrestaShop's Cron tasks manager, or an address for the host's cron) reads again the payments that could not count yet and cancels the orders left unpaid after a delay you choose (60 minutes by default).
- Multistore: each shop, group of shops or all shops can have its own connection (environment, keys, webhook); the settings page says which shop it is for and where each key comes from. A shop with a key of its own keeps its own environment, and never pays with the other environment's key of the shops above it. A shop can go back to the shared connection once no payment made with its own key is under way. A webhook delivery is judged by the environment of the shop whose order it is for.
- PrestaShop's GDPR module exports a customer's Wajub payments, and erases what the module keeps of them: that customer's alone, never a guest's with the same e-mail address.
- A one-page checkout module that draws the payment options its own way: the payer pays on Wajub's page.
- In English and French.
