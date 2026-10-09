<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Tests\Unit;

use Amtgard\Denarius\Domain\Taxonomy\CategoryConfidence;
use Amtgard\Denarius\Domain\Taxonomy\CategorySource;
use Amtgard\Denarius\Domain\Taxonomy\TransactionFlow;
use Amtgard\Denarius\Domain\Taxonomy\CreditPositiveProviderAmountSign;
use Amtgard\Denarius\Domain\Taxonomy\PlaidProviderAmountSign;
use Amtgard\Denarius\Domain\Taxonomy\ProviderAmountSignRegistry;
use Amtgard\PHPUnit\AmtgardTestCase;

/**
 * Amount sign: per-provider strategies at categorizer ingress (providers unchanged until M-TAX-03).
 */
final class ProviderAmountSignTest extends AmtgardTestCase
{
    public function testPlaidPositiveOutflowBecomesNegativeCents(): void
    {
        $plaid = new PlaidProviderAmountSign();
        $this->assertSame(-1250, $plaid->signedCents('12.50'));
        $this->assertSame(TransactionFlow::Expense, TransactionFlow::defaultFromSignedCents($plaid->signedCents('12.50')));
    }

    public function testCreditPositiveProvidersKeepNegativeOutflows(): void
    {
        $creditPositive = new CreditPositiveProviderAmountSign();
        $this->assertSame(-1250, $creditPositive->signedCents('-12.50'));
        $this->assertSame(5000, $creditPositive->signedCents('50.00'));
    }

    public function testAutoAcceptThresholdConstant(): void
    {
        $this->assertSame(70, CategoryConfidence::AUTO_ACCEPT);
    }

    public function testCategorySourceAndFlowStoredParsing(): void
    {
        $this->assertSame(CategorySource::Fallback, CategorySource::fromStored('unknown'));
        $this->assertNull(TransactionFlow::fromStored('not-a-flow'));
        $this->assertSame(TransactionFlow::Income, TransactionFlow::defaultFromSignedCents(1));
    }

    public function testRegistrySelectsPlaidStrategy(): void
    {
        $registry = new ProviderAmountSignRegistry(
            ['plaid' => new PlaidProviderAmountSign()],
            new CreditPositiveProviderAmountSign(),
        );
        $this->assertSame(-100, $registry->forProvider('plaid')->signedCents('1.00'));
        $this->assertSame(-100, $registry->forProvider('teller')->signedCents('-1.00'));
    }
}
