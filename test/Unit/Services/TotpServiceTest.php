<?php

use App\Service\TotpService;

it('matches the RFC 6238 SHA1 reference vector truncated to six digits', function () {
    expect((new TotpService)->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59))->toBe('287082');
});
it('generates 160 bit base32 secrets', function () {
    expect((new TotpService)->secret())->toMatch('/^[A-Z2-7]{32}$/');
});
it('rejects invalid codes', function () {
    expect((new TotpService)->counter((new TotpService)->secret(), 'abcdef'))->toBeNull();
});
