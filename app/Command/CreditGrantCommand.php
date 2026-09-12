<?php

namespace App\Command;

use App\Game\Reducer;
use App\Model\CreditRecord;
use App\Model\User;
use App\Service\GameService;
use Hyperf\Command\Command;
use Hyperf\DbConnection\Db as DB;
use Hyperf\Stringable\Str;
use LogicException;
use RuntimeException;
use Throwable;

use function App\Support\di;

final class CreditGrantCommand extends Command
{
    protected ?string $signature = 'credit:grant {account} {points} {--id=} {--description=}';

    protected string $description = 'Grant points idempotently to an account';

    public function handle(): int
    {
        $archive = di(GameService::class);
        $points = (string) ($this->input ?? throw new LogicException('Command input unavailable'))->getArgument('points');
        $id = (string) ($this->input ?? throw new LogicException('Command input unavailable'))->getOption('id');
        $description = ($this->input ?? throw new LogicException('Command input unavailable'))->getOption('description');
        if (! Str::isUuid($id) || ! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D',
            $points) || mb_strlen($description ?? '') > 255) {
            $this->error('Invalid id, points or description');

            return self::FAILURE;
        }
        [$whole, $fraction] = array_pad(explode('.', $points), 2, '');
        $amount = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
        if ($amount <= 0 || $amount > Reducer::MAX) {
            $this->error('Invalid points');

            return self::FAILURE;
        }
        try {
            $user = DB::transaction(function () use ($id, $description, $amount) {
                $u = User::query()->where('account',
                    strtolower(trim(($this->input ?? throw new LogicException('Command input unavailable'))->getArgument('account'))))->lockForUpdate()->firstOrFail();
                if (! $u instanceof User) {
                    throw new LogicException('Invalid user projection');
                }
                $old = CreditRecord::query()->where('uuid', $id)->first();
                if ($old) {
                    if ($old->user_id !== $u->id || $old->amount !== $amount || $old->description !== $description || ! $old->type->isGrant()) {
                        throw new RuntimeException('Grant id conflict');
                    }

                    return $u;
                }
                if ($u->credit_balance > Reducer::MAX - $amount) {
                    throw new RuntimeException('Balance overflow');
                }
                CreditRecord::query()->create([
                    'uuid' => $id, 'user_id' => $u->id, 'type' => 'GRANT', 'amount' => $amount,
                    'description' => $description,
                ]);
                $u->credit_balance += $amount;
                $u->save();

                return $u;
            });
        } catch (Throwable) {
            $this->error('Grant failed: account, conflict or database error');

            return self::FAILURE;
        }
        $status = 'pending';
        try {
            $archive->syncGrants($user->id);
            $status = 'synced';
        } catch (Throwable) {
        }
        $this->line(json_encode(['uuid' => $id, 'amount' => $amount, 'status' => $status], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
