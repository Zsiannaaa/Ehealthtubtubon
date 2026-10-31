# HANDOFF — Tubtubon eHealth → AWS EC2 deployment

**Read this file first.** It exists so a fresh chat can pick up without re-reading the
whole codebase or re-litigating decisions that are already settled.

- **Written:** 31 October 2026, ~19:45 PHT
- **Target:** partial defense on **Tuesday, 10 November 2026**
- **Scope of this chat:** AWS deployment only. Oracle is parked — see §9.

---

## 1. What the project is

Tubtubon eHealth is a capstone web system for **Barangay Tubtubon Health Center, Sibulan,
Negros Oriental** (St. Paul University Dumaguete). It is a **PHP 8.3 modular monolith** on
MySQL, served by Apache — no framework, no Composer, no build step.

| | |
|---|---|
| Local path | `C:/laragon/www/tubtubon_ehealth` |
| Repo | `https://github.com/Zsiannaaa/Ehealthtubtubon.git` — **public**, branch `main` |
| Entry point | `index.php` at project root → redirects to `modules/auth/login.php` |
| Database | 13 tables, 3NF, `utf8mb4_unicode_ci` |
| Roles | 5 (Administrator, Nurse/Midwife, BHW, Inventory, Resident) |
| Screens | 12 (S1–S12) + 8 printable DOH templates |
| OCR | Self-hosted Tesseract 5 via `shell_exec`, human-in-the-loop verification |
| SMS | Outbound-only, `Semaphore Mock` provider (simulated by design) |

**Document root is the project root, not `public/`.** Pages live at `/public/*.php`,
API endpoints at `/public/api/*.php`, auth at `/modules/auth/*.php`.

---

## 2. Verified facts — do not re-derive these

These were confirmed by reading the code and running checks on 31 Oct 2026.

| Fact | Evidence |
|---|---|
| `deploy/` is committed and pushed (`e028257`) | `git ls-remote origin refs/heads/main` |
| Repo is public — clone needs no PAT | `git ls-remote` resolves unauthenticated |
| Seed password is **`Tubtubon2026!`** | `password_verify()` returns true; login page auto-fills it |
| `001_schema.sql` already creates `users.patient_id` + FK | so `003` is a **no-op** (guarded by `@col_exists`) — still run it, it's harmless |
| All 13 tables match `preflight.php`'s expected list | compared schema to checker |
| Backup uses **PDO**, not `mysqldump` | `exportDatabaseSqlDump()` — no binary dependency on Linux |
| No Windows-only paths in the request path | `resolveTesseractBinary()` checks `/usr/bin/tesseract` before the Windows fallback |
| `js/app.js` + `mock-data.js` are **not** loaded by any PHP page | prototype-only; the real app has no local-JS dependency |
| `.env` and `reference files/` are gitignored | they will not arrive via clone — this is correct |
| Upload guard is real | 5 MB cap, `finfo` MIME whitelist, hashed names, `realpath()` containment |

---

## 3. Decisions already locked — do not reopen

1. **Region:** `ap-southeast-1` (Singapore). ~40 ms from Negros Oriental vs ~220 ms from us-east-1.
2. **Instance:** `t3.small` (2 vCPU / 2 GB). `t3.micro` will swap or OOM during OCR.
3. **AMI:** Ubuntu Server **24.04 LTS** x86_64. `bootstrap.sh` installs `php8.3`, which only
   exists in 24.04's repos.
4. **MySQL 8.0** — accept it. The paper says "8.4 LTS", but Ubuntu 24.04 ships 8.0.x and the
   dump is 8.0-compatible. **Do not add the MySQL APT repo** to force 8.4; it risks replacing
   `mysql-server` and breaking auth plugins hours before a defense. If asked, the honest answer
   is: *"the schema targets MySQL 8.x; we deployed on the 8.0 LTS that ships with Ubuntu 24.04."*
5. **TLS:** `<IP>.nip.io` + Certbot. Free, real certificate, green padlock. Bare-IP HTTP would
   put passwords in cleartext on a health-records demo.
6. **Demo password:** keep `Tubtubon2026!`.
7. **Elastic IP:** required. A stop/start otherwise changes the IP and breaks the link *and* the cert.
8. **Deploy source:** `git clone` (now works, `deploy/` is committed).

---

## 4. Corrections to `deploy/README.md`

The runbook is good but has three defects. Apply these, don't follow the README blindly.

| README says | Reality | Action |
|---|---|---|
| §7: "if you don't know the plaintext, reset it to `Demo@1234`" | The hash **is** `Tubtubon2026!`, and the login page auto-fills it | **Skip the reset.** Running it breaks every demo button on the sign-in screen |
| §2: "Elastic IP is free while attached" | AWS bills **$0.005/hr for every public IPv4** since Feb 2024 | Expect ~$3.65/mo |
| Cost: "~$9/mo worst case" | 24/7 `t3.small` + EIP + 20 GB gp3 ≈ **$23/mo** | Budget accordingly |
| `.env` template: `DB_HOST=127.0.0.1` | `bootstrap.sh` grants `'tubtubon'@'localhost'`; TCP to 127.0.0.1 only matches if MySQL reverse-resolves | **Set `DB_HOST=localhost`** in the server `.env` |

