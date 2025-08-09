<?php

declare(strict_types=1);

namespace Sc\Integration\Fb;

class FacebookApiException extends \Exception
{
    private array $responseData;

    public function __construct(string $message, int $code = 0, array $responseData = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->responseData = $responseData;
    }

    public function getResponseData(): array
    {
        return $this->responseData;
    }

    public function getErrorType(): ?string
    {
        return $this->responseData['error']['type'] ?? null;
    }

    public function getErrorSubcode(): ?int
    {
        return $this->responseData['error']['error_subcode'] ?? null;
    }

    public function getFbTraceId(): ?string
    {
        return $this->responseData['error']['fbtrace_id'] ?? null;
    }
}
