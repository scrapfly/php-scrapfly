<?php

declare(strict_types=1);

namespace Scrapfly;

/**
 * The answer of the Web Scraping API: what the target sent back in `result`, the
 * request as the API ran it in `config`, and the run's details in `context`.
 */
final class ScrapeResponse
{
    /**
     * @param array<string, mixed> $data the decoded API response
     * @param array<string, array<string>> $headers the API's own response headers
     */
    public function __construct(
        public readonly array $data,
        public readonly array $headers = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function result(): array
    {
        return $this->data['result'] ?? [];
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->data['config'] ?? [];
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->data['context'] ?? [];
    }

    public function uuid(): ?string
    {
        return $this->data['uuid'] ?? null;
    }

    /** The page, in the requested format; bytes for a binary document. */
    public function content(): string
    {
        return (string) ($this->result()['content'] ?? '');
    }

    /** `text`, `json`, `binary`, `markdown`, ... */
    public function format(): ?string
    {
        return $this->result()['format'] ?? null;
    }

    /** The target's status code. */
    public function statusCode(): ?int
    {
        return $this->result()['status_code'] ?? null;
    }

    public function isSuccess(): bool
    {
        return ($this->result()['success'] ?? false) === true;
    }

    /** The final URL, after redirects. */
    public function url(): ?string
    {
        return $this->result()['url'] ?? null;
    }

    /** @return array<string, mixed> the target's response headers */
    public function responseHeaders(): array
    {
        return $this->result()['response_headers'] ?? [];
    }

    /** @return array<string, mixed> */
    public function requestHeaders(): array
    {
        return $this->result()['request_headers'] ?? [];
    }

    /** @return array<string, mixed> name => {url, extension, format, size, ...} */
    public function screenshots(): array
    {
        return $this->result()['screenshots'] ?? [];
    }

    /** What `extractionTemplate`, `extractionPrompt` or `extractionModel` produced. */
    public function extractedData(): mixed
    {
        return $this->result()['extracted_data'] ?? null;
    }

    /** The scrape's page in the dashboard. */
    public function logUrl(): ?string
    {
        return $this->result()['log_url'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function error(): ?array
    {
        return $this->result()['error'] ?? null;
    }

    /** API credits the scrape cost, from the `X-Scrapfly-Api-Cost` header. */
    public function cost(): ?int
    {
        $cost = $this->header('x-scrapfly-api-cost');

        return $cost === null ? null : (int) $cost;
    }

    /** API credits left on the account, from the `X-Scrapfly-Remaining-Api-Credit` header. */
    public function remainingCredit(): ?int
    {
        $remaining = $this->header('x-scrapfly-remaining-api-credit');

        return $remaining === null ? null : (int) $remaining;
    }

    private function header(string $name): ?string
    {
        foreach ($this->headers as $header => $values) {
            if (strcasecmp($header, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
