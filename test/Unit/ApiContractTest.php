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
        $id = Str::random(16);

        self::assertFalse($validatorFactory->make(
            ['game_id' => $id],
            (new DetailRequest($container))->rules(),
        )->fails());
        self::assertFalse($validatorFactory->make(
            ['game_id' => $id, 'scope' => 'me'],
            (new EventsRequest($container))->rules(),
        )->fails());
    }
}
