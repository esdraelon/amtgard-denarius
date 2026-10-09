<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Domain\Statement\Publication\Ingest;

use Amtgard\Denarius\Persistence\Record\TransactionRecord;
use Amtgard\Denarius\Utilities\Log\DenariusLog;

/** Strategy: keyword and descriptor HARD rules on ingest text fields. */
final class VerificationKeywordHardMatcher
{
    public function matches(TransactionRecord $transaction): bool
    {
        $method = __METHOD__;

        return DenariusLog::trace($method, function () use ($method, $transaction): bool {
            $haystack = strtolower($transaction->getDescription() . ' ' . $transaction->getCounterparty());
            if ($this->containsVerificationToken($haystack)) {
                DenariusLog::debugBranch('publication_hard_keyword', $method, [
                    'teller_transaction_id' => $transaction->getTellerTransactionId(),
                ]);

                return true;
            }

            return false;
        });
    }

    private function containsVerificationToken(string $haystack): bool
    {
        return DenariusLog::trace(__METHOD__, function () use ($haystack): bool {
            if (str_contains($haystack, 'acctverify') || str_contains($haystack, 'microdep')) {
                return true;
            }
            if (preg_match('/acctverify.*#\s*[a-z]{3}\b/u', $haystack) === 1) {
                return true;
            }
            if (preg_match('/\bsm[a-z0-9]{4}\b/u', $haystack) === 1) {
                return true;
            }
            if (str_contains($haystack, 'verify') && $this->verifyBrandContext($haystack)) {
                return true;
            }

            return false;
        });
    }

    private function verifyBrandContext(string $haystack): bool
    {
        return DenariusLog::trace(__METHOD__, static function () use ($haystack): bool {
            foreach (['stripe', 'plaid', 'teller', 'ach'] as $brand) {
                if (str_contains($haystack, $brand)) {
                    return true;
                }
            }

            return false;
        });
    }
}
