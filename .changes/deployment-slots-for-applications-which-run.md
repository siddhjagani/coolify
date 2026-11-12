---
type: added
---
Deployment slots for applications, which run next to production, take over the production traffic with `Swap` without downtime, and can share the traffic by weight

- The `Deployment Slots` page of an application adds slots, makes a slot production, and sets the `Traffic split`
- A swap moves the domains with their proxy settings, HTTP basic auth, network aliases, automatic deployment, traffic share, scheduled tasks, and the name, and restarts both applications. Code, environment variables, and volumes stay with each application
- The traffic split sends a percentage of the production traffic to each slot on the same server, and a cookie keeps each visitor on one slot. A stopped slot gets no traffic
- The API has `GET`, `POST /applications/{uuid}/slots`, `PATCH /applications/{uuid}/slots/traffic`, and `POST /applications/{uuid}/slots/swap`
