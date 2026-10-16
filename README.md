# Ruachost Domain Registrar Module for WHMCS

Sell domain names under your own brand from your own WHMCS, with Ruachost
handling registration behind the scenes. Your customers order and manage
domains in your WHMCS as usual; each registration, transfer and renewal is paid
from your prepaid Ruachost credit balance.

This module is for **Ruachost reseller partners**. You need a Ruachost partner
account and API credentials to use it.

## Requirements

- WHMCS 8.0 or later
- PHP 8.1 or later, with the cURL extension
- Outgoing HTTPS (port 443) from your server
- An accurate server clock (NTP time sync); requests more than 5 minutes off are rejected
- A Ruachost partner account with an API key and secret

## Install

1. Download the latest release from the **Releases** page, or click
   **Code → Download ZIP**.
2. Copy the `modules` folder from the download into the root of your WHMCS
   installation. The result must be:

   ```
   <your-whmcs>/modules/registrars/ruachost/ruachost.php
   ```

   Don't rename the `ruachost` folder; WHMCS finds the module by that name.
3. In your WHMCS admin, go to *Configuration → System Settings → Domain
   Registrars*, find **Ruachost** and click **Activate**.
4. Enter your **API key** (starts with `rh_`) and **API secret**. Leave
   *API endpoint* blank unless Ruachost tells you otherwise.
5. Click **Save Changes**. Saving tests your credentials with Ruachost. An
   error at the top of the page means the test failed and nothing was saved.

Then set your prices and register a test domain:

- **Import prices:** *Utilities → Registrar TLD Sync → Ruachost* imports your Ruachost costs so you can set your margin.
- **Set the registrar:** under *System Settings → Domain Pricing*, set **Auto Registration** to **Ruachost** for each extension you sell.
- **Fund and test:** add funds to your Ruachost account, then register a test domain.

## Update

Download the new release and copy the `modules` folder over your existing one.
Your settings are kept. See [CHANGELOG.md](CHANGELOG.md) for what changed.

## Features

- Domain availability search
- Register, transfer and renew
- Nameservers, registrar lock, contact details, EPP code and ID protection
- Domain and transfer status sync
- TLD price import
- Clear error messages, each with a reference you can quote to Ruachost support

## Your API credentials

Create, rotate and revoke your API keys yourself in your Ruachost client area
([dashboard.ruachost.com](https://dashboard.ruachost.com)),
under *Account → API Credentials*. To rotate without downtime:

1. Create a new key.
2. Enter it in WHMCS and save.
3. Revoke the old key.

Keep your API secret private: anyone holding it can place orders against your
credit. Never commit it to a repository or send it by email.

## Troubleshooting

To see full request details, turn on **Debug logging** in the module settings
and check *Configuration → System Logs → Module Log*. Errors are logged even
with debug logging off. Your secret, EPP codes and contact details are never
logged.

| Message contains | What to do |
| --- | --- |
| API endpoint is not configured | Download the latest release, or enter the endpoint Ruachost gave you |
| Invalid or revoked API credentials | Re-enter your key and secret, or create a new key in your Ruachost client area |
| timestamp is outside the allowed window | Ask your host to enable NTP time sync |
| are not allowed for this account | Send Ruachost your server's public IP address |
| Could not reach Ruachost | Ask your host to allow outbound HTTPS (port 443) |
| credit balance is too low | Add funds, then retry; failed attempts are not charged |

Retrying a failed registration, transfer or renewal is safe. The module
recognises repeats and never charges twice.

## Support

Open a ticket from your Ruachost client area at
[dashboard.ruachost.com](https://dashboard.ruachost.com). Include the domain, what failed, when it
happened, and the reference (`Ref: …`) from the error message.

## Licence

Copyright © 2026 Ruach Hosting Limited (Ruachost). All rights reserved.
Ruachost partners may install and use this module with their Ruachost account.
See [LICENSE](LICENSE).
