<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\CreditRecordTypeEnum;
use App\Exception\CreditException;
use App\Model\CreditRecord;
use App\Model\User;
use Hyperf\DbConnection\Db;
use Hyperf\Stringable\Str;

/** Database credit ledger; amounts are integer units (100 units = 1 point). */
final class CreditService
{
    /** Positive amounts add credit; negative amounts deduct it. Reuse a UUID for retries. */
    public function change(User $user, int $amount, ?string $description = null, ?string $uuid = null): CreditRecord
    {
        if ($amount === 0) {
            throw CreditException::creditAmountInvalid();
        }
        if ($description !== null && mb_strlen($description) > 255) {
            throw CreditException::creditDescriptionTooLong();
        }
        if ($uuid !== null && ! Str::isUuid($uuid)) {
            throw CreditException::creditUuidInvalid();
        }
        $uuid = strtolower($uuid ?? (string) Str::uuid());
        $type = $amount > 0 ? CreditRecordTypeEnum::GRANT : CreditRecordTypeEnum::CONSUME;

        return Db::transaction(function () use ($user, $amount, $description, $uuid, $type): CreditRecord {
            /** @var User $account */
            $account = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            /** @var CreditRecord|null $existing */
            $existing = CreditRecord::query()->where('uuid', $uuid)->first();
            if ($existing !== null) {
                if ($existing->user_id !== $account->id || $existing->amount !== $amount
                    || $existing->type !== $type || $existing->description !== $description) {
                    throw CreditException::creditUuidConflict();
                }

                return $existing;
            }
            if ($amount < 0 && $account->credit_balance < -$amount) {
                throw CreditException::creditBalanceInsufficient();
            }
            $account->credit_balance += $amount;
            $account->saveOrFail();

            $record = new CreditRecord;
            $record->fill([
                'uuid' => $uuid,
                'user_id' => $account->id,
                'type' => $type->name,
                'amount' => $amount,
                'balance' => $account->credit_balance,
                'description' => $description,
            ]);
            $record->saveOrFail();

            return $record;
        });
    }

    public function recharge(User $user, int $amount, ?string $description = null, ?string $uuid = null): CreditRecord
    {
        if ($amount <= 0) {
            throw CreditException::creditRechargeAmountInvalid();
        }

        return $this->change($user, $amount, $description, $uuid);
    }

    public function consume(User $user, int $amount, ?string $description = null, ?string $uuid = null): CreditRecord
    {
        if ($amount <= 0) {
            throw CreditException::creditConsumeAmountInvalid();
        }

        return $this->change($user, -$amount, $description, $uuid);
    }

    /** Query the persisted balance rather than the caller's potentially stale model. */
    public function balance(User $user): int
    {
        return (int) User::query()->whereKey($user->id)->firstOrFail()->credit_balance;
    }
}
