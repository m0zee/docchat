#!/bin/bash
# Creates the free DocChat server from Oracle Cloud Shell (the >_ icon in the
# Oracle console), where the oci CLI is already signed in:
#   curl -fsSL https://raw.githubusercontent.com/m0zee/docchat/main/deploy/oracle/create-server.sh | bash
# It makes a network (VCN, internet gateway, public subnet, ports 22/80/443 open),
# an SSH key in ~/.ssh/docchat (kept in Cloud Shell), and an Always Free
# VM.Standard.A1.Flex instance (2 OCPUs, 12 GB, Ubuntu 24.04 aarch64) that sets
# itself up with cloud-init.sh. Safe to run again: it reuses what already exists.
set -euo pipefail

NAME="${DOCCHAT_NAME:-docchat}"
OCPUS="${DOCCHAT_OCPUS:-2}"
MEMORY_GB="${DOCCHAT_MEMORY_GB:-12}"
CLOUD_INIT_URL="${DOCCHAT_CLOUD_INIT_URL:-https://raw.githubusercontent.com/m0zee/docchat/main/deploy/oracle/cloud-init.sh}"
WORK="$HOME/docchat-oci"
mkdir -p "$WORK"

C="${OCI_TENANCY:-$(oci iam availability-domain list --query 'data[0]."compartment-id"' --raw-output)}"
echo "Compartment (tenancy root): $C"

# SSH key, kept in Cloud Shell's home folder (RSA: Cloud Shell runs in FIPS mode, which refuses ed25519).
# DOCCHAT_SSH_PUBLIC_KEY (for runs outside Cloud Shell, like the GitHub
# workflow) puts that public key on the server instead.
if [ -n "${DOCCHAT_SSH_PUBLIC_KEY:-}" ]; then
  mkdir -p "$HOME/.ssh"
  printf '%s\n' "$DOCCHAT_SSH_PUBLIC_KEY" > "$HOME/.ssh/docchat.pub"
elif [ ! -f "$HOME/.ssh/docchat" ]; then
  mkdir -p "$HOME/.ssh"
  ssh-keygen -t rsa -b 4096 -N "" -C docchat -f "$HOME/.ssh/docchat" >/dev/null
fi

q() { oci "$@" 2>/dev/null || true; }

# Network.
VCN=$(q network vcn list -c "$C" --display-name "$NAME-vcn" --lifecycle-state AVAILABLE --query 'data[0].id' --raw-output)
if [ -z "$VCN" ] || [ "$VCN" = "null" ]; then
  echo "Creating VCN..."
  VCN=$(oci network vcn create -c "$C" --display-name "$NAME-vcn" --cidr-blocks '["10.0.0.0/16"]' \
    --dns-label "$NAME" --wait-for-state AVAILABLE --query 'data.id' --raw-output)
fi

IGW=$(q network internet-gateway list -c "$C" --vcn-id "$VCN" --query 'data[0].id' --raw-output)
if [ -z "$IGW" ] || [ "$IGW" = "null" ]; then
  echo "Creating internet gateway..."
  IGW=$(oci network internet-gateway create -c "$C" --vcn-id "$VCN" --is-enabled true \
    --display-name "$NAME-igw" --wait-for-state AVAILABLE --query 'data.id' --raw-output)
fi

RT=$(oci network vcn get --vcn-id "$VCN" --query 'data."default-route-table-id"' --raw-output)
oci network route-table update --rt-id "$RT" --force \
  --route-rules "[{\"destination\":\"0.0.0.0/0\",\"destinationType\":\"CIDR_BLOCK\",\"networkEntityId\":\"$IGW\"}]" >/dev/null

SL=$(oci network vcn get --vcn-id "$VCN" --query 'data."default-security-list-id"' --raw-output)
cat > "$WORK/ingress.json" <<'JSON'
[
  {"source":"0.0.0.0/0","protocol":"6","tcpOptions":{"destinationPortRange":{"min":22,"max":22}}},
  {"source":"0.0.0.0/0","protocol":"6","tcpOptions":{"destinationPortRange":{"min":80,"max":80}}},
  {"source":"0.0.0.0/0","protocol":"6","tcpOptions":{"destinationPortRange":{"min":443,"max":443}}},
  {"source":"0.0.0.0/0","protocol":"17","udpOptions":{"destinationPortRange":{"min":443,"max":443}}},
  {"source":"0.0.0.0/0","protocol":"1","icmpOptions":{"type":3,"code":4}},
  {"source":"10.0.0.0/16","protocol":"1","icmpOptions":{"type":3}}
]
JSON
echo '[{"destination":"0.0.0.0/0","protocol":"all"}]' > "$WORK/egress.json"
oci network security-list update --security-list-id "$SL" --force \
  --ingress-security-rules "file://$WORK/ingress.json" \
  --egress-security-rules "file://$WORK/egress.json" >/dev/null
