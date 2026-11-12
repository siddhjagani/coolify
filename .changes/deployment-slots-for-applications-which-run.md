---
type: added
---
Deployment slots for applications, which run a staging slot next to production and move the production traffic to it with `Swap` without downtime

- The `Deployment Slots` page of an application creates the staging slot and swaps it
- A swap moves the domains with their proxy settings, HTTP basic auth, network aliases, automatic deployment, scheduled tasks, and the name, and restarts both applications. Code, environment variables, and volumes stay with each application
- The API has `POST /applications/{uuid}/slots` and `POST /applications/{uuid}/slots/swap`
