1. Two dimensions of status (important design point)

Across SAP, Dynamics, Odoo, NetSuite, etc., delivery/fulfilment usually has:
	1.	Document lifecycle / stock status
	•	Does this document still move stock?
	•	Has the goods issue happened?
	•	Can we invoice?
	2.	Operational logistics status
	•	Where is the shipment physically? (picking / packed / in transit / out for delivery / delivered / failed / returned)
	•	Has the customer actually confirmed receipt (POD)?

Big systems often reflect this as multiple status fields:
	•	SAP: overall status, goods movement status, POD status, billing status, etc.  ￼
	•	Dynamics 365: “Status” (Open, Delivered, Invoiced, etc.) vs “Document Status” (Confirmed, Picked, Packed, Invoiced).  ￼
	•	NetSuite: shipping status like Picked / Packed / Shipped on item fulfillments.  ￼
	•	Odoo: picking (delivery) states like Draft, Waiting, Ready, Done, Cancelled, and add-on modules show Partially Delivered etc.  ￼

So instead of one long list of statuses that tries to encode everything, they separate:
	•	document_state (for accounting & stock), and
	•	logistics_state (for ops & tracking).

That’s the pattern I’d copy.

⸻

2. Typical status sets by document type (overview)

Just to frame it:

Quotation
	•	Draft
	•	Sent
	•	Accepted
	•	Rejected
	•	Expired / Cancelled

Sales Order
	•	Draft
	•	Confirmed
	•	Partially Delivered
	•	Delivered
	•	Partially Invoiced
	•	Fully Invoiced
	•	Closed / Cancelled

Delivery Note / Outbound Shipment (our main topic)

Document / stock dimension (ERP side, like Odoo/SAP):
	•	Draft / Planned
	•	Waiting / Waiting for stock
	•	Ready to pick / Ready
	•	In Progress (picking/packing started)
	•	Shipped / Goods Issue Posted / Done
	•	Returned
	•	Cancelled

Logistics dimension (TMS / driver app side):
	•	Not dispatched
	•	Assigned to driver
	•	Out of warehouse / Out for delivery
	•	In transit
	•	Delivered (arrived at destination)
	•	POD confirmed (signed / OTP / photo)
	•	Delivery failed (no one home, wrong address, etc.)
	•	Returned to sender

Invoice
	•	Draft / Proforma
	•	Posted (accounting)
	•	Partially Paid
	•	Fully Paid
	•	Cancelled / Reversed (via credit note)

Payment
	•	Pending
	•	Confirmed
	•	Allocated (reconciled to invoice(s))
	•	Reversed / Chargeback

You obviously don’t need all of these to start, but you want something compatible.

⸻

3. How big ERPs handle delivery status specifically

3.1 Odoo-style (warehouse / stock focus)

Odoo delivery orders (WH/OUT) basically revolve around:
	•	Draft
	•	Waiting / Waiting for Availability
	•	Ready
	•	Done (stock moved)
	•	Cancelled  ￼

Add-ons & UX show “Partially Delivered, Delivered” at SO level, not at the delivery note itself.

No built-in distinction like “in delivery” vs “handed over” – that’s usually offloaded to carriers or a custom driver app.

⸻

3.2 SAP-style (goods issue + proof of delivery)

In SAP SD:
	•	The delivery document has goods movement status – has goods issue (GI) been posted?  ￼
	•	Optionally, there is Proof of Delivery (POD):
	•	POD status can be e.g. POD relevant / Deviations reported / Confirmed.  ￼
	•	POD can be required before invoicing (some industries invoice only after customer confirms actual quantity received).  ￼

Again, they’re separating:
	•	“Goods left my warehouse” = Goods Issue Posted
	•	“Customer confirmed they got it” = POD Confirmed

The last-mile path (in transit / out for delivery / etc.) is normally a TMS concern.

⸻

3.3 NetSuite & other SaaS ERPs (pick/pack/ship)

NetSuite’s fulfillment records have shipping statuses: Picked / Packed / Shipped, and dates for each.  ￼

Last-mile statuses like “out for delivery / delivered / exception” tend to live in:
	•	Courier integrations
	•	Last-mile platforms (e.g., ePOD apps) that send back a final status + POD.

External logistics tools commonly use flows like:

Picked up → In transit → Out for delivery → Delivered (+ optional exceptions like shipment exception)  ￼

And then you have a POD concept (signature, photo, OTP).  ￼

⸻

4. So what about your statuses: Out of warehouse, In delivery, Delivered, Handed over?

Let’s map these to industry practice.

4.1 Out of warehouse

