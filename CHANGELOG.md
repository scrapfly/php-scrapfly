# Changelog

## 0.1.0 (unreleased)

First release.

- Web Scraping API: `scrape()`, `scrapeProxified()` and `concurrentScrape()`, large objects fetched in place.
- Proxy pools: `ProxyPool::Basic`, `Unblocker`, `UnblockerUltra`, `Auto` and `PublicTorPool`. The `unblocker` and
  `asp` arguments are kept, deprecated, and sent as `asp`.
- Screenshot API and Extraction API.
- Crawler API: start, status, URLs, contents, WARC and HAR artifacts, cancel, search, streamed and buffered prompt,
  JSON Schema extraction, and the `Crawl` helper.
- Webhook signature verification.
- Typed exceptions carrying the API error code. A transport error never prints the API key.
