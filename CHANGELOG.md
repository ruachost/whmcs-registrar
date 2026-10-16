# Changelog

## 1.1.0

- Slow registrations now show as *Pending Registration* and complete through
  domain sync, instead of failing.
- New **Debug logging** setting. Errors are always logged; EPP codes, contact
  details and your API secret are never logged.
- Customer contact details and terms are checked before an order is sent, so
  obvious mistakes fail immediately and cost nothing.
- Every error message ends with a support reference.
- Clearer messages for suspended accounts, paused purchases and rate limits.
- Request signing updated (RH1). Version 1.1.0 is required; update from 1.0.0
  by copying the new files over the old ones.

## 1.0.0

- First release: availability, register, transfer, renew, nameservers, lock,
  contacts, EPP code, ID protection, sync and TLD price import.
