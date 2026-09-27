<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controller\ApiController;
use App\Enum\GameEventTypeEnum;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Model\Game;
use App\Request\Mine\Games\DetailRequest;
use App\Request\Mine\Games\EventsRequest;
use DateTimeImmutable;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Context\ResponseContext;
use Hyperf\HttpMessage\Server\Response as Psr7Response;
use Hyperf\Stringable\Str;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

final class ApiContractTest extends TestCase
{
    public function test_enum_values_keep_their_database_names(): void
    {
        $previousResponse = ResponseContext::getOrNull();
        ResponseContext::set(new Psr7Response);
        $controller = new class extends ApiController
        {
            public function render(mixed $data): ResponseInterface
            {
                return $this->success($data);
            }
        };
        $game = new Game;
        $game->setRawAttributes([
            'status' => 'OVER',
            'network' => 'WE',
            'total' => 100,
            'profit' => 25,
        ], true);

        try {
            $response = $controller->render([
                'status' => GameStatusEnum::OVER,
                'network' => NetworkEnum::WE,
                'type' => GameEventTypeEnum::ACTION,
                'game' => $game,
            ]);
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            Context::set(ResponseInterface::class, $previousResponse);
        }

        self::assertSame([
            'status' => 'OVER',
            'network' => 'WE',
            'type' => 'ACTION',
            'game' => [
                'status' => 'OVER',
                'network' => 'WE',
                'total' => 100,
                'profit' => 25,
            ],
        ], $body['data']);
    }

    public function test_game_queries_accept_generated_ids_and_me_scope(): void
    {
        $container = ApplicationContext::getContainer();
        $validatorFactory = $container->get(ValidatorFactoryInterface::class);
        $id = Str::uuid()->toString();

        self::assertFalse($validatorFactory->make(
            ['game_id' => $id],
            (new DetailRequest($container))->rules(),
        )->fails());
        self::assertFalse($validatorFactory->make(
            ['game_id' => $id, 'scope' => 'me'],
            (new EventsRequest($container))->rules(),
        )->fails());
        foreach ([$validatorFactory->make(
            ['game_id' => 'aaaabbbbcccc0001'],
            (new DetailRequest($container))->rules(),
        ), $validatorFactory->make(
            ['game_id' => 'aaaabbbbcccc0001'],
            (new EventsRequest($container))->rules(),
        )] as $validator) {
            self::assertTrue($validator->fails());
        }
    }

    public function test_response_dates_are_rendered_in_shanghai_with_an_explicit_offset(): void
    {
        $previousResponse = ResponseContext::getOrNull();
        ResponseContext::set(new Psr7Response);
        $controller = new class extends ApiController
        {
            public function render(mixed $data): ResponseInterface
            {
                return $this->success($data);
            }
        };
        $game = new Game;
        $game->setRawAttributes(['created_at' => '2026-09-23 08:30:00.000000'], true);

        try {
            $response = $controller->render([
                'instant' => new DateTimeImmutable('2026-09-23T00:30:00+00:00'),
                'game' => $game,
            ]);
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            Context::set(ResponseInterface::class, $previousResponse);
        }

        self::assertSame('2026-09-23T08:30:00+08:00', $body['data']['instant']);
        self::assertSame('2026-09-23T08:30:00+08:00', $body['data']['game']['created_at']);
    }

    public function test_response_dates_follow_app_timestamp(): void
    {
        $previous = getenv('APP_TIMESTAMP');
        $previousResponse = ResponseContext::getOrNull();
        putenv('APP_TIMESTAMP=Asia/Tokyo');
        ResponseContext::set(new Psr7Response);
        $controller = new class extends ApiController
        {
            public function render(mixed $data): ResponseInterface
            {
                return $this->success($data);
            }
        };

        try {
            $response = $controller->render(new DateTimeImmutable('2026-09-23T00:30:00+00:00'));
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame('2026-09-23T09:30:00+09:00', $body['data']);
        } finally {
            Context::set(ResponseInterface::class, $previousResponse);
            $previous === false ? putenv('APP_TIMESTAMP') : putenv('APP_TIMESTAMP='.$previous);
        }
    }
}
