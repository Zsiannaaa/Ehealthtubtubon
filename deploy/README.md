# Deploying Tubtubon eHealth to AWS EC2 (first pass: IP only, no domain)

**Goal of this pass:** get the real app running on a public URL today, so you can click through
all 12 screens and 5 roles from a phone/laptop that isn't your dev machine. Domain gets bolted on
later with **zero code changes**.

**Cost:** ~$0.40 for a full 8-hour day of testing with the box left running all night
(`t3.small` ap-southeast-1). Your $139.95 covers this pattern for many months.

> **This is now the FALLBACK path.** The primary deployment for the 10 Nov 2026 partial
> defense is Oracle Cloud Always Free — see `deploy/oracle.md`. This runbook stays valid
> and unchanged as the insurance policy.
>
> Two corrections to the figures below: a public IPv4 / Elastic IP is **not** free while
> attached — AWS bills $0.005/hr (~$3.65/mo) for every public IPv4 since Feb 2024. And a
> 24/7 `t3.small` plus EIP plus 20 GB gp3 lands closer to **~$23/mo**, not ~$9.

---

## 0. Why no domain is fine (and why you should still use a hostname)

| | Bare IP | `IP.nip.io` (recommended) |
|---|---|---|
| Works for testing | yes | yes |
| HTTPS / TLS | **no** — Let's Encrypt won't issue for a raw IP | **yes**, free real cert |
| Passwords travel in cleartext | **yes** | no |
| Panel perception | "not secure" warning in Chrome | green padlock |
| Effort | 0 min | 10 min |

This is a **health records system** whose manuscript carries security NFRs. Run the demo over
plaintext HTTP and the most likely panel question is "where's your HTTPS?". `nip.io` is a free
wildcard-DNS service: `13.212.44.10.nip.io` resolves to `13.212.44.10` automatically. Certbot
will happily issue a cert for it. When the Hostinger domain lands you re-point and re-issue.

Nothing in the code changes either way — `config/env.php` auto-detects the base URL and
`includes/auth.php` auto-detects HTTPS for the `secure` cookie flag.

---

## 1. Launch the instance

AWS Console → EC2 → **Launch instance**.

| Field | Value | Why |
|---|---|---|
| Name | `tubtubon-ehealth` | |
| AMI | **Ubuntu Server 24.04 LTS** (64-bit x86) | PHP 8.3 matches your Laragon 8.3.33 |
| Instance type | **t3.small** (2 vCPU / 2 GB) | MySQL + Apache + Tesseract on 1 GB will swap or OOM mid-OCR |
| Key pair | create new, download the `.pem` | you need this to SSH; **you cannot re-download it** |
| Network | default VPC, **public subnet** | never put it behind a NAT Gateway ($32/mo) |
| Auto-assign public IP | **Enable** | |
| Storage | 20 GiB gp3 | ~$1.60/mo, plenty |
| Security group | new, see §3 | |

**Region: `ap-southeast-1` (Singapore).** ~40 ms from Negros Oriental vs ~220 ms from
us-east-1. For live clicking in front of a panel, this matters more than the ~$1/mo difference.

Do **not** enable: RDS, ALB, NAT Gateway, CloudFront, Elastic Beanstalk. One box runs everything.

---

## 2. Elastic IP — do this BEFORE any testing

**Why:** a stopped instance releases its auto-assigned public IP. Stop/start and your IP changes,
so your test link, your nip.io hostname and your TLS cert all break. An Elastic IP is a fixed
address that survives stop/start.

EC2 → **Elastic IPs** → *Allocate Elastic IP address* → *Allocate* → select it →
**Actions → Associate** → pick your instance → Associate.

Cost: free while attached to a running instance; $0.005/hr while the instance is stopped (~$3.65/mo).
Worth it — it's the difference between "my link died" and "nothing to do".

**Write the IP down.** Everything below refers to it as `<IP>`.

---

## 3. Security group

Inbound rules — four, no more:

| Port | Source | Purpose |
|---|---|---|
| 22 (SSH) | **My IP** only | never `0.0.0.0/0`. If your ISP hands you a dynamic IP, update this when SSH stops working |
| 80 (HTTP) | `0.0.0.0/0` | web + Certbot HTTP-01 challenge |
| 443 (HTTPS) | `0.0.0.0/0` | TLS |

