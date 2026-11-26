#!/bin/bash
# Run in Oracle Cloud Shell. Prints the text for the ORACLE_SETTINGS GitHub
# secret used by .github/workflows/oracle-create-server.yml, and saves it to
# ~/oracle-settings-for-github.txt (download it with Cloud Shell's menu, paste
# it into the secret, then delete the file).
# Creates an API signing key for your Oracle user the first time it runs
# (Oracle allows 3 API keys per user). Delete the key in the console under
# your profile > API keys when you no longer need the workflow.
set -euo pipefail
KEY="$HOME/.oci/github_actions.pem"
mkdir -p "$HOME/.oci"
if [ ! -f "$KEY" ]; then
  openssl genrsa -out "$KEY" 2048 2>/dev/null
  chmod 600 "$KEY"
fi
openssl rsa -pubout -in "$KEY" -out "$HOME/.oci/github_actions_pub.pem" 2>/dev/null
USER_OCID="${OCI_CS_USER_OCID:?Run this in Oracle Cloud Shell}"
# Find this key among the user's API keys by its public half (Cloud Shell runs
# in FIPS mode, where openssl can't compute the MD5 fingerprint itself).
find_fp() {
  oci iam user api-key list --user-id "$USER_OCID" --all --output json | python3 -c '
import json, sys
pub = open(sys.argv[1]).read().split()
for k in json.load(sys.stdin)["data"]:
    if k.get("lifecycle-state") == "ACTIVE" and k["key-value"].split() == pub:
        print(k["fingerprint"]); break
' "$HOME/.oci/github_actions_pub.pem"
}
FP=$(find_fp)
if [ -z "$FP" ]; then
  oci iam user api-key upload --user-id "$USER_OCID" --key-file "$HOME/.oci/github_actions_pub.pem" >/dev/null
  FP=$(find_fp)
fi
[ -f "$HOME/.ssh/docchat.pub" ] || { mkdir -p "$HOME/.ssh"; ssh-keygen -t rsa -b 4096 -N "" -C docchat -f "$HOME/.ssh/docchat" >/dev/null; }
OUT="$HOME/oracle-settings-for-github.txt"
{
  echo "user=$USER_OCID"
  echo "fingerprint=$FP"
  echo "tenancy=$OCI_TENANCY"
  echo "region=$OCI_REGION"
  echo "ssh_public_key=$(cat "$HOME/.ssh/docchat.pub")"
  cat "$KEY"
} > "$OUT"
chmod 600 "$OUT"
echo "Saved to $OUT. Paste its whole text into the GitHub secret ORACLE_SETTINGS."
