<?php

namespace App\Lhdn;

final class LhdnCredentials
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly ?string $onBehalfOf,
        public readonly string $mode, // intermediary | own
    ) {}

    public function cacheKeyPart(): string
    {
        return $this->mode.':'.sha1($this->clientId.'|'.($this->onBehalfOf ?? ''));
    }
}