echo "Firewall: ports 22, 80, 443 open."

SUBNET=$(q network subnet list -c "$C" --vcn-id "$VCN" --display-name "$NAME-public" --query 'data[0].id' --raw-output)
if [ -z "$SUBNET" ] || [ "$SUBNET" = "null" ]; then
  echo "Creating public subnet..."
  SUBNET=$(oci network subnet create -c "$C" --vcn-id "$VCN" --cidr-block 10.0.0.0/24 \
    --display-name "$NAME-public" --dns-label pub --route-table-id "$RT" \
    --security-list-ids "[\"$SL\"]" --wait-for-state AVAILABLE --query 'data.id' --raw-output)
fi

# Existing server? Print it and stop.
INST=$(q compute instance list -c "$C" --display-name "$NAME" --lifecycle-state RUNNING --query 'data[0].id' --raw-output)
if [ -z "$INST" ] || [ "$INST" = "null" ]; then
  IMAGE=$(oci compute image list -c "$C" --operating-system "Canonical Ubuntu" --operating-system-version "24.04" \
    --shape VM.Standard.A1.Flex --sort-by TIMECREATED --sort-order DESC \
    --query 'data[?!contains("display-name", `Minimal`)] | [0].id' --raw-output)
  echo "Image: $IMAGE"
  curl -fsSL "$CLOUD_INIT_URL" -o "$WORK/cloud-init.sh"

  ADS=$(oci iam availability-domain list -c "$C" --query 'data[].name' --raw-output | tr -d '[]",')
  # Free Arm capacity comes and goes. Oracle answers "Out of host capacity" or
  # "Too many requests" when there's none; keep retrying for a while.
  DEADLINE=$(( $(date +%s) + ${DOCCHAT_RETRY_MINUTES:-30} * 60 ))
  ATTEMPT=0
  INST=""
  while [ -z "$INST" ]; do
  ATTEMPT=$((ATTEMPT + 1))
  for AD in $ADS; do
    if INST=$(oci compute instance launch -c "$C" --availability-domain "$AD" --display-name "$NAME" \
        --shape VM.Standard.A1.Flex --shape-config "{\"ocpus\":$OCPUS,\"memoryInGBs\":$MEMORY_GB}" \
        --image-id "$IMAGE" --subnet-id "$SUBNET" --assign-public-ip true \
        --ssh-authorized-keys-file "$HOME/.ssh/docchat.pub" --user-data-file "$WORK/cloud-init.sh" \
        --wait-for-state RUNNING --query 'data.id' --raw-output 2> "$WORK/launch-error.txt"); then
      break
    fi
    INST=""
    echo "  attempt $ATTEMPT: $(grep -o '"message": "[^"]*"' "$WORK/launch-error.txt" || head -c 300 "$WORK/launch-error.txt")"
  done
  if [ -z "$INST" ]; then
    if [ "$(date +%s)" -ge "$DEADLINE" ]; then
      echo "Could not create the server yet (no free Arm capacity). Run this again later."
      exit 1
    fi
    sleep "${DOCCHAT_RETRY_SECONDS:-60}"
  fi
  done
fi

IP=$(oci compute instance list-vnics --instance-id "$INST" --query 'data[0]."public-ip"' --raw-output)
cat > "$WORK/server.txt" <<EOF
instance=$INST
ip=$IP
url=https://$IP.sslip.io
ssh=ssh -i ~/.ssh/docchat ubuntu@$IP
EOF
echo
echo "== Server is running"
cat "$WORK/server.txt"
echo "First-boot setup (Docker, build, start) takes about 15 to 25 minutes. Watch it with:"
echo "  ssh -i ~/.ssh/docchat ubuntu@$IP sudo tail -f /var/log/docchat-setup.log"
