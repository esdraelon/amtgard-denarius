<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication;

use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Value object: persisted JSON publication_flags on ledger rows. */
final class PublicationFlags
{
    /**
     * @param list<string> $patternIds
     */
    private function __construct(
        private bool $hard,
        private array $patternIds,
        private bool $managerRedactDescription,
        private bool $managerEmbargoWaived,
    ) {
    }

    public static function empty(): self
    {
        return DenariusLog::trace(__METHOD__, static fn (): self => new self(false, [], false, false));
    }

    public static function parse(?string $json): self
    {
        return DenariusLog::trace(__METHOD__, static function () use ($json): self {
            if ($json === null || $json === '') {
                return self::empty();
            }
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                return self::empty();
            }
            $hard = (bool) ($decoded['hard'] ?? false);
            $ids = $decoded['pattern_ids'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $patternIds = [];
            foreach ($ids as $id) {
                if (is_string($id) && $id !== '') {
                    $patternIds[] = $id;
                }
            }

            $managerRedact = (bool) ($decoded['manager_redact_description'] ?? false);
            $embargoWaived = (bool) ($decoded['manager_embargo_waived'] ?? false);

            return new self($hard, $patternIds, $managerRedact, $embargoWaived);
        });
    }

    public function isHard(): bool
    {
        return DenariusLog::trace(__METHOD__, fn (): bool => $this->hard);
    }

    /**
     * @return list<string>
     */
    public function patternIds(): array
    {
        return DenariusLog::trace(__METHOD__, fn (): array => $this->patternIds);
    }

    public function isManagerRedactDescription(): bool
    {
        return DenariusLog::trace(__METHOD__, fn (): bool => $this->managerRedactDescription);
    }

    public function isManagerEmbargoWaived(): bool
    {
        return DenariusLog::trace(__METHOD__, fn (): bool => $this->managerEmbargoWaived);
    }

    public function withManagerRedactDescription(bool $redact): self
    {
        return DenariusLog::trace(__METHOD__, fn (): self => new self(
            $this->hard,
            $this->patternIds,
            $redact,
            $this->managerEmbargoWaived,
        ));
    }

    public function withManagerEmbargoWaived(bool $waived): self
    {
        return DenariusLog::trace(__METHOD__, fn (): self => new self(
            $this->hard,
            $this->patternIds,
            $this->managerRedactDescription,
            $waived,
        ));
    }

    public function withHardPattern(string $patternId): self
    {
        return DenariusLog::trace(__METHOD__, function () use ($patternId): self {
            return $this->withPatternId($patternId)->withHard(true);
        });
    }

    public function withPatternId(string $patternId): self
    {
        return DenariusLog::trace(__METHOD__, function () use ($patternId): self {
            $ids = $this->patternIds;
            if (!in_array($patternId, $ids, true)) {
                $ids[] = $patternId;
            }

            return new self($this->hard, $ids, $this->managerRedactDescription, $this->managerEmbargoWaived);
        });
    }

    private function withHard(bool $hard): self
    {
        return DenariusLog::trace(__METHOD__, fn (): self => new self(
            $hard,
            $this->patternIds,
            $this->managerRedactDescription,
            $this->managerEmbargoWaived,
        ));
    }

    public function merge(self $other): self
    {
        return DenariusLog::trace(__METHOD__, function () use ($other): self {
            $ids = $this->patternIds;
            foreach ($other->patternIds as $id) {
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }

            return new self(
                $this->hard || $other->hard,
                $ids,
                $this->managerRedactDescription || $other->managerRedactDescription,
                $this->managerEmbargoWaived || $other->managerEmbargoWaived,
            );
        });
    }

    public function encode(): ?string
    {
        return DenariusLog::trace(__METHOD__, function (): ?string {
            if (
                !$this->hard
                && $this->patternIds === []
                && !$this->managerRedactDescription
                && !$this->managerEmbargoWaived
            ) {
                return null;
            }

            return json_encode([
                'hard' => $this->hard,
                'pattern_ids' => $this->patternIds,
                'manager_redact_description' => $this->managerRedactDescription,
                'manager_embargo_waived' => $this->managerEmbargoWaived,
            ], JSON_THROW_ON_ERROR);
        });
    }
}
