---
type: added
---
Automatic failover of applications to an additional server when the primary server goes down, in the `High availability` section of the application `Servers` page

- Coolify makes the first additional server that is up and runs the application primary, and points the DNS records that it manages for the application to that server
- It needs a Docker registry image and at least one additional server, and does not swap back on its own
- A primary server of such an application gets a connection check every minute, also while Sentinel reports, and a second check 15 seconds after a failed one, so the failover starts about one minute after the server goes down
- `Make primary` is now `Swap with primary`, and it also moves the DNS records that Coolify manages
- The new `Availability` page of a server and the `High availability` section show the uptime of the last 24 hours, 7 days, and 30 days, and the outages of the last 30 days
- The API has `GET /servers/{uuid}/availability`, `GET` and `PATCH /applications/{uuid}/availability`, and `POST /applications/{uuid}/destinations/{destination_uuid}/swap`
