<?php

declare(strict_types=1);

namespace App\Controller;

use App\Request\Mine\UpdateLanguageRequest;
use App\Request\Mine\UpdateNicknameRequest;
use Hyperf\HttpServer\Request;
use Psr\Http\Message\ResponseInterface as JsonResponse;

class MineController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        return $this->success($this->profile($this->user($r)));
    }

    public function updateNickname(UpdateNicknameRequest $r): JsonResponse
    {
        $this->user($r)->update(['nickname' => $r->nickname()]);

        return $this->success(['nickname' => $r->nickname()]);
    }

    public function updateLanguage(UpdateLanguageRequest $r): JsonResponse
    {
        $this->user($r)->update(['language' => $r->language()]);

        return $this->success(['language' => $r->language()]);
    }
}