---

## 5. The deployment path — 7 checkpoints

Guide **one checkpoint at a time**. The user sends screenshots; confirm before proceeding.

### Checkpoint 1 — Launch the instance

AWS Console → EC2 → **Launch instance**:

| Field | Value |
|---|---|
| Name | `tubtubon-ehealth` |
| AMI | Ubuntu Server 24.04 LTS (64-bit x86) |
| Instance type | `t3.small` |
| Key pair | create new, download the `.pem` — **cannot be re-downloaded** |
| Network | default VPC, **public subnet**, auto-assign public IP **enabled** |
| Storage | 20 GiB gp3 |
| Security group | new, per Checkpoint 2 |

Do **not** enable RDS, ALB, NAT Gateway, CloudFront, or Elastic Beanstalk.

### Checkpoint 2 — Security group

Inbound only, three rules:

| Port | Source | Why |
|---|---|---|
| 22 | **My IP** | never `0.0.0.0/0` |
| 80 | `0.0.0.0/0` | web + Certbot HTTP-01 |
| 443 | `0.0.0.0/0` | TLS |

Never open 3306. MySQL listens on localhost only.

### Checkpoint 3 — Elastic IP

EC2 → Elastic IPs → Allocate → Actions → Associate → pick the instance.
**Write the IP down.** Everything downstream refers to it as `<IP>`.

### Checkpoint 4 — SSH in

From **Git Bash** (not cmd):

```bash
chmod 400 ~/Downloads/tubtubon-ehealth.pem
ssh -i ~/Downloads/tubtubon-ehealth.pem ubuntu@<IP>
```

### Checkpoint 5 — Code + bootstrap

```bash
sudo apt-get update -qq && sudo apt-get install -y git
sudo git clone https://github.com/Zsiannaaa/Ehealthtubtubon.git /var/www/tubtubon_ehealth
sudo chown -R ubuntu:ubuntu /var/www/tubtubon_ehealth
cd /var/www/tubtubon_ehealth
```

Then bootstrap. **`DB_PASS` must be alphanumeric only** — `bootstrap.sh` interpolates it into a
SQL heredoc and a `sed` expression, so a `'` or `/` breaks it:

```bash
sudo APP_DIR=/var/www/tubtubon_ehealth \
     DB_NAME=tubtubon_ehealth \
     DB_USER=tubtubon \
     DB_PASS='TubtubonDb2026' \
     SERVER_NAME=<IP>.nip.io \
     bash deploy/bootstrap.sh
```

What this fixes that would otherwise break the deploy:

1. **`DirectoryIndex index.php index.html`** — Ubuntu defaults to `index.html` first, which
   would serve the 91 KB static prototype at `/` and never run the PHP router.
2. **`.env` deny + `AllowOverride All`** — there is no root `.htaccess`, so on a stock Ubuntu
   box `.env` (DB password, plaintext) would be **downloadable over HTTP**, and
   `storage/.htaccess` would be ignored.
3. Enables apache2 + mysql so the box survives stop→start.

### Checkpoint 6 — `.env`, schema, preflight

```bash
cd /var/www/tubtubon_ehealth
sudo -u www-data cp deploy/env.production.example .env
sudo sed -i "s/CHANGE_ME_STRONG_PASSWORD/TubtubonDb2026/" .env
sudo sed -i "s|^DB_HOST=.*|DB_HOST=localhost|" .env
sudo chown www-data:www-data .env && sudo chmod 640 .env
```

Keep `TESSERACT_PATH=/usr/bin/tesseract` and `APP_BASE_URL=""` (blank = auto-detect, so the
same build works on IP → nip.io → future domain with no edits).

Import in this order — order matters:

```bash
sudo mysql tubtubon_ehealth < migrations/001_schema.sql
sudo mysql tubtubon_ehealth < migrations/002_seed_synthetic.sql
sudo mysql tubtubon_ehealth < migrations/003_users_patient_fk.sql
```

**Do not run the README's `Demo@1234` reset.** The seeded hash already is `Tubtubon2026!`.

```bash
sudo -u www-data php deploy/preflight.php
```

Exit 0 = deployable. It checks PHP version, 6 extensions, `shell_exec`, upload limits, `.env`
perms, `storage/` writability, DB connection, all 13 tables, the `users.patient_id` column, and
Tesseract 5.x with `eng`.

### Checkpoint 7 — TLS

Only after `<IP>.nip.io` resolves (it will, automatically):

```bash
sudo certbot --apache -d <IP>.nip.io
# choose: redirect HTTP -> HTTPS
```

Browse `https://<IP>.nip.io`.

---

## 6. Smoke test — walk it in this order

Each item exercises a different layer. Do them in sequence.