Never open **3306**. The database listens on `127.0.0.1` only.

---

## 4. SSH in and get the code

```bash
# from your laptop — Windows: use Git Bash, not cmd
chmod 400 ~/Downloads/tubtubon-ehealth.pem
ssh -i ~/Downloads/tubtubon-ehealth.pem ubuntu@<IP>
```

On the server:

```bash
sudo apt-get update -qq && sudo apt-get install -y git
sudo git clone https://github.com/Zsiannaaa/Ehealthtubtubon.git /var/www/tubtubon_ehealth
sudo chown -R ubuntu:ubuntu /var/www/tubtubon_ehealth
cd /var/www/tubtubon_ehealth
```

Private repo? Use a GitHub PAT in the clone URL, or `scp` the folder up. Either way, `.env` and
`reference files/` are gitignored — they will **not** arrive via clone, which is correct.

> Deploying **today**, before the current work is finished? Push first, then clone —
> otherwise you're testing an older commit than your local tree.

---

## 5. Bootstrap the server

```bash
sudo APP_DIR=/var/www/tubtubon_ehealth \
     DB_NAME=tubtubon_ehealth \
     DB_USER=tubtubon \
     DB_PASS='pick-a-strong-one' \
     SERVER_NAME=<IP>.nip.io \
     bash deploy/bootstrap.sh
```

This installs Apache + PHP 8.3 + MySQL + Tesseract, enables both services to auto-start
(**so the site survives stop → start**), writes the vhost, and sets `storage/` ownership.

`SERVER_NAME` later becomes your real domain — re-run just the vhost step or edit
`/etc/apache2/sites-available/tubtubon.conf`.

### The two things the vhost fixes that would otherwise break you

1. **`DirectoryIndex index.php index.html`** — Ubuntu's default lists `index.html` first, so
   `/` would serve your 91 KB static prototype and the PHP router would never run.
2. **`.env` deny + `AllowOverride All`** — there's no root `.htaccess` in the repo, so on a stock
   Ubuntu box your **`.env` would be downloadable** (DB password, in plaintext, over HTTP) and the
   `storage/.htaccess` that protects patient uploads would be ignored.

---

## 6. Create the real `.env`

```bash
cd /var/www/tubtubon_ehealth
sudo -u www-data cp deploy/env.production.example .env
sudo sed -i "s/CHANGE_ME_STRONG_PASSWORD/pick-a-strong-one/" .env
sudo chown www-data:www-data .env && sudo chmod 640 .env
```

`TESSERACT_PATH=/usr/bin/tesseract` — keep it. `APP_BASE_URL` stays empty so the same build works
on the IP, on nip.io, and on the final domain.

---

## 7. Import the schema — order matters

```bash
sudo mysql tubtubon_ehealth < migrations/001_schema.sql
sudo mysql tubtubon_ehealth < migrations/002_seed_synthetic.sql
sudo mysql tubtubon_ehealth < migrations/003_users_patient_fk.sql
```

