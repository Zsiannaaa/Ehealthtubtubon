# Tubtubon eHealth on Oracle Cloud Always Free

**Status:** primary path for the 10 November 2026 partial defense.
**Fallback:** AWS EC2 — `deploy/README.md`, unchanged and still valid.

Read this as a **delta only**. Steps 5–10 of the AWS runbook (bootstrap, `.env`,
SQL import, preflight, TLS, smoke test) are **identical**. Only the items below differ.

---

## 0. Why Oracle for this demo

| | AWS EC2 (fallback) | Oracle Always Free (primary) |
|---|---|---|
| Cost | ~$24/mo drawn from expiring credits | **$0** |
| Shape | `t3.small` — 2 vCPU, **2 GB**, x86 | `VM.Standard.A1.Flex` — 2 OCPU, **12 GB**, **arm64** |
| Egress | ~100 GB free | **10 TB/mo** |
| Public IP | Elastic IP | Reserved public IP |
| Firewall | Security Group only | **Security List + OS-level iptables (two layers)** |
| SSH key | `.pem` downloaded at launch | key pair generated in the console |
| `bootstrap.sh` | as written | **works unchanged** |

The AWS credits expire 23 Apr 2027 and cannot be spent on anything else, so the
"$24 saved" is not a real saving. The real wins are the 12 GB of RAM (Tesseract OCR
stops being a memory gamble) and the zero-cost story: a barangay health center with
no IT budget can keep this running indefinitely.

---

## 1. Create the instance

Console → **Compute → Instances → Create instance**.

| Field | Value |
|---|---|
| Name | `tubtubon-ehealth` |
| Compartment | your root compartment |
| Placement | any Availability Domain — see §6 if you get a capacity error |
| Image | **Canonical Ubuntu 24.04** — make sure the architecture reads **aarch64** |
| Shape | **Change shape → Ampere → `VM.Standard.A1.Flex` → 2 OCPU / 12 GB** |
| Primary VNIC | public subnet, **Assign a public IPv4 address: Yes** |
| SSH keys | **Generate a key pair for me** → download the private key immediately |
| Boot volume | leave the default **50 GB** (minimum is 47 GB; 200 GB total across the tenancy) |

Do **not** pick an AMD `VM.Standard.E2.1.Micro` "just to start". Those are 1 GB and
MySQL + Apache + Tesseract will not fit together.

### SSH from your laptop (Windows: Git Bash, not cmd)

```bash
# move the downloaded key somewhere sane first, e.g. ~/.ssh/
chmod 600 ~/.ssh/ssh-key-2026-11-01.key
ssh -i ~/.ssh/ssh-key-2026-11-01.key ubuntu@<PUBLIC_IP>
```

The default user on Oracle's Ubuntu images is **`ubuntu`** (same as AWS).

---

## 2. Open ports — TWO layers, both required

This is the step that makes people think "my server is broken". Opening the Security
List alone leaves you with a connection timeout, because Oracle's Ubuntu images also
ship with iptables rules that drop 80/443 at the OS level.

### Layer 1 — VCN Security List (console)

Networking → **Virtual Cloud Networks** → your VCN → **Security Lists** →
**Default Security List** → **Add Ingress Rules**, twice:

| Stateless | Source Type | Source CIDR | IP Protocol | Destination Port Range |
|---|---|---|---|---|
| No | CIDR | `0.0.0.0/0` | TCP | `80` |
| No | CIDR | `0.0.0.0/0` | TCP | `443` |

Keep the existing SSH rule (port 22). Do **not** add 3306.

### Layer 2 — OS firewall (on the server)

After the code is on the box, run:

```bash
sudo bash /var/www/tubtubon_ehealth/deploy/oracle-ports.sh
```

It is idempotent, inserts the ACCEPT rules ahead of Oracle's REJECT rule, and persists
them so a reboot does not close the ports again.

Verify from your laptop — this must return `200`, `302` or `403`, **not** a timeout:

```bash
curl -I http://<PUBLIC_IP>/
```

---

## 3. Reserve the public IP

Instance details → **Attached VNICs** → the VNIC → **IPv4 Addresses** → the row with
your public IP → **Edit** → change **Ephemeral public IP** to **Reserved public IP**.

Without this, a stop/start hands you a different address and breaks both your demo
link and your TLS certificate — the same failure mode the Elastic IP prevents on AWS.

---

## 4. Everything else is the AWS runbook

From here, follow `deploy/README.md` **§5 onward verbatim**, with one substitution:

```bash
sudo APP_DIR=/var/www/tubtubon_ehealth \
     DB_NAME=tubtubon_ehealth \
     DB_USER=tubtubon \
     DB_PASS='pick-a-strong-one' \
     SERVER_NAME=<PUBLIC_IP>.nip.io \
     bash deploy/bootstrap.sh
```

Then `.env`, the three migrations in order, `preflight.php`, and `certbot --apache -d
<PUBLIC_IP>.nip.io`. Nothing in those steps is AWS-specific.

