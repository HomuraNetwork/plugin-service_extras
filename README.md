# Service Extras for Blesta

Service Extras lets customers purchase additional products from an existing active service. Each purchase is stored as a normal Blesta service linked to the original service, invoiced separately, and provisioned after payment.

The purchase page appears only while the customer manages an eligible service. Products do not need to appear while the original service is being ordered.

## Requirements

- Blesta 6.0.0-b4 or later
- A module that supports the required Service Extras capability
- A package group containing the products customers may purchase

## Installation

Upload the plugin to:

```text
/path/to/blesta/plugins/service_extras/
```

Install and enable **Service Extras** under Blesta plugin settings. Rules are managed under **Packages > Service Extras**.

## Prepare a product group

Create a separate package group for the products that will be offered after a service has been provisioned. Add one or more packages to that group and configure their normal Blesta pricing and Configurable Options.

The package pricing controls the service lifecycle:

- One-time pricing creates a service without renewal invoices.
- Day, week, month, or year pricing creates a normally renewing service.
- A compatible module may supply a specific end time. Service Extras shows that time before purchase and schedules the child service to end at that time.

A module may restrict a capability to selected billing periods. For example, the VirtFusion Traffic Block capability accepts one-time pricing only, while Service Extras itself supports both one-time and recurring products.

When these products must not appear during initial ordering, do not associate the product group with the parent Standard Group through Blesta's native Addon Group relationship. The Service Extras rule provides the post-provision relationship instead.

## Create a rule

Open **Packages > Service Extras** and select **Add Rule**.

| Setting | Purpose |
| --- | --- |
| Service Page Name | The separate tab name shown while the customer manages an eligible service. |
| Module Capability | The function offered by the parent service module, such as `traffic_block` or `bandwidth_reset`. |
| Eligible Parent Packages | Individual packages whose active services may show this page. |
| Eligible Parent Package Groups | Optional groups whose packages may show this page. |
| Product Group | The group containing the products and pricing offered on this page. |
| Required Configurable Option Name | Optional internal option name that must exist on the parent service, such as `software`. |
| Allowed Configurable Option Values | Optional exact internal values that may use the rule. |
| Enabled | Controls whether the page is available for new purchases. |

Each rule creates its own service tab. Multiple rules may match the same parent service and appear as separate pages with different names, products, capabilities, and eligibility requirements.

After adding a new package to an eligible parent group, save the rule again so Service Extras is attached to that package.

## Customer purchase flow

The customer opens the rule's page from an active service, selects a product, billing term, and Configurable Options, then refreshes the purchase preview.

When the customer confirms the purchase:

1. Blesta creates a pending service linked to the original service.
2. Blesta creates an invoice using the selected package pricing and Configurable Options.
3. Payment activates the pending service and calls its module for provisioning.
4. Recurring products continue through Blesta's normal renewal process. One-time products do not renew.

The invoice and service use the selected product package, so product names and Configurable Option names should be written clearly for customers.

## Scheduled service end

Some products are valid only until a date supplied by the module. Service Extras displays this date in the confirmation preview and stores it as the service's scheduled end date.

One-time pricing prevents renewal invoices but does not cancel the service record by itself. If the module supplies no end date, the service follows only its Blesta package pricing and remains available for staff to complete or close when appropriate. Disabling or deleting a rule does not cancel existing services or alter existing invoices.

## VirtFusion Traffic Block example

The [VirtFusion Direct Provisioning Mod](https://github.com/HomuraNetwork/module-virtfusion_direct_provisioning_mod) supports the `traffic_block` capability.

Recommended setup:

- Enable Traffic Blocks in the VirtFusion module row.
- Create a VirtFusion package with **Product Type** set to **Traffic Block**.
- Use one-time pricing for the Traffic Block package.
- Add an `amount` Configurable Option whose internal value is the number of GB to provision.
- Place the package in a dedicated product group that is not attached to the parent VPS order group.
- Create a Service Extras rule for the eligible VPS packages and select that product group.

The purchase preview shows the current VirtFusion traffic period end. After payment, the module checks the period again, updates the child service to the actual activation-period end when necessary, and provisions the block. Closing the Blesta service does not remove the remote block early.
