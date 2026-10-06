<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Value object: member-facing banner when a public month statement has no rows. */
final class StatementAbsenceReason
{
    public const KIND_UNREVIEWED = 'unreviewed';
    public const KIND_STALE = 'stale';
    public const KIND_NO_SINCE = 'no_since';

    private function __construct(
        private readonly string $kind,
        private readonly ?string $sinceDate,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public static function unreviewed(): self
    {
        return DenariusLog::trace(__METHOD__, static fn (): self => new self(self::KIND_UNREVIEWED, null));
    }

    public static function staleTransactions(): self
    {
        return DenariusLog::trace(__METHOD__, static fn (): self => new self(self::KIND_STALE, null));
    }

    public static function noCurrentSince(string $isoDate): self
    {
        return DenariusLog::trace(__METHOD__, static fn (): self => new self(self::KIND_NO_SINCE, $isoDate));
    }

    public function message(): string
    {
        return DenariusLog::trace(__METHOD__, function (): string {
            return match ($this->kind) {
                self::KIND_UNREVIEWED => 'Unreviewed',
                self::KIND_STALE => 'Stale transactions',
                self::KIND_NO_SINCE => 'No current transactions since ' . ($this->sinceDate ?? ''),
                default => '',
            };
        });
    }

    public function kind(): string
    {
        return DenariusLog::trace(__METHOD__, fn (): string => $this->kind);
    }

    public function sinceDate(): ?string
    {
        return DenariusLog::trace(__METHOD__, fn (): ?string => $this->sinceDate);
    }

    /**
     * @return array{kind: string, sinceDate: ?string}
     */
    public function toCache(): array
    {
        return DenariusLog::trace(__METHOD__, fn (): array => [
            'kind' => $this->kind,
            'sinceDate' => $this->sinceDate,
        ]);
    }

    /**
     * @param array{kind?: string, sinceDate?: ?string} $payload
     */
    public static function fromCache(array $payload): ?self
    {
        return DenariusLog::trace(__METHOD__, function () use ($payload): ?self {
            $kind = $payload['kind'] ?? '';
            if ($kind === self::KIND_UNREVIEWED) {
                return self::unreviewed();
            }
            if ($kind === self::KIND_STALE) {
                return self::staleTransactions();
            }
            if ($kind === self::KIND_NO_SINCE) {
                $since = $payload['sinceDate'] ?? null;
                if (!is_string($since) || $since === '') {
                    return null;
                }

                return self::noCurrentSince($since);
            }

            return null;
        });
    }
}
