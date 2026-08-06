#!/usr/bin/env python3
"""
Rebuild RAG Arcana
==================
API Documentation: https://chat-ai.academiccloud.de/arcanas/api/v1/docs#/

This script updates the BIIGLE Arcana by performing the following steps:
1. Downloads the latest BIIGLE manual HTML pages using `download_manual` from `download_manual_html`.
2. Reads credentials (`ASK_BIIGLE_LLM_API_KEY`, `ASK_BIIGLE_LLM_ARCANA_ID`) from the main BIIGLE `.env` file (`../../../../.env`).
3. Lists all existing files in the Arcana.
4. (Default) Downloads existing tracked files (`.html` and `goldenFacts.md`) from Arcana and compares them against local files.
5. Deletes only missing or modified files and uploads new or changed files.
6. Triggers indexing for the Arcana if changes occurred and waits for completion.
"""

import argparse
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
GOLDEN_FACTS_PATH = os.path.join(os.path.dirname(__file__), "goldenFacts.md")


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
    parser = argparse.ArgumentParser(
        description="Rebuild RAG Arcana by downloading manual HTML files and updating the Arcana index."
    )
    parser.add_argument(
        "--force",
        "--no-compare",
        dest="force",
        action="store_true",
        help="Force deletion and re-upload of all tracked files without comparing against existing files in Arcana.",
    )
    parser.add_argument(
        "--skip-scrape",
        action="store_true",
        help="Skip downloading/scraping manual HTML pages from biigle.de.",
    )
    args = parser.parse_args()

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
    if not args.skip_scrape:
        print("\n--------------------------------------------------")
        print("1️⃣  Downloading manual HTML pages...")
        print("--------------------------------------------------")
        download_manual()
    else:
        print("\n⏭️  Skipping manual HTML download (--skip-scrape).")

    # 3. Build local managed files dict
    local_files = {}
    if os.path.exists(MANUAL_HTML_DIR):
        for fname in sorted(os.listdir(MANUAL_HTML_DIR)):
            if fname.endswith(".html"):
                local_files[fname] = os.path.join(MANUAL_HTML_DIR, fname)

    if os.path.exists(GOLDEN_FACTS_PATH):
        local_files["goldenFacts.md"] = GOLDEN_FACTS_PATH

    # 4. List existing files of the arcana
    print("\n--------------------------------------------------")
    print("2️⃣  Listing existing files in Arcana...")
    print("--------------------------------------------------")
    resp = client.get(f"{API_BASE_URL}/arcana/{arcana_name}/files/", headers=headers)
    resp.raise_for_status()
    existing_files = resp.json()

    existing_tracked_files = {
        f["name"]: f for f in existing_files
        if f["name"].endswith(".html") or f["name"] == "goldenFacts.md"
    }
    print(f"Found {len(existing_files)} file(s) in Arcana '{arcana_name}' ({len(existing_tracked_files)} tracked file(s)).")

    files_to_delete = []
    files_to_upload = []

    if args.force:
        print("\n--------------------------------------------------")
        print("3️⃣  [FORCE MODE] Marking all tracked files for deletion and re-upload...")
        print("--------------------------------------------------")
        files_to_delete = list(existing_tracked_files.keys())
        files_to_upload = list(local_files.keys())
    else:
        # Compare remote vs local files
        print("\n--------------------------------------------------")
        print("3️⃣  Comparing remote files with local files...")
        print("--------------------------------------------------")

        unchanged_count = 0
        for fname, local_filepath in local_files.items():
            with open(local_filepath, "rb") as f_obj:
                local_bytes = f_obj.read()

            if fname in existing_tracked_files:
                # Download remote file content for comparison
                dl_resp = client.get(
                    f"{API_BASE_URL}/arcana/{arcana_name}/files/{fname}/download",
                    headers=headers,
                )
                if dl_resp.status_code == 200 and dl_resp.content == local_bytes:
                    unchanged_count += 1
                else:
                    print(f"  📝 Modified: {fname} (content differs)")
                    files_to_delete.append(fname)
                    files_to_upload.append(fname)
            else:
                print(f"  ➕ New local file: {fname}")
                files_to_upload.append(fname)

        # Check for remote tracked files that no longer exist locally
        for fname in existing_tracked_files:
            if fname not in local_files:
                print(f"  🗑️  Stale remote file (no longer exists locally): {fname}")
                files_to_delete.append(fname)

        print(f"\n📊 Comparison Summary:")
        print(f"   Unchanged: {unchanged_count}")
        print(f"   To Delete: {len(files_to_delete)}")
        print(f"   To Upload: {len(files_to_upload)}")

    if not files_to_delete and not files_to_upload and not args.force:
        print("\n✨ No file changes detected. Arcana is up to date!")
        sys.exit(0)

    # 5. Remove changed/stale files from Arcana
    if files_to_delete:
        print("\n--------------------------------------------------")
        print("4️⃣  Removing modified/stale files from Arcana...")
        print("--------------------------------------------------")
        for fname in files_to_delete:
            del_resp = client.delete(f"{API_BASE_URL}/arcana/{arcana_name}/files/{fname}", headers=headers)
            del_resp.raise_for_status()
            print(f"  🗑️  Deleted: {fname}")

    # 6. Upload new/modified files to Arcana
    if files_to_upload:
        print("\n--------------------------------------------------")
        print("5️⃣  Uploading new/modified files to Arcana...")
        print("--------------------------------------------------")
        for idx, fname in enumerate(files_to_upload, start=1):
            filepath = local_files[fname]
            mime_type = "text/markdown" if fname.endswith(".md") else "text/html"
            with open(filepath, "rb") as f_obj:
                files = {"file": (fname, f_obj, mime_type)}
                up_resp = client.post(
                    f"{API_BASE_URL}/arcana/{arcana_name}/files/",
                    headers=headers,
                    files=files,
                )
                up_resp.raise_for_status()
            print(f"  📤 [{idx}/{len(files_to_upload)}] Uploaded: {fname}")

    # 7. Trigger indexing
    print("\n--------------------------------------------------")
    print("6️⃣  Triggering indexing for Arcana...")
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