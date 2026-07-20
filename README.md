# Service Extras for Blesta

Service Extras lets customers purchase additional products from an existing active service. Each purchase is stored as a normal Blesta service linked to the original service, invoiced separately, and provisioned after payment.

The purchase page appears as a separate tab on eligible active services in both the client Manage Service page and the staff Service Detail page. Products do not need to appear while the original service is being ordered.

## Requirements

- Blesta 6.0.0-b4 or later
- A package group containing the products customers may purchase

Modules may optionally provide availability checks, purchase details, a specific service end time, and trusted review markup displayed after the standard purchase details. Products without these module hooks still use the normal Blesta service and invoicing flow.

## Installation

Upload the plugin to:

```text
/path/to/blesta/plugins/service_extras/
```

Install and enable **Service Extras** under Blesta plugin settings. Rules are managed under **Packages > Service Extras**.

The **Unpaid purchase expiry** setting on that page controls how many hours a generated invoice may remain unpaid before its pending child service is removed. The default is 12 hours.

When updating an existing installation, replace the plugin files and run **Upgrade** for Service Extras in Blesta. The upgrade installs the purchase-tracking table and the five-minute unpaid-purchase cleanup task.

## Prepare a product group

Create a separate package group for the products that will be offered after a service has been provisioned. Add one or more packages to that group and configure their normal Blesta pricing and Configurable Options.

The package pricing controls the service lifecycle:

- One-time pricing creates a service without renewal invoices.
- Day, week, month, or year pricing creates a normally renewing service.
- A compatible module may supply a specific end time. Service Extras shows that time before purchase and schedules the child service to end at that time.

A module may restrict a product type to selected billing periods. For example, the VirtFusion Traffic Block product accepts one-time pricing only, while Service Extras itself supports both one-time and recurring products.

When these products must not appear during initial ordering, do not associate the product group with the parent Standard Group through Blesta's native Addon Group relationship. The Service Extras rule provides the post-provision relationship instead.

## Create a rule

Open **Packages > Service Extras** and select **Add Rule**.

| Setting | Purpose |
| --- | --- |
| Service Page Name | The separate tab name shown to clients and staff while managing an eligible active service. |
| Package Group Filter | Filters the available parent package list while configuring the rule; the group is not saved as an eligibility condition. |
| Eligible Packages | Packages whose active services may show this page. |
| Extra Product Group | Filters purchasable packages and is stored on each created child service. |
| Offered Packages | The specific packages customers may purchase from this page. |
| Enabled | Controls whether the page is available for new purchases. |

The package selectors use separate Eligible/Offered and Available lists. Click a selected item again to clear its selection, use Ctrl/Command for multiple items, double-click to move an item, or use the arrow buttons. Package groups only filter the Available list; the explicitly selected packages are what the rule saves.

Each rule creates its own service tab. Multiple rules may match the same parent service and appear as separate pages with different names and products.

The service tab lists the active products explicitly selected by the rule when they have pricing in the client's currency. Products are shown as cards rather than a dropdown. Selecting a product does not run module validation. Validation and module-specific information are loaded when the customer reviews the purchase.

## Customer purchase flow

The customer opens the rule's page from an active service. The page identifies the parent package and service label, then guides the customer through three stages:

1. Select a product and configure its Configurable Options.
2. Review the target service, module information, price, and end time when one is supplied.
3. Continue directly to Blesta's payment flow.

When the customer continues to payment:

1. Blesta creates a pending service linked to the original service.
2. Blesta creates an invoice using the selected package pricing and Configurable Options.
3. The browser opens the payment page immediately.
4. Payment activates the pending service and calls its module for provisioning.
5. Provisioning normally completes within a few minutes after Blesta confirms payment.
6. Recurring products continue through Blesta's normal renewal process. One-time products do not renew.

The public invoice note identifies the parent package and service. Product names and Configurable Option labels should still be written clearly because they are also used in billing and service records.

Payment must be completed within the configured unpaid purchase expiry window. Every purchase created by this plugin is tracked separately. The cleanup task preserves paid or already-activated services; for an unpaid pending purchase it voids the invoice and removes the pending child service using Blesta's normal abandoned-order behavior.

## Scheduled service end

Some products are valid only until a date supplied by the module. Service Extras displays this date in the confirmation preview, explains that the child service will cancel automatically when the active period ends, and stores it as the service's scheduled end date.

One-time pricing prevents renewal invoices but does not cancel the service record by itself. If the module supplies no end date, the service follows only its Blesta package pricing and remains available for staff to complete or close when appropriate. Disabling or deleting a rule does not cancel paid existing services or alter their invoices.

## VirtFusion Traffic Block example

The [VirtFusion Direct Provisioning Mod](https://github.com/HomuraNetwork/module-virtfusion_direct_provisioning_mod) identifies a Traffic Block from the selected package's **Product Type**. No capability name needs to be entered in Service Extras.

Recommended setup:

- Create a VirtFusion package with **Product Type** set to **Traffic Block**.
- Enter the package's fixed **Block Size (GB)**.
- Use one-time pricing for the Traffic Block package.
- To let the customer choose the capacity, add a Quantity or Dropdown Configurable Option named `traffic` or `addon_traffic`. `addon_traffic` has priority over `traffic`, and either one overrides the package's fixed Block Size.
- Place the package in a dedicated product group that is not attached to the parent VPS order group.
- Create a Service Extras rule, select the eligible VPS packages, select the Traffic Block product group, and move the Traffic Block packages to **Offered Packages**.

The purchase preview shows the current VirtFusion traffic period end. After payment, the module checks the period again, updates the child service to the actual activation-period end when necessary, and provisions the block. Closing the Blesta service does not remove the remote block early.
