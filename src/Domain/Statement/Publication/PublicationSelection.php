<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Value object: manager publish, redact, and embargo choices for one review row. */
final class PublicationSelection
{
    public function __construct(
        private readonly string $tellerTransactionId,
        private readonly bool $publish,
        private readonly bool $redact,
        private readonly bool $embargo,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function tellerTransactionId(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->tellerTransactionId);
    }

    public function redact(): bool
    {
        return DenariusLog::trace(__METHOD__, fn (): bool => $this->redact);
    }

    public function embargo(): bool
    {
        return DenariusLog::trace(__METHOD__, fn (): bool => $this->embargo);
    }

    /** Redact publishes the row with generic description copy, so it implies publish. */
    public function wantsPublished(): bool
    {
        return DenariusLog::trace(__METHOD__, fn (): bool => $this->publish || $this->redact);
    }

    /**
     * @return array{publish: bool, redact: bool, embargo: bool}
     */
    public function view(): array
    {
        return DenariusLog::trace(__METHOD__, fn (): array => [
            'publish' => $this->publish,
            'redact' => $this->redact,
            'embargo' => $this->embargo,
        ]);
    }
}
