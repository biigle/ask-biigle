#!/usr/bin/env python3
"""
BIIGLE Manual HTML Downloader
=============================
Crawls https://biigle.de/manual and downloads all manual HTML pages
into the `manualhtml` directory as 0.html, 1.html, 2.html, etc.
Also generates a `url_map.json` to keep track of URL-to-filename mappings.
"""

import json
import os
import sys
import time
from html.parser import HTMLParser
from urllib.parse import urljoin, urlparse, urldefrag
from urllib.request import Request, urlopen

BASE_URL = "https://biigle.de/manual"
OUTPUT_DIR = os.path.join(os.path.dirname(__file__), "manualhtml")
USER_AGENT = "Mozilla/5.0 (compatible; BiigleManualCrawler/1.0)"


class LinkExtractor(HTMLParser):
    def __init__(self, base_url):
        super().__init__()
        self.base_url = base_url
        self.links = set()

    def handle_starttag(self, tag, attrs):
        if tag == "a":
            for attr, value in attrs:
                if attr == "href" and value:
                    full_url = urljoin(self.base_url, value)
                    # Remove URL fragment / anchor
                    defragged_url, _ = urldefrag(full_url)
                    self.links.add(defragged_url)


def normalize_url(url):
    """Normalize URL by stripping fragments and trailing slashes for consistency."""
    url, _ = urldefrag(url)
    if url.endswith("/") and url != BASE_URL + "/":
        url = url.rstrip("/")
    return url


def is_valid_manual_url(url):
    """Check if the URL belongs to the BIIGLE manual domain and path."""
    parsed = urlparse(url)
    if parsed.netloc != "biigle.de":
        return False
    # Only follow links under /manual
    return parsed.path.startswith("/manual")


def download_manual():
    os.makedirs(OUTPUT_DIR, exist_ok=True)

    # Clean up old HTML files to prevent stale files from previous runs
    for fname in os.listdir(OUTPUT_DIR):
        if fname.endswith(".html"):
            try:
                os.remove(os.path.join(OUTPUT_DIR, fname))
            except Exception:
                pass

    visited_urls = set()
    queue = [BASE_URL]
    url_to_file = {}
    file_to_url = {}

    file_counter = 0

    print(f"🚀 Starting crawl of BIIGLE Manual from: {BASE_URL}")
    print(f"📁 Saving HTML files to: {OUTPUT_DIR}\n")

    while queue:
        current_url = normalize_url(queue.pop(0))

        if current_url in visited_urls:
            continue

        visited_urls.add(current_url)
        filename = f"{file_counter}.html"
        filepath = os.path.join(OUTPUT_DIR, filename)

        req = Request(current_url, headers={"User-Agent": USER_AGENT})

        try:
            with urlopen(req, timeout=15) as response:
                content_type = response.headers.get("Content-Type", "")
                if "text/html" not in content_type:
                    continue

                html_bytes = response.read()
                html_text = html_bytes.decode("utf-8", errors="replace")

            with open(filepath, "w", encoding="utf-8") as f:
                f.write(html_text)

            url_to_file[current_url] = filename
            file_to_url[filename] = current_url
            print(f"  [{file_counter}] Downloaded: {current_url} -> {filename}", flush=True)
            file_counter += 1

            # Extract links for further crawling
            parser = LinkExtractor(current_url)
            parser.feed(html_text)

            for link in parser.links:
                norm_link = normalize_url(link)
                if is_valid_manual_url(norm_link) and norm_link not in visited_urls:
                    if norm_link not in queue:
                        queue.append(norm_link)

            # Be polite to the server
            time.sleep(0.15)

        except Exception as e:
            print(f"  ⚠️  Failed to download {current_url}: {e}", file=sys.stderr)

    # Save mapping file
    mapping_path = os.path.join(OUTPUT_DIR, "url_map.json")
    with open(mapping_path, "w", encoding="utf-8") as f:
        json.dump(
            {"file_to_url": file_to_url, "url_to_file": url_to_file},
            f,
            indent=2,
        )

    print(f"\n🎉 Crawl finished! Downloaded {file_counter} pages.")
    print(f"📄 Mapping saved to: {mapping_path}")


if __name__ == "__main__":
    download_manual()
