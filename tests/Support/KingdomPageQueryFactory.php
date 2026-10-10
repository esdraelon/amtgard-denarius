<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Support;

use Amtgard\Denarius\Domain\Statement\MonthStatementBuilder;
use Amtgard\Denarius\Domain\Statement\Publication\Pipeline\PublicationPipelineFactory;
use Amtgard\Denarius\Domain\Statement\Publication\StatementAbsenceClassifier;
use Amtgard\Denarius\Persistence\Repository\Account\AccountRepositoryInterface;
use Amtgard\Denarius\Persistence\Repository\Transaction\TransactionRepositoryInterface;
use Amtgard\Denarius\Service\Kingdom\KingdomPageQuery;
use Amtgard\Denarius\Service\Kingdom\KingdomPublicationLineSource;
use Amtgard\Denarius\Service\Kingdom\ManagerKingdomPageQuery;
use Amtgard\Denarius\Tests\Support\CategorizationArrange;

final class KingdomPageQueryFactory
{
    public static function publicRead(
        TransactionRepositoryInterface $transactions,
        AccountRepositoryInterface $accounts,
        ?MonthStatementBuilder $builder = null,
        ?\DateTimeImmutable $asOf = null,
    ): KingdomPageQuery {
        $source = new KingdomPublicationLineSource($transactions, $accounts);
        $pipelines = PublicationPipelineFactory::standard(
            CategorizationArrange::bundledCatalog(),
            CategoryCatalogFixture::asInterface(),
        );

        return new KingdomPageQuery(
            $source,
            $pipelines->forPublicRead(),
            $builder ?? MonthStatementBuilder::standard(),
            new StatementAbsenceClassifier(),
            $asOf ?? new \DateTimeImmutable('2026-10-01T12:00:00+00:00'),
        );
    }

    public static function managerReview(
        TransactionRepositoryInterface $transactions,
        AccountRepositoryInterface $accounts,
        ?MonthStatementBuilder $builder = null,
        ?\DateTimeImmutable $asOf = null,
    ): ManagerKingdomPageQuery {
        $source = new KingdomPublicationLineSource($transactions, $accounts);
        $pipelines = PublicationPipelineFactory::standard();

        return new ManagerKingdomPageQuery(
            $source,
            $pipelines->forManagerReview(),
            $builder ?? MonthStatementBuilder::standard(),
            $asOf ?? new \DateTimeImmutable('2026-10-01T12:00:00+00:00'),
        );
    }
}
