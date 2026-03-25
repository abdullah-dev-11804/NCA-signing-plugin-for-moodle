# NCANode Backend Setup

## Current role

`local_ncasign` now expects **NCANode** for server-side detached CMS verification.

This phase covers:
- manual NCALayer signing on the signer workstation
- server-side CMS verification through NCANode
- evidence storage back into Moodle

It does **not** yet cover:
- final PAdES PDF embedding
- incremental PDF signature byte-range updates
- eGov Mobile callback verification

## Docker run

```bash
docker volume create ncanode_cache
docker run -d \
  --name ncanode \
  --restart unless-stopped \
  -p 14579:14579 \
  -v ncanode_cache:/app/cache \
  malikzh/ncanode
```

## Docker Compose

```yaml
services:
  ncanode:
    image: malikzh/ncanode
    restart: unless-stopped
    ports:
      - "14579:14579"
    volumes:
      - ncanode_cache:/app/cache

volumes:
  ncanode_cache:
```

## Required runtime config

Default image/runtime does not require mandatory environment variables for the basic verification flow.

What you do need:
- exposed HTTP port `14579`
- persistent cache volume at `/app/cache`
- network reachability from Moodle to NCANode

## Health check

Endpoint:

```text
http://127.0.0.1:14579/actuator/health
```

Example:

```bash
curl http://127.0.0.1:14579/actuator/health
```

## Minimal verification test

From the Moodle server:

```bash
php local/ncasign/cli/test_ncanode.php
php local/ncasign/cli/test_ncanode.php --cmsfile=/path/to/signer.cms.b64 --datafile=/path/to/document.pdf --expectediin=123456789012
```

## Moodle settings

Set these values in:

`Site administration -> Plugins -> Local plugins -> NCA Signing`

Recommended values:
- `NCANode base URL`: `http://127.0.0.1:14579`
- `NCANode request timeout (seconds)`: `20`
- `Check OCSP in NCANode`: `Yes`
- `Check CRL in NCANode`: `Yes`

## Notes

- The plugin calls `POST /cms/verify` on NCANode.
- The plugin calls `GET /actuator/health` for health checks.
- Detached verification request body includes:
  - `cms`
  - `data`
  - `revocationCheck`
- The plugin stores:
  - raw CMS
  - signer certificate info
  - signer IIN
  - revocation evidence returned by NCANode
  - timestamp info if returned by NCANode