Verified compatible with MySQL 8.0 (Ubuntu 24.04's stock version): the dump uses
`utf8mb4_unicode_ci` and no 8.4-only syntax.

Seeded demo logins (all share one bcrypt hash):

| Username | Role |
|---|---|
| `admin` | Administrator |
| `nurse.reyes` | Nurse/Midwife |
| `bhw.lim`, `bhw.cruz` | Barangay Health Worker |
| `inventory.cruz` | Inventory/Admin |
| `resident.juana` | Resident (bound to patient #1) |
| `bhw.inactive` | Deactivated — good for the RBAC denial demo |

If you don't know the plaintext for that hash, reset it:
```bash
sudo mysql tubtubon_ehealth -e "UPDATE users SET password_hash='$(php -r 'echo password_hash("Demo@1234", PASSWORD_BCRYPT);')';"
```

---

## 8. Preflight

```bash
sudo -u www-data php deploy/preflight.php
```

Green across the board before you open a browser. It checks PHP version, 6 extensions,
`shell_exec`, upload limits, `.env` perms, `storage/` writability, DB connection, all 13 tables,
the migrated `users.patient_id` FK, and that Tesseract is 5.x with `eng` installed.

---

## 9. TLS (10 minutes, skip only if truly out of time)

```bash
sudo certbot --apache -d <IP>.nip.io
# choose: redirect HTTP -> HTTPS
```

Then browse `https://<IP>.nip.io`. Cert is valid 90 days — a 1-week defense never hits renewal,
but run `sudo certbot renew` whenever the box is up for a while.

**Later, with the Hostinger domain:** add an `A` record → `<IP>` (and `www`), wait for propagation,
then `sudo certbot --apache -d yourdomain.com -d www.yourdomain.com`. No code change, no redeploy.

---

## 10. Smoke test checklist

Open `https://<IP>.nip.io` and walk these **in this order** — each one exercises a different
part of the stack:

- [ ] `/` redirects to the sign-in screen (if you see the old green prototype, `DirectoryIndex` is wrong)
- [ ] `admin` can sign in, and the dashboard counters are non-zero
- [ ] Dashboard low-stock alerts appear (needs `getInventoryAlerts()` + seeded FEFO data)
- [ ] Nurse can create a service record; **Admin cannot** (the RBAC hole you closed)
- [ ] Register a patient → appears in the list and in the resident portal binding
- [ ] Upload a **PNG/JPG** in OCR → real extracted fields with confidence scores
- [ ] Upload a **PDF** in OCR → falls back with an honest message (Tesseract can't read PDF directly)
- [ ] `resident.juana` sees only their own records, no other patient
- [ ] `bhw.inactive` is refused at login
- [ ] Templates page renders the 8 printable DOH forms
- [ ] Reports page renders
- [ ] `https://<IP>.nip.io/.env` returns **403** ← verify this explicitly
- [ ] `https://<IP>.nip.io/cli/backup_job.php` returns **403**
- [ ] Logout works and the session is gone on back-button

---

## 11. Snapshot before you demo, then decide about stopping

**Night before the defense:**
```bash
sudo mysqldump -u root tubtubon_ehealth > /var/www/tubtubon_ehealth/storage/backups/predemo_$(date +%F).sql
```
Also EC2 → Actions → Image → **Create image**. Costs cents, restores in minutes.

**On "turn it off when not in use":** now that cost is ~$9/mo worst case, the honest recommendation
flips — **just leave it running.** The risk you're avoiding (a dead link when your adviser asks
to see it, or a 20-minute panic before the defense) is worth far more than $4. If you do stop it:
**Stop, never Terminate**, and remember the box comes back in ~30 s with data intact.

**After the defense:** EC2 → Instance state → **Terminate** (deletes the EBS volume), then release
the Elastic IP. Otherwise EBS + EIP keep billing quietly for months.

---

## 12. Known-unfinished items — decide whether to show them

Not blockers, but a curious panelist may poke these:

- **Seed ledger blemish:** batch `PAR-2024-EXP` (30 units) has no matching Stock-In row, so
  Paracetamol reads 350 in the ledger vs 380 physical. If someone adds up the stock card live,
  it won't reconcile. Either fix the seed row or don't invite that comparison.
- **SMS:** only Semaphore has a live HTTP path; iTexMo/MOVider silently fall through to the
  simulator and **fabricate "Delivered"**. Keep `SMS_PROVIDER=Semaphore Mock` so the app is honest.
- **OCR on PDFs** is a documented fallback, not a bug — say so before they find it.
- **Manuscript numbering:** Phase 6 is headed "CHAPTER VI" (should be IV), and Phases 7 & 8 both
  claim deliverables 49 and 55. Not deployment-related, but worth fixing before printing.

---

## Files in this kit

| File | Purpose |
|---|---|
| `deploy/README.md` | this runbook |
| `deploy/bootstrap.sh` | idempotent LAMP + Tesseract setup (Ubuntu 24.04) |
| `deploy/apache-vhost.conf` | vhost with the `DirectoryIndex`, `.env` deny and `AllowOverride` fixes |
| `deploy/env.production.example` | server `.env` template, placeholders only |
| `deploy/preflight.php` | CLI environment checker, exit 0 = deployable |

Additive only — no existing application file was modified. If you'd rather keep these out of the
repo, add `deploy/` to `.gitignore`; committing them is also defensible (deployment evidence for
your manuscript appendix).
