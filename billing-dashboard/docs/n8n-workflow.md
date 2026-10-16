# n8n delivery workflow deployment checklist

Keep this as a separate workflow from final-video upload. The dashboard does not trigger file sharing.

1. Add a Schedule Trigger.
2. Add HTTP Request `GET /api/n8n/eligible` with the dedicated `N8N_API_TOKEN` bearer credential.
3. Split returned `items` into individual records.
4. Call `POST /api/n8n/claim/{id}` with the same credential. Stop on any non-2xx response; retain `item.delivery_claim_token`.
5. Immediately before sharing, verify the row still has `payment_status=cleared`, `invoice_status=sent`, and the current claim token. A recommended production pattern is a read-only MariaDB query node:

```sql
SELECT id FROM final_delivery_billing
WHERE id = ?
  AND payment_status = 'cleared'
  AND invoice_status = 'sent'
  AND delivery_status = 'processing'
  AND delivery_claim_token = ?;
```

6. Only proceed when the query returns exactly one row.
7. Grant access to the intended customer using a restricted Drive permission. Do not use `type=anyone`.
8. Send the final link via WhatsApp.
9. Report success to `POST /api/n8n/complete/{id}` with claim token, `status: delivered`, and final HTTPS link. On a workflow error, report `status: failed` and a short error description.

Use n8n credentials storage for Drive, WhatsApp, database and dashboard bearer token credentials. Restrict workflow editors and execution logs. Test with a non-customer file before enabling production delivery.
