<?php

return [
    'common' => [
        'business_error' => '操作失败，请稍后重试。',
        'invalid_input' => '参数无效。',
        'not_found' => '记录不存在。',
        'rate_limited' => '请求过于频繁，请稍后重试。',
        'server_error' => '服务暂不可用',
        'status_invalid' => '当前状态不允许此操作。',
    ],
    'auth' => [
        'failed' => '账号或密码错误。',
        'required' => '请先登录。',
        'expired' => '登录已失效。',
        'two_factor_required' => '请输入身份验证器验证码。',
        'two_factor_invalid' => '两步验证码无效或已使用。',
        'two_factor_already_enabled' => '两步验证已启用。',
        'setup_expired' => '绑定信息已过期。',
    ],
    'game' => [
        'already_exists' => '游戏已存在。',
        'not_found' => '未找到牌局。',
        'event_invalid' => '事件格式或内容无效。',
        'event_type_invalid' => '不支持的事件类型。',
        'card_invalid' => '牌面无效。',
        'action_in_progress' => '正在计算行动建议，请稍后重试。',
        'action_amount_invalid' => '行动金额无效。',
        'hero_not_found' => '未找到本局 Hero 玩家。',
        'big_blind_not_found' => '未找到大盲位玩家。',
        'player_not_found' => '未找到指定玩家。',
        'players_empty' => '牌局没有玩家。',
    ],
    'provider' => [
        'unavailable' => '决策服务暂不可用。',
        'failed' => '决策服务处理失败。',
    ],
];