- [ ] `/` redirects to sign-in (if you see the green prototype, `DirectoryIndex` is wrong)
- [ ] `admin` / `Tubtubon2026!` signs in; dashboard counters are non-zero
- [ ] Dashboard shows low-stock alerts
- [ ] `nurse.reyes` can create a service record; **admin cannot** (RBAC — admin is redirected)
- [ ] Register a patient → appears in list and in resident portal binding
- [ ] OCR: upload **PNG/JPG** → real extracted fields with confidence scores
- [ ] OCR: upload **PDF** → honest fallback message (Tesseract can't read PDF directly — this is
      documented behaviour, not a bug; say so before a panelist finds it)
- [ ] `resident.juana` sees only their own record
- [ ] `bhw.inactive` is refused at login
- [ ] Templates page renders the 8 printable DOH forms
- [ ] Reports page renders
- [ ] `https://<IP>.nip.io/.env` returns **403** ← verify explicitly
- [ ] `https://<IP>.nip.io/cli/backup_job.php` returns **403**
- [ ] Logout works and back-button doesn't restore the session

---

## 7. Known blemishes — decide whether to show them

- **Seed ledger mismatch:** batch `PAR-2024-EXP` (30 units) has no matching Stock-In row, so
  Paracetamol reads 350 in the ledger vs 380 physical. Don't invite a live reconciliation.
- **SMS:** only Semaphore has a live HTTP path; iTexMo/MOVider fall through to the simulator and
  fabricate "Delivered". Keep `SMS_PROVIDER=Semaphore Mock` — the app is then honest.
- **`/index.html` is still publicly reachable** — the static prototype with mock names.
  Synthetic data, so no PHI risk, but a wandering panelist can land there.
- **CDN dependency:** Bootstrap, lucide, Chart.js and Inter all load from jsdelivr / unpkg /
  Google Fonts. On flaky venue Wi-Fi the UI degrades. Optional: vendor the three files locally.
- **`public/ocr.php` requires Nurse/BHW** — admin cannot reach the OCR screen. Log in as
  `nurse.reyes` for that part of the demo.

---

## 8. Cost and timeline

| Item | Cost |
|---|---|
| t3.small, 730 h | ≈ $18/mo |
| Public IPv4 / EIP | $3.65/mo |
| 20 GB gp3 | ≈ $1.60/mo |
| **Total** | **≈ $23/mo** |

AWS account is on the **Free account plan**: $139.95 credits remaining, **175 days left**
(expires ~23 Apr 2027). Note there are **no free EC2 hours** on this plan — usage draws down
credits. ~$23/mo leaves ample headroom for a one-month demo.

**After the defense:** EC2 → Instance state → **Terminate**, then release the Elastic IP.
Otherwise EBS + EIP bill quietly.

Timeline to 10 Nov: launch + bootstrap by 2 Nov; TLS and full smoke test by 4 Nov; freeze 9 Nov.

---

## 9. Oracle Cloud — parked, not abandoned

An Oracle Always Free attempt was made on 31 Oct and blocked:

- Tenancy region: **Singapore (Singapore)**, single AD (`AP-SINGAPORE-1-AD-1`)
- VCN `tubtubon-vcn` created **with** public subnet `public subnet-tubtubon-vcn`
- Image/shape chosen: `Canonical Ubuntu 24.04 Minimal aarch64` / `VM.Standard.A1.Flex`
- **Blocker:** `Out of capacity for shape VM.Standard.A1.Flex` — at both 2 OCPU/12 GB and 1 OCPU/6 GB
- SSH key pair was generated in-console (RSA); private key downloaded by the user

**Do not spend time on Oracle in this chat.** If the user raises it, point them to
`deploy/oracle.md` and the retry ladder in §6 of that file. The user intends to retry on
1 November, and to upgrade the tenancy to Pay As You Go if capacity still fails.

Relevant correction already recorded there: Oracle's Always Free A1 allowance is
**2 OCPU / 12 GB total** (1,500 OCPU-hours + 9,000 GB-hours/month), *not* the widely-quoted
4 OCPU / 24 GB. Minimum boot volume is 47 GB.

---

## 10. How to work with this user

- They are **learning the cloud consoles as they go**. Give exact click paths and field values
  *before* they click — never "configure the security group appropriately."
- They send **screenshots** and expect verification. Confirm each checkpoint before moving on.
- **One checkpoint per turn.** Dumping all seven steps at once loses them.
- Flag anything **irreversible** loudly and immediately — the `.pem` download, the private key,
  the demo password reset.
- They are on **Windows + Git Bash**; `chmod 400` works there, `cmd` does not.
- If a console path differs from this doc, trust the screenshot and adapt — AWS reshuffles its UI.

---

## 11. Files worth reading

| File | Why |
|---|---|
| `deploy/README.md` | The full AWS runbook (12 steps). Corrected by §4 above |
| `deploy/bootstrap.sh` | Know what it installs before running it |
| `deploy/preflight.php` | The pass/fail list you're aiming for |
| `deploy/oracle.md` | Only if Oracle comes up |
| `migrations/001_schema.sql` | 13-table schema, if a data question arises |

Start at **Checkpoint 1**.
