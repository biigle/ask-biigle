#!/usr/bin/env python3
"""
Rebuild RAG Arcana
==================
API Documentation: https://chat-ai.academiccloud.de/arcanas/api/v1/docs#/

This script updates the BIIGLE Arcana by performing the following steps:
1. Downloads the latest BIIGLE manual HTML pages using `download_manual` from `download_manual_html`.
2. Reads credentials (`ASK_BIIGLE_LLM_API_KEY`, `ASK_BIIGLE_LLM_ARCANA_ID`) from the main BIIGLE `.env` file (`../../../../.env`).
3. Lists all existing files in the Arcana.
4. Removes all `.html` files currently in the Arcana.
5. Uploads the newly downloaded `.html` files.
6. Triggers indexing for the Arcana and waits for completion.
"""

import os
import sys
import time
import httpx
from download_manual_html import download_manual

# -----------------------------------------------------------------------------
# Configuration
# -----------------------------------------------------------------------------
API_BASE_URL = "https://chat-ai.academiccloud.de/v1/arcanas/api/v1"
ENV_PATH = os.path.abspath(os.path.join(os.path.dirname(__file__), "../../../../.env"))
MANUAL_HTML_DIR = os.path.join(os.path.dirname(__file__), "manualhtml")


def load_env_file(filepath: str) -> dict:
    """Parses a simple .env file into a key-value dictionary."""
    env_vars = {}
    if not os.path.exists(filepath):
        print(f"⚠️  Warning: .env file not found at: {filepath}", file=sys.stderr)
        return env_vars

    with open(filepath, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, val = line.split("=", 1)
            key = key.strip()
            val = val.strip().strip("'\"")
            env_vars[key] = val
    return env_vars


def get_arcana_info(client: httpx.Client, headers: dict, arcana_name: str) -> dict:
    """Fetches details for a specific arcana from the API."""
    resp = client.get(f"{API_BASE_URL}/arcana/", headers=headers)
    resp.raise_for_status()
    for arcana in resp.json():
        if arcana.get("name") == arcana_name:
            return arcana
    return {}


def main():
    # 1. Load credentials from .env
    print(f"🔑 Loading credentials from: {ENV_PATH}")
    env_vars = load_env_file(ENV_PATH)

    api_key = env_vars.get("ASK_BIIGLE_LLM_API_KEY")
    arcana_id = env_vars.get("ASK_BIIGLE_LLM_ARCANA_ID", "BiigleManual")

    # Arcana name variable
    arcana_name = arcana_id.split("/")[-1] if "/" in arcana_id else arcana_id

    if not api_key:
        print("❌ Error: ASK_BIIGLE_LLM_API_KEY not found in .env", file=sys.stderr)
        sys.exit(1)

    print(f"📌 Arcana Name: {arcana_name}")

    headers = {
        "Authorization": api_key,
        "Accept": "application/json",
    }
    client = httpx.Client(timeout=300.0)

    # 2. Download manual HTML files
    print("\n--------------------------------------------------")
    print("1️⃣  Downloading manual HTML pages...")
    print("--------------------------------------------------")
    download_manual()

    # 3. List all files of the arcana
    print("\n--------------------------------------------------")
    print("2️⃣  Listing existing files in Arcana...")
    print("--------------------------------------------------")
    resp = client.get(f"{API_BASE_URL}/arcana/{arcana_name}/files/", headers=headers)
    resp.raise_for_status()
    existing_files = resp.json()

    print(f"Found {len(existing_files)} file(s) in Arcana '{arcana_name}':")
    for f in existing_files:
        print(f"  - {f['name']} ({f.get('size', 0)} bytes)")

    # 4. Remove all html files
    print("\n--------------------------------------------------")
    print("3️⃣  Removing HTML files from Arcana...")
    print("--------------------------------------------------")
    html_files_to_remove = [f for f in existing_files if f['name'].endswith('.html')]
    if not html_files_to_remove:
        print("No HTML files found to remove.")
    else:
        for f in html_files_to_remove:
            fname = f['name']
            del_resp = client.delete(f"{API_BASE_URL}/arcana/{arcana_name}/files/{fname}", headers=headers)
            del_resp.raise_for_status()
            print(f"  🗑️  Deleted: {fname}")

    # 5. Add the new html files
    print("\n--------------------------------------------------")
    print("4️⃣  Uploading new HTML files to Arcana...")
    print("--------------------------------------------------")
    new_html_files = sorted([f for f in os.listdir(MANUAL_HTML_DIR) if f.endswith('.html')])
    if not new_html_files:
        print(f"⚠️  No HTML files found in {MANUAL_HTML_DIR} to upload.")
    else:
        for idx, fname in enumerate(new_html_files, start=1):
            filepath = os.path.join(MANUAL_HTML_DIR, fname)
            with open(filepath, "rb") as f_obj:
                files = {"file": (fname, f_obj, "text/html")}
                up_resp = client.post(
                    f"{API_BASE_URL}/arcana/{arcana_name}/files/",
                    headers=headers,
                    files=files,
                )
                up_resp.raise_for_status()
            print(f"  📤 [{idx}/{len(new_html_files)}] Uploaded: {fname}")

    # 6. Trigger an indexing
    print("\n--------------------------------------------------")
    print("5️⃣  Triggering indexing for Arcana...")
    print("--------------------------------------------------")
    try:
        idx_resp = client.post(f"{API_BASE_URL}/arcana/{arcana_name}/generate-index", headers=headers)
        if idx_resp.status_code == 200:
            print(f"  ✨ Indexing response: {idx_resp.json()}")
        else:
            print(f"  ℹ️  Trigger indexing response code: {idx_resp.status_code}")
    except (httpx.HTTPStatusError, httpx.TimeoutException) as e:
        # Gateway 504 / timeouts can happen while indexing runs asynchronously on server
        print(f"  ℹ️  Indexing trigger sent (received {e}). Monitoring progress...")

    # Poll status until indexing completes
    print("  ⏳ Polling indexing status...")
    info = {}
    while True:
        info = get_arcana_info(client, headers, arcana_name)
        index_info = info.get("index_info") or {}
        status = index_info.get("index_status", "UNKNOWN")
        print(f"     Status: {status}")
        if status not in ("PENDING", "PROCESSING", "UNKNOWN"):
            break
        time.sleep(10)

    if status == "INDEXED":
        print("\n🎉 Arcana update process completed successfully!")
    else:
        index_info = info.get("index_info") or {}
        err = index_info.get("error_msg")
        print(f"\n❌ Arcana indexing finished with status '{status}'! Error: {err}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()