Matches “Goods Issue Posted / Shipped” in ERP terms.
	•	Stock is already decreased.
	•	From a certification point of view: this is typically the moment that finalizes the outbound movement for stock valuation.
	•	In many implementations, this is when the ERP delivery note document moves to Done / Shipped / Completed.

4.2 In delivery

Equivalent to In transit / Out for delivery:
	•	The driver has the goods on the vehicle and is on the way to the customer.  ￼
	•	Operationally interesting (ETA, route optimization).
	•	From accounting & certification perspective: irrelevant. The goods are already out; only POD still matters.

4.3 Delivered

Ambiguous wording unless you define it clearly:
	•	Many carriers use Delivered as “package is at the destination” (could be a front desk, neighbor, drop-off point).
	•	Some systems treat “Delivered” = also POD done, but more and more they split them to avoid disputes.

4.4 Handed over

This is very close to Proof of Delivery confirmed:
	•	Recipient has actually accepted the goods.
	•	You have a signature / photo / OTP as evidence.  ￼
	•	In SAP terms this is “POD confirmed”.  ￼

So if you adopt Delivered vs Handed over, I’d define:
	•	Delivered = driver says: “I left it at the place it should be”.
	•	Handed over = customer confirmation exists (POD).

That’s actually very clean conceptually.

⸻

5. A pragmatic status model I’d recommend for your ERP

5.1 At delivery note (document / stock) level

Keep this simple and ERP-centric, aligned with what Odoo/SAP/Dynamics do:
	•	draft – created but not confirmed
	•	planned / waiting – confirmed but pending stock or scheduling
	•	picking – warehouse is preparing
	•	picked – all lines picked
	•	packed – goods packed and ready to leave
	•	shipped (aka goods_issue_posted) – left warehouse, stock moved
	•	returned – return flow completed
	•	cancelled – logically cancelled (no GI or fully reversed)

For certification: only “shipped / goods_issue_posted” matters for stock side; invoicing will add its own layer.

⸻

5.2 At logistics / last-mile level (driver app / TMS)

Here you can be richer and very close to what you proposed:
	•	not_dispatched
	•	assigned_to_driver
	•	out_of_warehouse (vehicle departed)
	•	in_transit
	•	out_for_delivery (at last hub, on its final route)
	•	delivered (driver says it’s dropped off)
	•	pod_confirmed (your “handed over”: signature / photo / OTP)
	•	failed_attempt (customer absent, address issue)
	•	returned_to_sender

You could rename for UI:
	•	Out of warehouse
	•	In delivery
	•	Delivered
	•	Handed over

… while keeping system/internal names more generic.

⸻

5.3 Link between the two layers

You can do something like:
	•	When goods_issue_posted = true → document_state = shipped.
	•	When driver first leaves warehouse → logistics_state = out_of_warehouse.
	•	When driver marks at destination → logistics_state = delivered.
	•	When POD captured → logistics_state = pod_confirmed AND set a pod_date, pod_user, pod_evidence.

Then:
	•	SO overall status becomes:
	•	Not delivered / Partially delivered / Fully delivered
	•	POD pending / POD confirmed

And you can decide for some customers / countries:
	•	“Invoice allowed only when goods_issue_posted = true”
	•	or “Invoice allowed only when pod_confirmed = true” (SAP POD scenario).

⸻

6. Is this common enough to be “standard practice”?
	•	The exact labels differ, but the pattern of:
	•	stock status (picked/packed/shipped)
	•	shipment tracking status (in transit/out for delivery/delivered)
	•	POD confirmation
is absolutely standard in warehouse + TMS + ePOD setups.  ￼
	•	In classical ERPs without a TMS, people usually stop at:
	•	Draft → Ready → Done (shipped), maybe “Partially” and “Cancelled”.  ￼

You’re just bringing the TMS/last-mile logic into your ecosystem, which is actually a strong product differentiation (especially with your driver app).

⸻

7. What I’d do for your ERP specifically

For your rebuild, I’d:
	1.	Define a generic status dictionary per domain:
	•	document_state (for every document type)
	•	logistics_state (for deliveries / shipments only)
	2.	Start minimal in v1, but design it so you can:
	•	turn on extra statuses per country / vertical,
	•	enforce validation rules (e.g., invoice can’t be created before goods_issue_posted in country X, or before pod_confirmed in country Y).
	3.	Expose friendly labels per vertical:
	•	For car parts wholesalers: maybe just Picking → Packed → Shipped → Delivered.
	•	For last-mile courier flows: Out of warehouse → In delivery → Delivered → Handed over (POD).
