<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Api;

/**
 * Non-2xx response from the BillTo API, or a transport failure (status 0).
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $body  Decoded JSON body (empty when the response was not JSON).
     */
    public function __construct(
        string $message,
        private int $status,
        private array $body = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    public function isTransport(): bool
    {
        return $this->status === 0;
    }

    public function isValidation(): bool
    {
        return $this->status === 422;
    }

    public function isConflict(): bool
    {
        return $this->status === 409;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isAuth(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }

    /** 429 without Retry-After = exhausted plan quota, retrying is pointless. */
    public function isPlanLimit(): bool
    {
        return $this->status === 429 && isset($this->body['usage']);
    }

    /** Transient failure that a later retry may resolve. */
    public function isRetryable(): bool
    {
        if ($this->isTransport()) {
            return true;
        }

        if ($this->status === 429) {
            return ! $this->isPlanLimit();
        }

        return in_array($this->status, [500, 502, 503, 504], true);
    }

    /**
     * Validation errors flattened to "field: message" lines (for order notes).
     *
     * @return list<string>
     */
    public function validationMessages(): array
    {
        $errors = $this->body['errors'] ?? [];
        $lines = [];

        if (! is_array($errors)) {
            return $lines;
        }

        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $lines[] = $field.': '.(is_scalar($message) ? (string) $message : wp_json_encode($message));
            }
        }

        return $lines;
    }
}
