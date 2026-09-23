---
paths:
  - 'app/Controller/Media/**'
  - 'app/Service/Media/**'
---

# Media

## Protect private media URLs if introduced
For future private media GET/HEAD endpoints, use short-lived authorization tied to the current user or token, avoid logging signed URLs or credentials, and recheck ownership, deletion, and expiry before serving each file.

## Keep media storage locations portable
If resource originals, previews, or archives are added, choose their storage through explicit project configuration; persist the chosen location and path for later reads and cleanup across machines.

## Keep media logs safe
Use the project's Hyperf logger with safe resource IDs, status, and byte counts; exclude sensitive query strings and cookies from general request logs for private media endpoints.
