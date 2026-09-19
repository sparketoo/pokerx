<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Controller\ApiController;
use App\Request\Mine\GameConfig\IndexRequest;
use App\Request\Mine\GameConfig\SaveRequest;
use App\Service\UserGameConfigService;
use Psr\Http\Message\ResponseInterface as JsonResponse;

class GameConfigController extends ApiController
{
    public function __construct(private readonly UserGameConfigService $configs) {}

    public function index(IndexRequest $request): JsonResponse
    {
        $items = [];
        foreach ($this->configs->all($this->user($request), $request->network()) as $key => $value) {
            $items[] = ['key' => $key, 'value' => $value];
        }

        return $this->success(['items' => $items]);
    }

    public function save(SaveRequest $request): JsonResponse
    {
        $this->configs->save($this->user($request), $request->network(), $request->filters()['items']);

        return $this->index($request);
    }
}
