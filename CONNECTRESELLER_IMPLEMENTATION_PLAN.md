# ConnectReseller Registrar Implementation Plan

## References

- `Connectreseller_API_Document.pdf`, API v11, dated 2025-11-04
- [Creating a Registrar Integration](https://docs.fossbilling.org/extensions-and-development/guides/creating-a-registrar-integration/)
- [FOSSBilling domain products](https://docs.fossbilling.org/admin-guide/product-types/domains/)
- `src/library/Registrar/AdapterAbstract.php`
- Existing adapters in `src/library/Registrar/Adapter/`

## 1. Add the registrar adapter

Create:

```text
src/library/Registrar/Adapter/Connectreseller.php
```

Implement `Registrar_Adapter_Connectreseller`, extending `Registrar_AdapterAbstract`.

Registrar configuration should contain:

- ConnectReseller API key
- Reseller ID, primarily for balance diagnostics
- A fixed production API base URL: `https://api.connectreseller.com/ConnectReseller/ESHOP/`
- A warning that the supplied API documentation does not define a sandbox URL

Use FOSSBilling's Symfony HTTP client, URL-encoded GET query parameters, JSON decoding, timeouts, and centralized exception handling. Never log the API key or a complete authenticated URL.

## 2. Build a reusable API client layer

Add private helpers inside the adapter:

```php
request(string $endpoint, array $parameters): array
domainName(Registrar_Domain $domain): string
getRemoteDomain(string $name): array
getDomainId(string $name): int
ensureCustomer(Registrar_Domain_Contact $contact): int
createContact(int $customerId, Registrar_Domain_Contact $contact): int
mapContactToParameters(Registrar_Domain_Contact $contact): array
assertSuccess(array $response, string $action): array
```

The response handler should:

- Require valid JSON.
- Read `responseMsg.statusCode` and `responseMsg.message`.
- Treat status code `200` as success unless an endpoint documents another success value.
- Include the registrar's message in `Registrar_Exception`.
- Tolerate inconsistent `responseData` shapes in the supplied PDF.
- Keep credentials and customer personal data out of logs.
- Convert HTTP, transport, timeout, and decoding failures into `Registrar_Exception`.

## 3. Customer and contact lifecycle

ConnectReseller requires a customer `Id` for registration, transfer, and renewal.

Implement this flow:

1. Call `ViewClient` using the FOSSBilling contact email.
2. If the client is absent, call `AddClient`.
3. Call `ViewClient` again to obtain the resulting `clientId`.
4. During registration, create a dedicated registrar contact with `AddRegistrantContact`.
5. After `domainorder`, assign the contact to the registrant, admin, technical, and billing roles using `updatecontact`.

Creating a dedicated contact per domain avoids changing the contact information of unrelated domains.

Registration must be idempotent. Call `ViewDomain` before submitting an order. If the domain already exists under the reseller, reconcile its nameservers, contact, privacy, and lock state instead of attempting another registration.

Country data needs explicit normalization. Confirm whether ConnectReseller accepts ISO 3166-1 alpha-2 codes from FOSSBilling or requires full country names before enabling live provisioning.

## 4. Required adapter method mapping

| FOSSBilling method | ConnectReseller endpoint | Implementation |
| --- | --- | --- |
| `isDomainAvailable()` | `checkdomainavailable` | Return the documented `available` value and reject premium results. |
| `isDomaincanBeTransferred()` | `checkdomainavailable` | Best-effort check that the domain is already registered; final eligibility is determined by the transfer order. |
| `registerDomain()` | `domainorder` | Provision the customer, submit the order, and assign the domain contact. |
| `transferDomain()` | `TransferOrder` | Use `OrderType=4`, the EPP code, privacy selection, and customer ID. |
| `renewDomain()` | `RenewalOrder` | Use `OrderType=2`, period, customer ID, privacy selection, and current expiry year. |
| `getDomainDetails()` | `ViewDomain` and `ViewRegistrant` | Populate dates, nameservers, contact, privacy, and lock state. |
| `modifyNs()` | `UpdateNameServer` | Resolve `domainNameId`, then submit nameservers 1-4. |
| `modifyContact()` | `AddRegistrantContact` and `updatecontact` | Create a new contact and assign it to all four domain roles. |
| `getEpp()` | `ViewEPPCode` | Resolve the domain ID and return the secret key. |
| `lock()` / `unlock()` | `ManageDomainLock` | Set `isDomainLocked` to `true` or `false`. |
| `enablePrivacyProtection()` / `disablePrivacyProtection()` | `ManageDomainPrivacyProtection` | Set `isWhoIsProtected` to `true` or `false`. |
| `deleteDomain()` | Unsupported | Throw a clear `Registrar_Exception`. |

The PDF's `syncTransfer` endpoint checks the status of an already placed transfer. It is not a genuine transferability pre-check. This limitation should be documented in the adapter.

## 5. Domain-detail mapping

Map the `ViewDomain` response into a new `Registrar_Domain` object:

| ConnectReseller field | FOSSBilling value |
| --- | --- |
| `creationDate` | Registration timestamp |
| `expirationDate` | Expiration timestamp |
| `nameserver1` through `nameserver4` | Domain nameservers |
| `isDomainLocked` | Lock state |
| `isPrivacyProtection` | Privacy state |
| `registrantContactId` | Input for `ViewRegistrant` |

Map the `ViewRegistrant` response into `Registrar_Domain_Contact`, including name, email, company, address, city, state, country, postcode, telephone, and fax fields.

Preserve ConnectReseller's numeric timestamps because the current FOSSBilling domain synchronization code expects numeric registrar timestamps.

## 6. Registration safety rules

For the initial release:

- Set `ProductType=1`.
- Set `isEnablePremium=0`.
- Reject premium domains because FOSSBilling uses static TLD pricing.
- Require at least two nameservers.
- Send nameservers 3 and 4 only when supplied.
- Pass the IDN `lang` parameter only when a supported language code is available.
- Reject `.us` registrations with a clear explanation until Nexus-purpose fields are collected from the customer.
- Do not invent regulatory defaults for restricted TLDs.
- Prevent write operations when FOSSBilling test mode is enabled unless ConnectReseller supplies a verified sandbox endpoint.

## 7. Unsupported and optional features

Do not include these in the initial registrar adapter:

- DNS record management
- Child nameserver management
- Domain forwarding
- Transfer cancellation and status polling
- Domain suspension
- Theft protection separate from the standard registrar lock
- Automatic TLD and pricing synchronization
- Reseller balance display

These features are outside `Registrar_AdapterAbstract` and require additional FOSSBilling module, API, or interface work.

ConnectReseller's `tldsync` endpoint can later support an administrator import or audit tool. It should not silently update retail pricing. FOSSBilling currently expects administrators to configure prices and enabled TLDs manually.

## 8. Testing

Create:

```text
tests/Unit/Registrar/Adapter/ConnectresellerTest.php
```

Use Symfony `MockHttpClient` and `MockResponse` fixtures to test:

- Available and unavailable domains
- Premium-domain rejection
- Existing and newly created customers
- Contact parameter normalization
- Successful registration
- A retry after partial registration
- Transfer success and invalid EPP errors
- Renewal
- Domain-detail and contact mapping
- Nameserver modification
- Privacy and lock operations
- Malformed JSON
- Non-successful HTTP responses
- Registrar status errors
- Missing or malformed response fields
- Confirmation that API keys are absent from logs and exceptions
- Unsupported deletion
- Rejection of mutations in test mode

After automated tests pass, run controlled live tests using a low-cost domain before enabling automated customer provisioning.

## 9. Delivery sequence

1. Implement configuration and request/response handling.
2. Implement read-only availability and domain lookup.
3. Implement customer and contact provisioning.
4. Implement registration with idempotency and reconciliation.
5. Implement transfer and renewal.
6. Implement nameserver, contact, privacy, lock, and EPP management.
7. Add unit tests, formatting checks, and static analysis.
8. Configure ConnectReseller under **System -> Domain registration**.
9. Create prices manually and enable only verified TLDs.
10. Run live registration, contact, nameserver, lock, privacy, transfer, and renewal tests where feasible.
11. Enable the adapter for customer orders after live reconciliation tests pass.

## 10. Acceptance criteria

The implementation is ready for production when:

- Every method required by `Registrar_AdapterAbstract` is implemented.
- API credentials never appear in logs or customer-facing exceptions.
- Duplicate activation attempts cannot create duplicate orders.
- Domain registration, transfer, renewal, synchronization, nameserver changes, contact changes, privacy, lock, and EPP retrieval are covered by tests.
- Unsupported operations fail explicitly instead of reporting false success.
- Restricted and premium domains cannot bypass pricing or registry requirements.
- A live end-to-end test domain remains consistent between ConnectReseller and FOSSBilling after synchronization.
