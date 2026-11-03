# Changelog

## 0.0.1 (2026-11-03)

First release.

- Web Scraping API: `scrape()`, `scrapeProxified()` and `concurrentScrape()`, large objects fetched in place.
- Proxy pools: `ProxyPool::Basic`, `Unblocker`, `UnblockerUltra`, `Auto` and `PublicTorPool`. The `unblocker` and
  `asp` arguments are kept, deprecated, and sent as `asp`.
- Screenshot API and Extraction API.
- Batch: `scrapeBatch()` streams each result as its scrape finishes, JSON or msgpack parts, proxified parts as the
  target's response; `scrapeBatchAsync()` does the same on Guzzle's promises.
- Crawler API: start, status, URLs, contents, WARC (with `pages()`) and HAR artifacts, cancel, refresh, search,
  streamed and buffered prompt, JSON Schema extraction, and the `Crawl` helper.
- Cloud Browser API: the CDP URL builder, unblock, sessions, extensions, credential vaults and their linked
  service, debug recordings.
- Schedules, Monitoring API and `classify()`.
- Webhook signature verification.
- Typed exceptions carrying the API error code. A transport error never prints the API key.
