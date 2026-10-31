#!/usr/bin/env bash
# ============================================================================
# deploy/oracle-ports.sh — Open 80/443 in the OS-level firewall on Oracle Cloud
#
# Oracle's Ubuntu images ship with iptables rules that DROP everything except
# SSH, independently of the VCN Security List. Opening the Security List in the
# console is therefore not enough: without this step, http://<IP>/ times out.
#
#   sudo bash deploy/oracle-ports.sh
#
# Idempotent: safe to re-run. Only touches INPUT rules for tcp/80 and tcp/443,
# and persists them so the ports survive a reboot.
#
# This script is Oracle-specific. On AWS it is unnecessary (the Security Group
# is the only layer) but harmless if run by mistake.
# ============================================================================
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Run with sudo:  sudo bash $0" >&2
    exit 1
fi

if ! command -v iptables >/dev/null 2>&1; then
    echo "!! iptables not found. Is this an Ubuntu image?" >&2
    exit 1
fi

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

# ---------------------------------------------------------------------------
log "1/3  Allowing inbound tcp/80 and tcp/443"
for port in 80 443; do
    # -C tests for an exact rule match; non-zero means it is not there yet.
    if iptables -C INPUT -p tcp --dport "$port" -j ACCEPT 2>/dev/null; then
        echo "    [skip] tcp/${port} already allowed"
    else
        # Insert at the top of INPUT so it sits ahead of Oracle's REJECT rule.
        iptables -I INPUT 1 -p tcp --dport "$port" -j ACCEPT
        echo "    [add ] tcp/${port} allowed"
    fi
done

# ---------------------------------------------------------------------------
log "2/3  Persisting rules across reboot"
if command -v netfilter-persistent >/dev/null 2>&1; then
    netfilter-persistent save
    echo "    saved via netfilter-persistent"
elif [ -d /etc/iptables ]; then
    iptables-save > /etc/iptables/rules.v4
    echo "    saved to /etc/iptables/rules.v4"
else
    mkdir -p /etc/iptables
    iptables-save > /etc/iptables/rules.v4
    echo "    saved to /etc/iptables/rules.v4"
    echo "    !! iptables-persistent is not installed — rules may NOT reload on boot."
    echo "       Install it with:  sudo apt-get install -y iptables-persistent"
fi

# ---------------------------------------------------------------------------
log "3/3  Current INPUT chain"
iptables -L INPUT -n --line-numbers

echo
if iptables -C INPUT -p tcp --dport 80 -j ACCEPT 2>/dev/null \
   && iptables -C INPUT -p tcp --dport 443 -j ACCEPT 2>/dev/null; then
    echo "Ports 80 and 443 are open at the OS level."
    echo "Still required: the VCN Security List ingress rules (see deploy/oracle.md §2)."
    echo "Verify from your laptop:  curl -I http://<PUBLIC_IP>/"
else
    echo "!! Rules did not apply as expected — inspect the INPUT chain above." >&2
    exit 1
fi