One Oracle-specific tweak worth making in the server `.env`:

```
DB_HOST=localhost
```

instead of `127.0.0.1`. `bootstrap.sh` grants the app user as `'tubtubon'@'localhost'`;
connecting over the Unix socket always matches that grant, whereas TCP to `127.0.0.1`
only matches if MySQL reverse-resolves the address to `localhost`.

---

## 5. arm64 is a non-issue here — verified

Every package the stack needs has an arm64 build in Ubuntu 24.04: `php8.3`,
`libapache2-mod-php8.3`, `php8.3-mysql`, `php8.3-mbstring`, `php8.3-zip`,
`php8.3-curl`, `php8.3-xml`, `php8.3-gd`, `mysql-server`, `tesseract-ocr`,
`tesseract-ocr-eng`. The application is pure PHP with no compiled extensions, and
`resolveTesseractBinary()` finds `/usr/bin/tesseract` before it ever reaches the
Windows fallback path. No code changes required.

---

## 6. "Out of host capacity"

Expected, not a bug. Free A1 capacity is genuinely scarce in popular regions.
Singapore (`ap-singapore-1`) has a **single availability domain**, so the usual
"try another AD" advice does not apply there.

In order:

1. **Shrink the request to 1 OCPU / 6 GB and retry.** A smaller shape is dramatically
   more likely to find a free slot, and 6 GB is still 3× the RAM of `t3.small`. You can
   scale up to 2 OCPU / 12 GB later by stopping the instance and editing its shape.
2. Retry the same request every 60–90 seconds for ~10 minutes. Do not hammer the API —
   back off between attempts.
3. Retry at a different hour. Capacity in Singapore frees up unpredictably; early
   morning PHT tends to be better than evening.
4. **Escape hatch:** upgrading the tenancy to **Pay As You Go** usually clears A1
   capacity immediately, and Always Free resources *stay free* after the upgrade. If
   you take this route, set a budget alert on day one and stay strictly inside the
   Always Free limits.

**Time-box: 60 minutes.** If you do not have a running instance by then, switch to
`deploy/README.md` (AWS) and move on. Do not lose a day to refreshing a console.

---

## 7. The one real risk: idle reclamation

Oracle may reclaim an Always Free instance that is **idle for 7 consecutive days**,
where idle means all three of:

- CPU utilization (95th percentile) **< 20 %**
- Network utilization **< 20 %**
- Memory utilization **< 20 %** (A1 shapes only)

Your stack idles at roughly 1–2 % CPU, negligible network, and ~600 MB of 12 GB —
so on paper a demo instance qualifies as idle. Enforcement is inconsistent in
practice, but the timeline makes this worth respecting: an instance created on
**1 November** becomes eligible on **8 November**, two days before the defense.

Mitigations, in order of usefulness:

1. **Keep AWS warm as the fallback** — already the plan. This is the real insurance.
2. **Build late.** Creating the instance on 3–4 November puts the 7-day mark after
   the defense.
3. **Keep-warm cron** (optional, belt and braces) — a light request every 10 minutes
   from inside the box:

   ```bash
   ( sudo crontab -l 2>/dev/null; \
     echo "*/10 * * * * curl -s -o /dev/null http://localhost/ && mysql -e 'SELECT 1' tubtubon_ehealth" \
   ) | sudo crontab -
   ```

   This satisfies the network and memory signals but will not push 95th-percentile
   CPU above 20 % on a 2-OCPU box. It reduces, not eliminates, the risk.

---

## 8. Schedule to 10 November

| Date | Action |
|---|---|
| Oct 31 – Nov 1 | Create instance (§1), open both firewalls (§2), reserve IP (§3) |
| Nov 2 – 3 | `bootstrap.sh`, `.env`, migrations, preflight green, TLS issued |
| Nov 4 – 7 | Full 15-item smoke test, both roles, OCR with a real PNG and a PDF |
| **Nov 8 (Sun)** | **Go / no-go.** Oracle unstable or capacity lost → flip to AWS, ~20 min |
| Nov 9 (Mon) | Freeze. EC2-style snapshot equivalent: `mysqldump` to `storage/backups/`, rehearse the click-through once |
| Nov 10 (Tue) | Defense |

---

## 9. Pre-defense checklist (Oracle-specific additions)

Add these to the AWS runbook's §10 smoke test:

- [ ] `curl -I http://<IP>/` returns a response from your laptop (both firewall layers open)
- [ ] `https://<IP>.nip.io` shows a valid certificate, not a warning
- [ ] Public IP still matches the one in your notes after a reboot test
- [ ] `sudo iptables -L INPUT -n` shows ACCEPT for 80 and 443 **above** the REJECT
- [ ] `sudo reboot`, wait 60 s, site comes back with no manual intervention
      (proves apache2/mysql are enabled, per `bootstrap.sh` step 2/9)
- [ ] `https://<IP>.nip.io/.env` returns **403**
- [ ] `https://<IP>.nip.io/cli/backup_job.php` returns **403**
