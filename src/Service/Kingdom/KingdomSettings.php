<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Service\Kingdom;

use Amtgard\Denarius\Domain\Kingdom\KingdomRecordRebuilder;
use Amtgard\Denarius\Domain\Statement\Publication\PublicationSettingsValidator;
use Amtgard\Denarius\Persistence\Repository\Kingdom\KingdomRepositoryInterface;
use Amtgard\Denarius\Domain\Statement\Presentation\DisplayMode;
use Amtgard\Denarius\Domain\Access\Visibility;
use Amtgard\Denarius\Persistence\Record\KingdomRecord;
use Amtgard\Denarius\Service\Month\MonthInvalidator;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

final class KingdomSettings
{
    public function __construct(
        private readonly KingdomRepositoryInterface $kingdoms,
        private readonly PublicationSettingsValidator $publicationSettings,
        private readonly MonthInvalidator $months,
    ) {
        $entered = DenariusLog::enter(__METHOD__);
    }

    public function update(
        KingdomRecord $kingdom,
        Visibility $visibility,
        DisplayMode $displayMode,
        int $embargoDays,
    ): KingdomRecord {
        return DenariusLog::trace(__METHOD__, function () use ($kingdom, $visibility, $displayMode, $embargoDays): KingdomRecord {
            $mode = $this->publicationSettings->canonicalDisplayMode($displayMode);

            $saved = $this->kingdoms->save(KingdomRecordRebuilder::from($kingdom)
                ->visibility($visibility->value)
                ->displayMode($mode->value)
                ->embargoDays($this->publicationSettings->clampEmbargoDays($embargoDays))
                ->build());
            $this->months->forget((int) $saved->getId());

            return $saved;
        });
    }
}
