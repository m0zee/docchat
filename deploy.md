# Deploying DocChat on Oracle Cloud Always Free

This guide puts DocChat on one free Oracle Cloud VM with Docker Compose: the app, a queue worker, Postgres with pgvector, and Caddy for automatic HTTPS. Everything here is free. It's meant for testing and a portfolio demo, not for a public or commercial service.

What you get: `https://<your-ip>.sslip.io` (or your own domain) serving DocChat, with uploads, the database and certificates on Docker volumes that survive restarts and updates.

## 1. Create the server

1. Sign up at [cloud.oracle.com](https://cloud.oracle.com). **Choose your home region carefully: you can't change it later**, and free Arm capacity varies by region. Oracle asks for a card to verify your identity; Always Free resources aren't charged.
2. Go to **Compute → Instances → Create instance**.
   - Image: **Canonical Ubuntu 24.04** (the aarch64 build).
   - Shape: **Ampere → VM.Standard.A1.Flex**, 2 OCPUs and 12 GB memory (the Always Free maximum).
   - Networking: create a new VCN with a public subnet and **assign a public IPv4 address**.
   - SSH keys: upload your public key (or let Oracle generate one and download it).
3. If you get **"Out of host capacity"**, try another availability domain or try again later. It usually works within a day.
4. Note the instance's **public IP address**.

## 2. Open ports 80 and 443

Oracle blocks traffic in two places, and both need opening.

**a. The VCN security list (Oracle's firewall).** Go to **Networking → Virtual cloud networks → your VCN → Security → Default security list → Add ingress rules** and add:

| Source CIDR | Protocol | Destination port |
|---|---|---|
| `0.0.0.0/0` | TCP | 80 |
| `0.0.0.0/0` | TCP | 443 |
| `0.0.0.0/0` | UDP | 443 (optional, HTTP/3) |

**b. The VM's own firewall.** Oracle's Ubuntu images ship with iptables rules that reject everything except SSH. SSH in and allow the web ports:

```bash
ssh ubuntu@<public-ip>

sudo iptables -I INPUT 5 -m state --state NEW -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 5 -m state --state NEW -p tcp --dport 443 -j ACCEPT
sudo iptables -I INPUT 5 -m state --state NEW -p udp --dport 443 -j ACCEPT
sudo netfilter-persistent save
```

## 3. Install Docker

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl git
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker ubuntu
exit   # log out and back in so the docker group applies
```

Every image DocChat uses (`serversideup/php`, `pgvector/pgvector`, `caddy`, `node`) has an arm64 build, so nothing extra is needed for the Arm CPU.

## 4. Get the code and configure it

```bash
ssh ubuntu@<public-ip>
git clone https://github.com/m0zee/docchat.git
cd docchat
cp .env.production.example .env
nano .env
```

Fill in:

- `DOCCHAT_DOMAIN` and `APP_URL`. With no domain, use the free [sslip.io](https://sslip.io) name for your IP: for `203.0.113.10` that's `203.0.113.10.sslip.io`, and `APP_URL=https://203.0.113.10.sslip.io`. With the GitHub Student Pack you can also get a free domain for a year; point an A record at the IP and use that name instead.
- `DB_PASSWORD`: a long random password, for example from `openssl rand -base64 24`.
- `GEMINI_API_KEY` from [Google AI Studio](https://aistudio.google.com/apikey) and `GROQ_API_KEY` from [Groq](https://console.groq.com/keys). Both are free. On the free Gemini tier, Google may use what you send to improve its products, so **upload test documents only**.

Then generate the app key and paste it into `APP_KEY`:

```bash
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml run --rm --no-deps app php artisan key:generate --show
```

## 5. Start it

```bash
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml ps
```

On start, the app container waits for Postgres, runs the migrations and warms Laravel's caches. Caddy gets a Let's Encrypt certificate on the first request, which can take up to a minute. Then open `https://<your-domain>`.

## 6. First user and the sample document

There's no mail server on the free setup, so verification and password-reset emails go to the log. Register in the browser, then mark your account as verified:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan tinker --execute="App\Models\User::where('email', 'you@example.com')->first()->markEmailAsVerified();"
```

To try DocChat with the bundled sample handbook (user `demo@docchat.test`, password `password`; change it after logging in):

```bash
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=SampleDocumentSeeder --force
```

The worker picks the document up within a few seconds. When it shows **Ready**, run the eval against the real APIs:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan docchat:eval evals/sample.json
docker compose -f docker-compose.prod.yml exec app php artisan docchat:eval evals/sample.json --sweep=0.3,0.4,0.5,0.6
```

### Sending real email (optional, free)

By default emails (workspace invites, verification, password reset) are written to the log, and owners can copy an invite link from the Members page and send it themselves. To actually send email, pick a free SMTP option and set the `MAIL_*` lines in `.env`:

- **Gmail:** turn on 2-step verification, create an [app password](https://myaccount.google.com/apppasswords), then `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_USERNAME` = your address, `MAIL_PASSWORD` = the app password, `MAIL_FROM_ADDRESS` = the same address. Gmail limits how much you can send per day, which is plenty for a demo.
- **A transactional email service's free tier** (for example Brevo or Mailjet; check their current free limits). They give you SMTP host, port, user and password to paste in the same way.

Then `docker compose -f docker-compose.prod.yml up -d` to restart with the new settings. Invite emails go through the queue, so the worker must be running.

## 7. Day-to-day

| Task | Command |
|---|---|
| Update to the latest code | `git pull && docker compose -f docker-compose.prod.yml up -d --build` |
| Logs (all services) | `docker compose -f docker-compose.prod.yml logs -f --tail=100` |
| Per-answer usage log | `docker compose -f docker-compose.prod.yml exec app sh -c 'tail -f storage/logs/docchat-*.log'` |
| Restart the worker | `docker compose -f docker-compose.prod.yml restart worker` |
| Scheduled tasks (cache pruning) | run by the `scheduler` service; check with `docker compose -f docker-compose.prod.yml logs scheduler` |
| Back up the database | `docker compose -f docker-compose.prod.yml exec -T db pg_dump -U docchat docchat \| gzip > docchat-$(date +%F).sql.gz` |
| Restore a backup | `gunzip -c docchat-YYYY-MM-DD.sql.gz \| docker compose -f docker-compose.prod.yml exec -T db psql -U docchat docchat` |
| Stop everything | `docker compose -f docker-compose.prod.yml down` (volumes and data stay) |

Uploaded files live in the `storage` volume. To back them up too: `docker run --rm -v docchat_storage:/s -v "$PWD":/b alpine tar czf /b/storage-$(date +%F).tgz -C /s app`.

## 8. Things to know about the free tier

- **Idle reclaim.** Oracle may reclaim an Always Free VM whose CPU, network and memory all stay under 20% for 7 days. A demo nobody uses can trip this. Upgrading the account to Pay As You Go keeps Always Free resources free and, per Oracle's community reports, exempts them from idle reclaim, but it needs a card on file. Check Oracle's current Always Free page before relying on this.
- **API limits are app-wide.** Groq's free tier allows about 1,000 requests and 8,000 tokens per minute for the whole app, which is roughly one question per minute at full context. DocChat shows "The model is busy, try again in a minute" when Groq says no.
- **Backups are your job.** Nothing on the free VM is backed up automatically. Use the `pg_dump` command above.

## Troubleshooting

- **The browser can't connect.** Check both firewalls in step 2. `curl -I http://localhost` on the server should answer; if it does but the outside can't reach it, it's the security list or iptables.
- **Caddy shows a certificate error.** `DOCCHAT_DOMAIN` must resolve to this server's public IP and ports 80 and 443 must be open, because Let's Encrypt checks over port 80. See `docker compose -f docker-compose.prod.yml logs caddy`.
- **Documents stay Pending.** Check the worker: `docker compose -f docker-compose.prod.yml logs worker`. A missing `GEMINI_API_KEY` shows up as a Failed document with "Set GEMINI_API_KEY in .env."
- **Links point to http:// instead of https://.** `APP_URL` must start with `https://`.
